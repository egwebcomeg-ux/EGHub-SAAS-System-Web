<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CorporateBooking;
use App\Models\CorporateContract;
use App\Services\Corporate\CorporateBookings;
use App\Services\Orders\OrderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Company visits (feature corporate_vouchers): the visits companies asked through their private link or staff
 * entered, waiting for a manager, upcoming and past. Staff with reservations.manage may enter a visit; confirming,
 * declining, cancelling and closing take contracts.manage (a manager), as every booking goes back to the manager.
 */
class CorporateBookingsController extends Controller
{
    public const FILTERS = ['open', 'requested', 'confirmed', 'closed', 'declined', 'cancelled', 'all'];

    public function index(Request $request, CorporateBookings $service): View
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : 'open';
        $bookings = CorporateBooking::query()->with(['contract', 'branch', 'decider:id,name', 'vouchers'])
            ->when($filter === 'open', fn ($q) => $q->whereIn('status', ['requested', 'confirmed']))
            ->when(! in_array($filter, ['open', 'all'], true), fn ($q) => $q->where('status', $filter))
            ->when($request->integer('contract'), fn ($q, $id) => $q->where('corporate_contract_id', $id))
            ->orderByRaw("CASE WHEN status = 'requested' THEN 0 ELSE 1 END")
            ->orderBy('visit_at', in_array($filter, ['open', 'requested', 'confirmed'], true) ? 'asc' : 'desc')
            ->paginate(30)->withQueryString();

        return view('dashboard.corporate.bookings', [
            'bookings' => $bookings, 'filter' => $filter,
            'waiting' => CorporateBooking::query()->where('status', 'requested')->count(),
            'available' => $bookings->getCollection()->where('status', 'requested')->pluck('contract')->unique('id')
                ->mapWithKeys(fn (CorporateContract $c) => [$c->id => $service->available($c)]),
            'contracts' => CorporateContract::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'branch_ids', 'booking_notice_hours']),
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'canDecide' => $request->user()->can('contracts.manage'),
        ]);
    }

    /** A visit the company asked by phone: entered by staff, still confirmed by a manager. */
    public function store(Request $request, CorporateBookings $service): RedirectResponse
    {
        $data = $request->validate([
            'corporate_contract_id' => 'required|integer',
            'branch_id' => 'nullable|integer',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'party_size' => 'required|integer|min:1|max:'.CorporateBookings::MAX_PARTY,
            'budget' => 'required|numeric|min:1|max:'.CorporateBookings::MAX_BUDGET,
            'rep_name' => 'required|string|max:100',
            'rep_phone' => 'required|string|max:20',
            'notes' => 'nullable|string|max:500',
        ]);
        $contract = CorporateContract::query()->findOrFail($data['corporate_contract_id']);
        try {
            $service->request($contract, $data, 'staff', $request->user());
        } catch (OrderException $e) {
            return back()->withInput()->withErrors(['date' => $e->getMessage()]);
        }

        return redirect()->route('dash.corporate.bookings')->with('ok', __('Visit added. A manager confirms it.'));
    }

    public function approve(Request $request, CorporateBooking $booking, CorporateBookings $service): RedirectResponse
    {
        $data = $request->validate(['meal_price' => 'required|numeric|min:0|max:'.CorporateBookings::MAX_BUDGET]);
        try {
            $service->approve($booking, (float) $data['meal_price'], $request->user());
        } catch (OrderException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('ok', __('Visit confirmed. Send the meals voucher to :name on WhatsApp.', ['name' => $booking->rep_name]));
    }

    public function decline(Request $request, CorporateBooking $booking, CorporateBookings $service): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:200']);
        try {
            $service->decline($booking, $data['reason'], $request->user());
        } catch (OrderException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('ok', __('Visit declined.'));
    }

    public function cancel(Request $request, CorporateBooking $booking, CorporateBookings $service): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:200']);
        try {
            $service->cancel($booking, $data['reason'], $request->user());
        } catch (OrderException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('ok', __('Visit cancelled.'));
    }

    public function close(Request $request, CorporateBooking $booking, CorporateBookings $service): RedirectResponse
    {
        try {
            $service->close($booking, $request->user());
        } catch (OrderException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return back()->with('ok', __('Visit closed. What the meals did not use is on the company\'s voucher.'));
    }

    /** A new private link for the company; the old one stops working. */
    public function portal(CorporateContract $contract, CorporateBookings $service): RedirectResponse
    {
        abort_if($contract->status === 'void', 404);
        $service->rotatePortal($contract);

        return back()->with('ok', __('New company link ready. The old link no longer works.'));
    }
}
