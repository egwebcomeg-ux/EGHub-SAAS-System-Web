<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Services\Crm\CallScript;
use App\Services\Crm\ProspectScore;
use App\Support\SalesPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «الأماكن»: the places the sales team works before they have an EgHub account. crm.view lists them (a field rep only
 * the ones assigned to him) and opens a place; crm.manage logs calls, changes stages, assigns and books visits for
 * the field rep; the field rep (crm.visits) adds the places he finds and fixes their data. Imports are
 * ProspectImportController, visits VisitsController.
 */
class ProspectsController extends Controller
{
    /** @return array{q: string, stage: string, category: string, city: string, source: string, mine: string, sort: string, view: string} */
    public static function filters(Request $request): array
    {
        $pick = fn (string $key, array $allowed): string => in_array($value = (string) $request->query($key, ''), $allowed, true) ? $value : '';

        return [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'stage' => $pick('stage', ['open', ...array_keys(Prospect::STAGES)]),
            'category' => $pick('category', array_keys(Prospect::CATEGORIES)),
            'city' => mb_substr(trim((string) $request->query('city', '')), 0, 60),
            'source' => $pick('source', array_keys(Prospect::SOURCES)),
            'mine' => $pick('mine', ['1']),
            'sort' => $pick('sort', ['score', 'newest', 'follow_up', 'name']) ?: 'score',
            'view' => $pick('view', ['map']),
        ];
    }

    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        $f = self::filters($request);
        $query = $this->listing($f, $admin);

