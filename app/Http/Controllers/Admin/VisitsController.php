<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Prospect;
use App\Services\Crm\ProspectScore;
use App\Services\Crm\RoutePlanner;
use App\Services\Crm\TeamStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «زياراتي»: the field rep's phone page (crm.visits). Today's booked visits and the suggested walk-ins on his way,
 * a check-in from the phone's location at the place (how far from its pin is kept), and the visit report: what came
 * out of it, the data collected (the place's tables, branches, delivery, current system, who decides) and photos.
 */
class VisitsController extends Controller
{
    public function index(Request $request, RoutePlanner $planner, TeamStats $stats): View
    {
        $admin = $request->user('admin');
        $day = $planner->day($admin, local_day_start());

        return view('admin.crm.visits', [
            'day' => $day,
            'upcoming' => Appointment::query()->with('prospect')->where('field_id', $admin->id)->where('status', 'scheduled')
                ->where('scheduled_at', '>=', local_day_start(local_at()->addDay()->toDateString()))->orderBy('scheduled_at')->limit(20)->get(),
            'late' => Appointment::query()->with(['prospect', 'setter'])->where('field_id', $admin->id)->where('status', 'scheduled')
                ->where('scheduled_at', '<', local_day_start())->orderBy('scheduled_at')->get(),
            'card' => $stats->card($admin, local_at()->format('Y-m')),
            'admin' => $admin,
        ]);
    }

    /** The rep is at the place: where his phone is, and how far that is from the place's pin (or it becomes the pin). */
    public function checkIn(Request $request, Appointment $appointment, ProspectScore $score): RedirectResponse
    {
        $this->own($request, $appointment);
        $data = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        $prospect = $appointment->prospect;
        $distance = $prospect->hasLocation() ? (int) round(Prospect::distance((float) $data['lat'], (float) $data['lng'], $prospect->lat, $prospect->lng)) : null;
        $appointment->update(['checked_in_at' => now(), 'check_in_lat' => $data['lat'], 'check_in_lng' => $data['lng'], 'check_in_distance' => $distance]);
        if (! $prospect->hasLocation()) {
            $prospect->update(['lat' => $data['lat'], 'lng' => $data['lng']]);
            $score->rescore([$prospect->id]);
        }

        return back()->with('ok', $distance !== null && $distance > Appointment::NEAR_METRES
            ? __('Checked in, :m metres from the place pin.', ['m' => $distance])
            : __('Checked in at the place.'));
    }

    /** The visit report. */
    public function report(Request $request, Appointment $appointment): RedirectResponse
    {
        $admin = $request->user('admin');
        $this->own($request, $appointment);
        abort_unless($appointment->status === 'scheduled', 422);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(Appointment::OUTCOMES))],
            'answers' => 'nullable|array',
            'answers.*' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:3000',
            'next_step' => 'nullable|string|max:255',
            'next_visit_at' => 'nullable|date',
            'photos' => 'nullable|array|max:4',
            'photos.*' => 'image|max:8192',
        ]);
        $answers = array_filter(array_intersect_key((array) ($data['answers'] ?? []), Appointment::QUESTIONS), fn ($v) => trim((string) $v) !== '');
        $photos = [];
        foreach ($request->file('photos', []) as $file) {
            $photos[] = $file->store("crm-visits/{$appointment->id}", 'local');
        }
        $closed = $data['outcome'] === 'closed';
        $appointment->update([
            'status' => $closed ? 'no_show' : 'done', 'outcome' => $data['outcome'], 'answers' => $answers ?: null,
            'photos' => $photos ?: null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: $appointment->notes,
            'next_step' => trim((string) ($data['next_step'] ?? '')), 'reported_at' => now(),
            'checked_in_at' => $appointment->checked_in_at,
        ]);

        $prospect = $appointment->prospect;
        $prospect->events()->create(['type' => 'visit', 'admin_id' => $admin->id, 'outcome' => $data['outcome'],
            'notes' => trim(($data['notes'] ?? '').' '.($data['next_step'] ?? '')) ?: null, 'meta' => ['appointment' => $appointment->id, 'answers' => $answers ?: null]]);
        // what he learnt fills the place's card
        $prospect->fill(array_filter([
            'branches' => is_numeric($answers['branches'] ?? null) ? (int) $answers['branches'] : null,
            'has_delivery' => isset($answers['delivery']) ? in_array($answers['delivery'], ['1', 'yes', 'نعم', 'أيوه'], true) : null,
            'current_system' => isset($answers['current_system']) ? mb_substr($answers['current_system'], 0, 100) : null,
            'owner_name' => $prospect->owner_name === '' && isset($answers['decision_maker']) ? mb_substr($answers['decision_maker'], 0, 100) : null,
        ], fn ($v) => $v !== null));
        if (! in_array($prospect->stage, ['trial', 'won'], true)) {
            $prospect->stage = match ($data['outcome']) {
                'not_interested' => 'lost',
                'closed' => $prospect->appointments()->whereKeyNot($appointment->id)->where('status', 'scheduled')->exists() ? 'appointment' : 'contacted',
                default => 'visited',
            };
            $prospect->lost_reason = $data['outcome'] === 'not_interested' ? 'no_need' : null;
        }
        $prospect->field_id ??= $admin->id;
        $prospect->last_contact_at = now();
        $prospect->save();
        if (! empty($data['next_visit_at'])) {
            $prospect->appointments()->create(['set_by' => $admin->id, 'field_id' => $admin->id, 'scheduled_at' => local_input($data['next_visit_at']), 'address' => $appointment->address]);
            if ($prospect->stage !== 'trial' && $prospect->stage !== 'won' && $prospect->stage !== 'lost') {
                $prospect->update(['stage' => 'appointment']);
            }
        }

        return redirect()->route('admin.crm.visits')->with('ok', __('Visit report saved.'));
    }

    /** A visit without a booking (a suggested place on the way, or one he just found): starts a visit now. */
    public function walkIn(Request $request, Prospect $prospect): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($prospect->isVisibleTo($admin), 404);
        $appointment = $prospect->appointments()->where('field_id', $admin->id)->where('status', 'scheduled')
            ->where('scheduled_at', '>=', local_day_start())->first()
            ?? $prospect->appointments()->create(['set_by' => null, 'field_id' => $admin->id, 'scheduled_at' => now(), 'address' => $prospect->address]);
        $prospect->update(['field_id' => $prospect->field_id ?? $admin->id]);

        return redirect()->to(route('admin.crm.visits').'#visit-'.$appointment->id);
    }

    /** A photo of a visit report, for whoever may see the visit. */
    public function photo(Request $request, Appointment $appointment, int $index): StreamedResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin->allows('crm.manage') || $appointment->field_id === $admin->id, 404);
        $path = $appointment->photos[$index] ?? null;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function own(Request $request, Appointment $appointment): void
    {
        abort_unless($appointment->field_id === $request->user('admin')->id, 404);
    }
}
