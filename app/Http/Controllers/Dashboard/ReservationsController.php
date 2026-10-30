<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Reservation;
use App\Services\Reservations\ReservationException;
use App\Services\Reservations\ReservationService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Reservations: the day view (by time), the bookings still to confirm, booking by hand (phone, walk-in) and the
 * booking rules. Staff tied to a branch only see and book that branch. Pending bookings also show on the order board.
 */
class ReservationsController extends Controller
{
    public const SLOT_CHOICES = [15, 30, 45, 60];

    public function index(Request $request, ReservationService $service): View
    {
        $user = $request->user();
        $branches = Branch::query()->where('is_active', true)->when($user->branch_id, fn ($q) => $q->whereKey($user->branch_id))
            ->orderBy('sort_order')->get();
        $branch = $branches->firstWhere('id', (int) $request->query('branch')) ?? $branches->first();
        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $request->query('date'), local_timezone())->startOfDay();
        } catch (\Throwable) {
            $date = local_at()->startOfDay();
        }
        $scoped = fn () => Reservation::query()->when($branch, fn ($q) => $q->where('branch_id', $branch->id));

        $day = $scoped()->whereBetween('starts_at', [local_day_start($date), local_day_end($date)])->with('branch')->orderBy('starts_at')->get();

        return view('dashboard.reservations', [
            'date' => $date, 'branch' => $branch, 'branches' => $branches,
            'day' => $day,
            'covers' => $day->whereIn('status', Reservation::ACTIVE)->sum('party_size'),
            'pending' => $scoped()->where('status', 'pending')->where('starts_at', '>=', now()->subHours(2))->with('branch')->orderBy('starts_at')->limit(50)->get(),
            'upcoming' => $scoped()->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>', local_day_end($date))
                ->where('starts_at', '<=', local_day_end(local_at()->addDays(14)))->with('branch')->orderBy('starts_at')->limit(50)->get(),
            'slots' => $branch ? $service->slots($branch, $date, 1, true) : [],
            'config' => ReservationService::config(),
            'settings' => Settings::all(),
            'seats' => ReservationService::seatsMap(),
            'hours' => ReservationService::customHours(),
            'allBranches' => $user->branch_id ? $branches : Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'guestUrl' => tenant()->url('reserve'),
            'canSettings' => ! $user->branch_id,
        ]);
    }

    /** A booking taken by phone or at the door: confirmed at once, the seats rule still applies. */
    public function store(Request $request, ReservationService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'party_size' => 'required|integer|min:1|max:100',
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:500',
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $branch->id, 404);
        try {
            $service->book($data, $branch, $request->user());
        } catch (ReservationException $e) {
            return back()->withInput()->withErrors(['time' => $e->getMessage()]);
        }

        return redirect()->route('dash.reservations', ['date' => $data['date'], 'branch' => $branch->id])->with('ok', __('Reservation saved.'));
    }

    public function transition(Request $request, Reservation $reservation, ReservationService $service): JsonResponse|RedirectResponse
    {
        abort_if($request->user()->branch_id && $request->user()->branch_id !== $reservation->branch_id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Reservation::STATUSES))]]);
        try {
            $service->transition($reservation, $data['status']);
        } catch (ReservationException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['status' => $e->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'status' => $reservation->status])
            : back()->with('ok', __('Reservation updated.'));
    }

    /** The booking rules (restaurant-wide; staff tied to one branch can't change them). */
    public function settings(Request $request): RedirectResponse
    {
        abort_if((bool) $request->user()->branch_id, 403);
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'slot_minutes' => ['required', Rule::in(self::SLOT_CHOICES)],
            'duration_minutes' => 'required|integer|min:30|max:360',
            'max_party' => 'required|integer|min:1|max:100',
            'lead_hours' => 'required|integer|min:0|max:72',
            'horizon_days' => 'required|integer|min:1|max:180',
            'hours_mode' => ['required', Rule::in(['branch', 'custom'])],
            'hours' => 'array',
            'hours.*.open' => 'nullable|date_format:H:i',
            'hours.*.close' => 'nullable|date_format:H:i',
            'seats' => 'array',
            'seats.*' => 'nullable|integer|min:0|max:2000',
        ]);
        $seats = [];
        foreach ($data['seats'] ?? [] as $branchId => $n) {
            if ($n !== null && Branch::query()->where('tenant_id', $tenant)->whereKey((int) $branchId)->exists()) {
                $seats[(int) $branchId] = (int) $n;
            }
        }
        $hours = [];
        if ($data['hours_mode'] === 'custom') {
            foreach (range(0, 6) as $d) {
                $open = $data['hours'][$d]['open'] ?? null;
                $close = $data['hours'][$d]['close'] ?? null;
                $hours[$d] = $open && $close ? ['open' => $open, 'close' => $close] : ['open' => '', 'close' => ''];
            }
        }

        Settings::put([
            'reservations_enabled' => $request->boolean('reservations_enabled') ? '1' : '0',
            'reservation_auto_confirm' => $request->boolean('auto_confirm') ? '1' : '0',
            'reservation_slot_minutes' => (string) $data['slot_minutes'],
            'reservation_duration_minutes' => (string) $data['duration_minutes'],
            'reservation_max_party' => (string) $data['max_party'],
            'reservation_lead_hours' => (string) $data['lead_hours'],
            'reservation_horizon_days' => (string) $data['horizon_days'],
            'reservation_hours' => $hours ? json_encode($hours) : '',
            'reservation_seats' => $seats ? json_encode($seats) : '',
        ]);

        return back()->with('ok', __('Settings saved.'));
    }
}
