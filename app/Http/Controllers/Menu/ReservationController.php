<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Reservation;
use App\Services\Entitlements;
use App\Services\Menu\MenuCatalog;
use App\Services\Reservations\ReservationException;
use App\Services\Reservations\ReservationService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Booking a table from the menu link: /reserve (branch, day and party size pick the free slots; the page reloads
 * when they change, so it works without JavaScript), then /reserve/{token} to follow or cancel the booking.
 */
class ReservationController extends Controller
{
    public function create(Request $request, ReservationService $reservations): View
    {
        abort_unless(ReservationService::enabled(), 404);
        $c = ReservationService::config();
        $branches = Branch::query()->where('is_active', true)->orderBy('sort_order')->get();
        $branch = $branches->firstWhere('id', (int) $request->query('branch')) ?? MenuCatalog::defaultBranch();
        $party = max(1, min($c['max_party'], (int) ($request->query('party') ?: 2)));
        $first = local_at()->addHours($c['lead_hours'])->startOfDay();
        $last = local_at()->addDays($c['horizon_days'])->startOfDay();
        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $request->query('date'), local_timezone())->startOfDay();
        } catch (\Throwable) {
            $date = $first->copy();
        }
        if ($date->lt($first) || $date->gt($last)) {
            $date = $first->copy();
        }

        return view('menu.reserve', [
            'settings' => Settings::all(), 'config' => $c, 'branches' => $branches, 'branch' => $branch,
            'party' => $party, 'date' => $date, 'first' => $first, 'last' => $last,
            'slots' => $reservations->slots($branch, $date, $party),
        ]);
    }

    public function store(Request $request, ReservationService $reservations): RedirectResponse
    {
        abort_unless(ReservationService::enabled(), 404);
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'party_size' => 'required|integer|min:1|max:100',
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:500',
        ], [], ['time' => __('Time'), 'name' => __('Your name'), 'phone' => __('Mobile number')]);
        $branch = Branch::query()->where('is_active', true)->find($data['branch_id']) ?? abort(404);

        try {
            $reservation = $reservations->book($data, $branch);
        } catch (ReservationException $e) {
            return back()->withInput()->withErrors(['time' => $e->getMessage()]);
        }

        return redirect()->route('menu.reserve.show', $reservation->token)->with('ok', $reservation->status === 'confirmed'
            ? __('Your table is booked. See you soon!')
            : __('We got your booking. The restaurant will confirm it soon.'));
    }

    public function show(string $token): View
    {
        $reservation = $this->reservation($token);

        return view('menu.reservation', ['reservation' => $reservation->load('branch'), 'settings' => Settings::all()]);
    }

    public function cancel(string $token, ReservationService $reservations): RedirectResponse
    {
        $reservation = $this->reservation($token);
        try {
            $reservations->cancelByGuest($reservation);
        } catch (ReservationException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return back()->with('ok', __('Your booking was cancelled.'));
    }

    /** A guest keeps access to their booking while the restaurant has the feature, even if it stops new bookings. */
    private function reservation(string $token): Reservation
    {
        abort_unless(Entitlements::current()->allows('reservations'), 404);

        return Reservation::query()->where('token', $token)->firstOrFail();
    }
}