        return view('admin.crm.prospects.index', [
            'prospects' => $f['view'] === 'map' ? null : (clone $query)->with(['caller', 'field'])->paginate(30)->withQueryString(),
            'pins' => $f['view'] === 'map' ? (clone $query)->whereNotNull('lat')->whereNotNull('lng')->limit(1500)
                ->get(['id', 'name', 'category', 'stage', 'lat', 'lng', 'score'])->map(fn (Prospect $p) => [
                    'id' => $p->id, 'name' => $p->name, 'lat' => $p->lat, 'lng' => $p->lng, 'score' => $p->score,
                    'stage' => $p->stageLabel(), 'emoji' => $p->categoryEmoji(), 'url' => route('admin.crm.prospects.show', $p),
                ])->values() : collect(),
            'filters' => $f,
            'cities' => Prospect::query()->visibleTo($admin)->where('city', '!=', '')->distinct()->orderBy('city')->pluck('city'),
            'recommended' => $admin->allows('crm.manage') ? $this->recommendedCalls($admin) : collect(),
            'team' => $this->team(),
            'counts' => [
                'new' => Prospect::query()->visibleTo($admin)->where('stage', 'new')->count(),
                'appointment' => Prospect::query()->visibleTo($admin)->where('stage', 'appointment')->count(),
                'trial' => Prospect::query()->visibleTo($admin)->where('stage', 'trial')->count(),
                'won' => Prospect::query()->visibleTo($admin)->where('stage', 'won')->count(),
            ],
            'admin' => $admin,
        ]);
    }

    public function show(Request $request, Prospect $prospect, CallScript $script): View
    {
        $admin = $request->user('admin');
        abort_unless($prospect->isVisibleTo($admin), 404);
        $sections = $script->sections();
        $context = [
            '{owner}' => Str::before(trim($prospect->owner_name), ' '), '{restaurant}' => $prospect->name,
            '{caller}' => Str::before(trim($admin->name), ' '),
        ];
        $phone = (string) ($prospect->phone ?? '');
        $whatsappText = CallScript::fill((string) ($sections['whatsapp']->first()?->body ?? ''), $context);

        return view('admin.crm.prospects.show', [
            'prospect' => $prospect->load(['caller', 'field', 'closer', 'assist', 'tenant']),
            'sections' => $sections,
            'context' => $context,
            'events' => $prospect->events()->with('admin')->latest('id')->limit(60)->get(),
            'appointments' => $prospect->appointments()->with(['field', 'setter'])->latest('scheduled_at')->get(),
            'team' => $this->team(),
            'telUrl' => $phone !== '' ? 'tel:'.$phone : null,
            'whatsappUrl' => $phone !== '' ? 'https://wa.me/'.whatsapp_number($phone).($whatsappText !== '' ? '?text='.rawurlencode($whatsappText) : '') : null,
            'admin' => $admin,
        ]);
    }

    /** A place typed by hand (the caller) or found on the street (the field rep, who then owns it). */
    public function store(Request $request, ProspectScore $score): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin->allows('crm.manage') || $admin->allows('crm.visits'), 403);
        $data = $this->validated($request);
        $phone = $data['phone'] ? normalize_phone($data['phone']) : null;
        if ($phone && $existing = Prospect::query()->where('phone', $phone)->orWhere('phone2', $phone)->first()) {
            return $existing->isVisibleTo($admin)
                ? redirect()->route('admin.crm.prospects.show', $existing)->with('ok', __('This place is already in the system.'))
                : back()->withInput()->withErrors(['phone' => __('This number belongs to a place another team member follows.')]);
        }
        $field = ! $admin->allows('crm.manage');
        $prospect = Prospect::query()->create([...$this->columns($data), 'phone' => $phone,
            'source' => $field ? 'field' : ($data['source'] ?? 'manual'), 'created_by' => $admin->id,
            'field_id' => $field ? $admin->id : null, 'caller_id' => $field ? null : $admin->id]);
        $prospect->events()->create(['type' => 'import', 'admin_id' => $admin->id, 'meta' => ['source' => $prospect->source]]);
        $score->rescore([$prospect->id]);

        return redirect()->route('admin.crm.prospects.show', $prospect)->with('ok', __('Place added.'));
    }

    /** The place's data (name, kind, address, pin, phones, owner, branches, system, delivery, notes). */
    public function update(Request $request, Prospect $prospect, ProspectScore $score): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($prospect->isVisibleTo($admin) && ($admin->allows('crm.manage') || $admin->allows('crm.visits')), 404);
        $data = $this->validated($request);
        $phone = $data['phone'] ? normalize_phone($data['phone']) : null;
        if ($phone && Prospect::query()->whereKeyNot($prospect->id)->where(fn ($q) => $q->where('phone', $phone)->orWhere('phone2', $phone))->exists()) {
            return back()->withInput()->withErrors(['phone' => __('Another place already has this number.')]);
        }
        $prospect->update([...$this->columns($data), 'phone' => $phone]);
        $score->rescore([$prospect->id]);

        return back()->with('ok', __('Saved.'));
    }

    /** A call or a WhatsApp attempt, with the next follow-up and an optional stage (contacted, lost). */
    public function call(Request $request, Prospect $prospect): RedirectResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'channel' => ['required', Rule::in(['call', 'whatsapp'])],
            'outcome' => ['required', Rule::in(array_keys(Lead::OUTCOMES))],
            'notes' => 'nullable|string|max:2000',
            'next_follow_up_at' => 'nullable|date',
            'stage' => ['nullable', Rule::in(Prospect::MANUAL_STAGES)],
            'lost_reason' => ['nullable', 'required_if:stage,lost', Rule::in(array_keys(Lead::LOST_REASONS))],
        ]);
        $followUp = ! empty($data['next_follow_up_at']) ? local_input($data['next_follow_up_at']) : null;
        $stage = $data['stage'] ?? null;
        if ($stage === null && $prospect->stage === 'new' && in_array($data['outcome'], ['answered', 'sent'], true)) {
            $stage = 'contacted';
        }
        if (in_array($prospect->stage, ['trial', 'won'], true)) {
            $stage = null; // what happened later (a sign-up, a payment) is not undone by a call
        }
        $prospect->events()->create([
            'type' => $data['channel'], 'admin_id' => $admin->id, 'outcome' => $data['outcome'],
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'meta' => array_filter(['from' => $stage && $stage !== $prospect->stage ? $prospect->stage : null, 'to' => $stage !== $prospect->stage ? $stage : null,
                'follow_up' => $followUp?->toIso8601String()]) ?: null,
        ]);
        $prospect->update(array_filter([
            'stage' => $stage, 'lost_reason' => $stage === 'lost' ? $data['lost_reason'] : null,
        ], fn ($v) => $v !== null) + [
            'last_contact_at' => now(),
            'next_follow_up_at' => $stage === 'lost' ? null : $followUp,
            'caller_id' => $prospect->caller_id ?? $admin->id,
        ]);

        return redirect()->route('admin.crm.prospects.show', $prospect)->with('ok', __('Call saved.'));
    }

    /** Stage by hand, the caller and the field rep of the place. */
    public function assign(Request $request, Prospect $prospect): RedirectResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'stage' => ['nullable', Rule::in(Prospect::MANUAL_STAGES)],
            'lost_reason' => ['nullable', 'required_if:stage,lost', Rule::in(array_keys(Lead::LOST_REASONS))],
            'caller_id' => ['nullable', Rule::in($this->team()->where('seat', 'caller')->pluck('admin.id')->merge([$admin->id])->all())],
            'field_id' => ['nullable', Rule::in($this->team()->where('seat', 'field')->pluck('admin.id')->all())],
        ]);
        $changes = [
            'caller_id' => isset($data['caller_id']) ? (int) $data['caller_id'] : null,
            'field_id' => isset($data['field_id']) ? (int) $data['field_id'] : null,
        ];
        if (! empty($data['stage']) && $data['stage'] !== $prospect->stage && ! in_array($prospect->stage, ['trial', 'won'], true)) {
            $prospect->events()->create(['type' => 'stage', 'admin_id' => $admin->id,
                'meta' => ['from' => $prospect->stage, 'to' => $data['stage'], 'reason' => $data['lost_reason'] ?? null]]);
            $changes += ['stage' => $data['stage'], 'lost_reason' => $data['stage'] === 'lost' ? $data['lost_reason'] : null,
                'next_follow_up_at' => $data['stage'] === 'lost' ? null : $prospect->next_follow_up_at];
        }
        if ($changes['caller_id'] !== $prospect->caller_id || $changes['field_id'] !== $prospect->field_id) {
            $prospect->events()->create(['type' => 'assign', 'admin_id' => $admin->id, 'meta' => [
                'caller' => PlatformAdmin::query()->find($changes['caller_id'])?->name, 'field' => PlatformAdmin::query()->find($changes['field_id'])?->name]]);
        }
        $prospect->update($changes);

        return back()->with('ok', __('Saved.'));
    }

    /** Gives the ticked places to a caller and/or a field rep. */
    public function bulkAssign(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'ids' => 'required|array|max:500',
            'ids.*' => 'integer',
            'caller_id' => ['nullable', Rule::in($this->team()->where('seat', 'caller')->pluck('admin.id')->merge([$admin->id])->all())],
            'field_id' => ['nullable', Rule::in($this->team()->where('seat', 'field')->pluck('admin.id')->all())],
        ]);
        $changes = array_filter(['caller_id' => $data['caller_id'] ?? null, 'field_id' => $data['field_id'] ?? null]);
        if ($changes === []) {
            return back()->withErrors(['ids' => __('Pick who gets the places.')]);
        }
        $count = Prospect::query()->whereIn('id', $data['ids'])->update($changes);

        return back()->with('ok', __(':n places assigned.', ['n' => $count]));
    }

    /** The caller books a visit for the field rep. */
    public function book(Request $request, Prospect $prospect): RedirectResponse
    {
        $admin = $request->user('admin');
        $data = $request->validate([
            'field_id' => ['required', Rule::in($this->team()->where('seat', 'field')->pluck('admin.id')->all())],
            'scheduled_at' => 'required|date',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ], ['field_id.in' => __('Pick the field rep.')]);
        $at = local_input($data['scheduled_at']);
        if ($at->lt(now()->subHour())) {
            return back()->withInput()->withErrors(['scheduled_at' => __('Pick a time that has not passed.')]);
        }
        $appointment = $prospect->appointments()->create([
            'set_by' => $admin->id, 'field_id' => (int) $data['field_id'], 'scheduled_at' => $at,
            'address' => trim((string) ($data['address'] ?? '')) ?: $prospect->address, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ]);
        $prospect->events()->create(['type' => 'appointment', 'admin_id' => $admin->id, 'notes' => $appointment->notes,
            'meta' => ['at' => $at->toIso8601String(), 'field' => $appointment->field?->name]]);
        $prospect->update([
            'stage' => in_array($prospect->stage, ['trial', 'won'], true) ? $prospect->stage : 'appointment',
            'field_id' => $appointment->field_id, 'caller_id' => $prospect->caller_id ?? $admin->id, 'next_follow_up_at' => null,
        ]);

        return back()->with('ok', __('Visit booked for :at.', ['at' => local_time($at, 'd/m h:i A')]));
    }

    /** A booked visit cancelled or moved (whoever booked it, the field rep or a manager). */
    public function appointment(Request $request, Appointment $appointment): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin->allows('crm.manage') || $appointment->field_id === $admin->id, 404);
        abort_unless($appointment->status === 'scheduled', 422);
        $data = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'no_show', 'move'])],
            'scheduled_at' => ['nullable', 'required_if:action,move', 'date'],
        ]);
        $prospect = $appointment->prospect;
        if ($data['action'] === 'move') {
            $appointment->update(['scheduled_at' => local_input($data['scheduled_at'])]);
            $prospect->events()->create(['type' => 'appointment', 'admin_id' => $admin->id, 'meta' => ['moved' => $appointment->scheduled_at->toIso8601String()]]);

            return back()->with('ok', __('Visit moved.'));
        }
        $appointment->update(['status' => $data['action'] === 'cancel' ? 'cancelled' : 'no_show']);
        $prospect->events()->create(['type' => 'visit', 'admin_id' => $admin->id, 'outcome' => $data['action'] === 'cancel' ? null : 'closed',
            'notes' => $appointment->statusLabel()]);
        if ($prospect->stage === 'appointment' && ! $prospect->appointments()->where('status', 'scheduled')->exists()) {
            $prospect->update(['stage' => 'contacted']);
        }

        return back()->with('ok', __('Saved.'));
    }

    /**
     * The places a caller should phone next: open, with a number, nobody called yet (or a follow-up due), hers or
     * nobody's, best «ترشيح السيستم» first.
     *
     * @return Collection<int, Prospect>
     */
    private function recommendedCalls(PlatformAdmin $admin): Collection
    {
        return Prospect::query()->open()->whereNotNull('phone')
            ->where(fn (Builder $w) => $w->whereNull('caller_id')->orWhere('caller_id', $admin->id))
            ->where(fn (Builder $w) => $w->whereNull('last_contact_at')->orWhere('next_follow_up_at', '<', local_day_start(local_at()->addDay()->toDateString())))
            ->where('stage', '!=', 'appointment')
            ->orderByRaw('next_follow_up_at is null')->orderBy('next_follow_up_at')->orderByDesc('score')->limit(10)->get();
    }

    /**
     * @param  array<string, string>  $f
     * @return Builder<Prospect>
     */
    private function listing(array $f, PlatformAdmin $admin): Builder
    {
        $query = Prospect::query()->visibleTo($admin);
        if ($f['q'] !== '') {
            $like = "%{$f['q']}%";
            $phone = normalize_phone($f['q']);
            $query->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('owner_name', 'like', $like)
                ->orWhere('area', 'like', $like)->orWhere('address', 'like', $like)
                ->when($phone, fn ($m) => $m->orWhere('phone', $phone)->orWhere('phone2', $phone)));
        }
        if ($f['stage'] === 'open') {
            $query->open();
        } elseif ($f['stage'] !== '') {
            $query->where('stage', $f['stage']);
        }
        foreach (['category', 'city', 'source'] as $column) {
            if ($f[$column] !== '') {
                $query->where($column, $f[$column]);
            }
        }
        if ($f['mine'] === '1') {
            $query->where(fn (Builder $w) => $w->where('caller_id', $admin->id)->orWhere('field_id', $admin->id));
        }

        return match ($f['sort']) {
            'newest' => $query->latest('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            'follow_up' => $query->orderByRaw('next_follow_up_at is null')->orderBy('next_follow_up_at')->orderByDesc('score'),
            default => $query->orderByDesc('score')->orderBy('id'),
        };
    }

    /** The sales seats: [admin, seat]. @return Collection<int, array{admin: PlatformAdmin, seat: string}> */
    private function team(): Collection
    {
        return PlatformAdmin::query()->active()->orderBy('name')->get()
            ->map(fn (PlatformAdmin $a) => ['admin' => $a, 'seat' => SalesPlan::seat($a) ?? ($a->allows('crm.manage') ? 'caller' : null)])
            ->filter(fn (array $m) => $m['seat'] !== null)->values();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'category' => ['required', Rule::in(array_keys(Prospect::CATEGORIES))],
            'governorate' => 'nullable|string|max:60',
            'city' => 'nullable|string|max:60',
            'area' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (trim((string) $value) !== '' && normalize_phone((string) $value) === null) {
                    $fail(__('Enter a valid Egyptian phone number.'));
                }
            }],
            'phone2' => 'nullable|string|max:20',
            'owner_name' => 'nullable|string|max:100',
            'branches' => 'nullable|integer|min:1|max:500',
            'current_system' => 'nullable|string|max:100',
            'has_delivery' => 'nullable|in:0,1',
            'source' => ['nullable', Rule::in(array_keys(Prospect::SOURCES))],
            'notes' => 'nullable|string|max:5000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $text = fn (string $key) => trim((string) ($data[$key] ?? ''));

        return [
            'name' => $text('name'), 'category' => $data['category'], 'governorate' => $text('governorate') ?: 'الغربية',
            'city' => $text('city'), 'area' => $text('area'), 'address' => $text('address'),
            'lat' => isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null,
            'phone2' => $text('phone2') !== '' ? normalize_phone($text('phone2')) : null,
            'owner_name' => $text('owner_name'), 'branches' => isset($data['branches']) ? (int) $data['branches'] : null,
            'current_system' => $text('current_system'),
            'has_delivery' => isset($data['has_delivery']) && $data['has_delivery'] !== '' ? (bool) $data['has_delivery'] : null,
            'notes' => $text('notes') ?: null,
        ];
    }
}
