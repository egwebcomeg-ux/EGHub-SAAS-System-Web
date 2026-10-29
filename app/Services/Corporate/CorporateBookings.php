<?php

namespace App\Services\Corporate;

use App\Models\Branch;
use App\Models\CorporateBooking;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Audit;
use App\Services\Entitlements;
use App\Services\Orders\OrderException;
use App\Services\Push\StaffPush;
use App\Services\Reservations\ReservationException;
use App\Services\Reservations\ReservationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Company visits on a corporate contract: "our rep comes today with 10,000 EGP for 8 people".
 *
 * The company asks through its private link (at least booking_notice_hours ahead) or staff enter it; nothing is
 * charged until a manager confirms. Confirming splits the budget: the meals part (meal price × guests) becomes a
 * voucher valid on the visit day only, linked to the rep's mobile; the rest becomes a voucher for the company, valid
 * until the contract ends. The whole budget is charged to the company account (corporate_contracts.extra_value), so a
 * prepaid company needs the balance and one on account the credit. After the visit it is closed (by hand or by the
 * nightly run): what the meals voucher did not use moves onto the company's voucher. Cancelling a confirmed visit
 * whose vouchers were not used voids them and takes the charge back.
 */
final class CorporateBookings
{
    public const MAX_PARTY = 500;

    public const MAX_BUDGET = 1000000;

    /** Upcoming requests one company may have waiting at once (its link is a shared secret, not a login). */
    public const MAX_WAITING = 10;

    public function __construct(private CorporateAccount $account, private CorporateVouchers $vouchers) {}

    /**
     * A visit asked by the company (source portal: the notice period applies) or entered by staff.
     *
     * @param  array{branch_id?:int|string|null, date:string, time:string, party_size:int|string, budget:float|string, rep_name:string, rep_phone:string, notes?:?string}  $data
     */
    public function request(CorporateContract $contract, array $data, string $source, ?User $by = null): CorporateBooking
    {
        if ($contract->status !== 'active') {
            throw new OrderException(__('This contract is not active. Call the restaurant.'));
        }
        $branch = $this->branchFor($contract, $data['branch_id'] ?? null);
        try {
            $at = Carbon::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], config('app.timezone'))->startOfMinute();
        } catch (\Throwable) {
            throw new OrderException(__('Choose the day and time of the visit.'));
        }
        if ($source === 'portal' && $at->lt(now()->addHours($contract->booking_notice_hours))) {
            throw new OrderException(__('Visits are booked at least :n hours ahead. For a sooner visit, call the restaurant.', ['n' => $contract->booking_notice_hours]));
        }
        if ($at->lt(now()->startOfDay())) {
            throw new OrderException(__('The visit can\'t be in the past.'));
        }
        if ($contract->expires_on && $at->copy()->startOfDay()->gt($contract->expires_on)) {
            throw new OrderException(__('The contract ends on :date.', ['date' => local_time($contract->expires_on, 'Y/m/d')]));
        }
        $this->checkWindow($contract, $at);
        $party = (int) $data['party_size'];
        if ($party < 1 || $party > self::MAX_PARTY) {
            throw new OrderException(__('Between 1 and :n guests.', ['n' => self::MAX_PARTY]));
        }
        $budget = round((float) $data['budget'], 2);
        if ($budget <= 0 || $budget > self::MAX_BUDGET) {
            throw new OrderException(__('Enter the budget of the visit.'));
        }
        $phone = normalize_phone((string) $data['rep_phone']) ?? throw new OrderException(__('Enter a valid Egyptian mobile number.'));
        $name = mb_substr(trim((string) $data['rep_name']), 0, 100);
        if ($name === '') {
            throw new OrderException(__('Write the name of who is coming.'));
        }
        if ($source === 'portal' && $contract->bookings()->where('status', 'requested')->count() >= self::MAX_WAITING) {
            throw new OrderException(__('You already have :n visits waiting for the restaurant. Call the restaurant.', ['n' => self::MAX_WAITING]));
        }

        $booking = CorporateBooking::query()->create([
            'corporate_contract_id' => $contract->id, 'branch_id' => $branch?->id, 'visit_at' => $at, 'party_size' => $party,
            'budget' => $budget, 'rep_name' => $name, 'rep_phone' => $phone,
            'notes' => ($v = trim((string) ($data['notes'] ?? ''))) !== '' ? mb_substr($v, 0, 500) : null,
            'source' => $source === 'portal' ? 'portal' : 'staff', 'status' => 'requested', 'created_by' => $by?->id,
        ]);
        Audit::record('corporate.booking_requested', $contract->name, ['booking' => $booking->id, 'budget' => $budget, 'at' => $at->format('Y-m-d H:i')], $by?->id);
        app(StaffPush::class)->corporateBookingRequested($booking->setRelation('contract', $contract));

        return $booking;
    }

    /**
     * What the company can still take: a prepaid company what it paid ahead, one on account what is left of its
     * credit (null = no limit).
     */
    public function available(CorporateContract $contract): ?float
    {
        $summary = $this->account->summary($contract);
        if ($contract->billing === 'account') {
            return $summary['credit_left'];
        }

        return round(-$summary['owed'], 2);
    }

    /**
     * A manager confirms the visit: the meals part (meal price × guests, at most the budget) on a voucher for the
     * visit day linked to the rep's mobile, the rest on a voucher for the company, the budget charged to the company.
     */
    public function approve(CorporateBooking $booking, float $mealPrice, User $by): CorporateBooking
    {
        $mealPrice = round($mealPrice, 2);
        if ($mealPrice < 0) {
            throw new OrderException(__('Enter the meal price per guest.'));
        }

        $done = DB::transaction(function () use ($booking, $mealPrice, $by) {
            $locked = CorporateBooking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== 'requested') {
                throw new OrderException(__('This visit was already answered.'));
            }
            $contract = CorporateContract::query()->lockForUpdate()->findOrFail($locked->corporate_contract_id);
            if ($contract->status !== 'active') {
                throw new OrderException(__('This contract is not active.'));
            }
            if ($locked->visit_at->copy()->endOfDay()->isPast()) {
                throw new OrderException(__('The day of this visit has passed.'));
            }
            $this->checkWindow($contract, $locked->visit_at);
            $available = $this->available($contract);
            if ($available !== null && $locked->budget > $available + 0.004) {
                throw new OrderException($contract->billing === 'account'
                    ? __('The company\'s credit left (:left) doesn\'t cover this visit. Record a payment or raise the limit.', ['left' => money(max(0, $available))])
                    : __('The company paid ahead :left only. Record its payment before confirming the visit.', ['left' => money(max(0, $available))]));
            }
            $meals = round(min($locked->budget, $mealPrice * $locked->party_size), 2);
            $rest = round($locked->budget - $meals, 2);
            $day = $locked->visit_at->format('Y-m-d');

            if ($meals > 0) {
                $rep = Customer::query()->firstOrCreate(['phone' => $locked->rep_phone], ['name' => $locked->rep_name, 'source' => 'pos']);
                $this->vouchers->issueOne($contract, $meals, $day, $day, 'balance', $locked->id, $rep->id);
            }
            if ($rest > 0) {
                $this->vouchers->issueOne($contract, $rest, $this->companyExpiry($contract, $day), null, 'balance', $locked->id);
            }
            $contract->update(['extra_value' => round($contract->extra_value + $locked->budget, 2)]);
            $this->account->refreshPaid($contract);
            $locked->update([
                'status' => 'confirmed', 'meals_amount' => $meals, 'voucher_amount' => $rest, 'decided_by' => $by->id, 'decided_at' => now(),
            ]);
            Audit::record('corporate.booking_confirmed', $contract->name, ['booking' => $locked->id, 'meals' => $meals, 'voucher' => $rest]);

            return $locked;
        });
        $this->holdTable($done, $by);

        return $done->refresh();
    }

    public function decline(CorporateBooking $booking, string $reason, User $by): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new OrderException(__('Write why the visit is declined, the company sees it.'));
        }
        DB::transaction(function () use ($booking, $reason, $by) {
            $locked = CorporateBooking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== 'requested') {
                throw new OrderException(__('This visit was already answered.'));
            }
            $locked->update(['status' => 'declined', 'decline_reason' => mb_substr($reason, 0, 200), 'decided_by' => $by->id, 'decided_at' => now()]);
            Audit::record('corporate.booking_declined', $locked->contract->name, ['booking' => $locked->id, 'reason' => $reason]);
        });
    }

    /**
     * Cancels a visit still waiting, or a confirmed one whose vouchers were not used: they are voided and the budget
     * is taken off the company account.
     */
    public function cancel(CorporateBooking $booking, string $reason, User $by): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new OrderException(__('Write why the visit is cancelled.'));
        }
        $reservationId = DB::transaction(function () use ($booking, $reason, $by) {
            $locked = CorporateBooking::query()->lockForUpdate()->findOrFail($booking->id);
            if (! in_array($locked->status, ['requested', 'confirmed'], true)) {
                throw new OrderException(__('Only a visit waiting or confirmed can be cancelled.'));
            }
            $contract = CorporateContract::query()->lockForUpdate()->findOrFail($locked->corporate_contract_id);
            if ($locked->status === 'confirmed') {
                $ids = CorporateVoucher::query()->where('corporate_booking_id', $locked->id)->lockForUpdate()->pluck('id');
                if (CorporateVoucherRedemption::query()->whereIn('corporate_voucher_id', $ids)->whereNull('refunded_at')->exists()) {
                    throw new OrderException(__('The vouchers of this visit were already used: it can\'t be cancelled.'));
                }
                CorporateVoucher::query()->whereIn('id', $ids)->update(['status' => 'void', 'updated_at' => now()]);
                $contract->update(['extra_value' => round(max(0, $contract->extra_value - $locked->budget), 2)]);
                $this->account->refreshPaid($contract);
            }
            $locked->update(['status' => 'cancelled', 'decline_reason' => mb_substr($reason, 0, 200), 'decided_by' => $by->id, 'decided_at' => now()]);
            Audit::record('corporate.booking_cancelled', $contract->name, ['booking' => $locked->id, 'reason' => $reason]);

            return $locked->reservation_id;
        });
        $reservation = $reservationId ? Reservation::query()->find($reservationId) : null;
        if ($reservation && $reservation->canMoveTo('cancelled')) {
            app(ReservationService::class)->transition($reservation, 'cancelled');
        }
    }

    /**
     * After the visit: what is left on the meals voucher moves onto the company's voucher (a new one when the whole
     * budget went on meals). The charge does not change: the money stays the company's.
     */
    public function close(CorporateBooking $booking, ?User $by = null): CorporateBooking
    {
        return DB::transaction(function () use ($booking, $by) {
            $locked = CorporateBooking::query()->lockForUpdate()->findOrFail($booking->id);
            if ($locked->status !== 'confirmed') {
                throw new OrderException(__('Only a confirmed visit can be closed.'));
            }
            if ($locked->visit_at->copy()->startOfDay()->isFuture()) {
                throw new OrderException(__('The visit is closed on its day or after it.'));
            }
            $meals = CorporateVoucher::query()->where('corporate_booking_id', $locked->id)->whereNotNull('valid_from')->lockForUpdate()->first();
            $left = $meals && $meals->status === 'active' ? round($meals->balance, 2) : 0.0;
            if ($meals && $meals->status === 'active') {
                // the meals voucher keeps what it spent: its value is what the visit ate
                $meals->update(['value' => round($meals->value - $left, 2), 'balance' => 0, 'status' => 'used']);
            }
            $locked->update(['status' => 'closed', 'closed_at' => now()]);
            $this->moveToCompany($locked, $left);
            Audit::record('corporate.booking_closed', $locked->contract->name, ['booking' => $locked->id, 'moved' => $left], $by?->id);

            return $locked;
        });
    }

    /**
     * Money of a visit's meals that is not spent on its day (left at closing, or an order refunded after closing)
     * goes onto the company's voucher of that visit (a new one when it has none usable). Call inside a transaction
     * with the visit locked.
     */
    public function moveToCompany(CorporateBooking $booking, float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }
        $company = CorporateVoucher::query()->where('corporate_booking_id', $booking->id)->whereNull('valid_from')->where('status', 'active')
            ->lockForUpdate()->first();
        if ($company) {
            $company->update(['value' => round($company->value + $amount, 2), 'balance' => round($company->balance + $amount, 2)]);
        } else {
            $contract = CorporateContract::query()->findOrFail($booking->corporate_contract_id);
            $this->vouchers->issueOne($contract, $amount, $this->companyExpiry($contract, $booking->visit_at->format('Y-m-d')), null, 'balance', $booking->id);
        }
        $booking->update([
            'meals_amount' => round((float) $booking->meals_amount - $amount, 2), 'voucher_amount' => round((float) $booking->voucher_amount + $amount, 2),
        ]);
    }

    /** The nightly run: confirmed visits of past days are closed. Returns how many. */
    public function closePast(): int
    {
        $n = 0;
        $ids = CorporateBooking::query()->where('status', 'confirmed')->where('visit_at', '<', now()->startOfDay())->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            try {
                $this->close(CorporateBooking::query()->findOrFail($id));
                $n++;
            } catch (\Throwable $e) {
                report($e); // e.g. cancelled meanwhile: the next run or a manager sees to it
            }
        }

        return $n;
    }

    /** A fresh private link for the company (the old one stops working). */
    public function rotatePortal(CorporateContract $contract): string
    {
        $contract->update(['portal_token' => random_token(12)]);
        Audit::record('corporate.portal_link', $contract->name);

        return $contract->portal_token;
    }

    /** The visit falls in the contract's days and hours, where its vouchers work. */
    private function checkWindow(CorporateContract $contract, \DateTimeInterface $at): void
    {
        if (! $contract->allowsTime(Carbon::instance($at))) {
            throw new OrderException(__('The contract works on :window only.', ['window' => $contract->windowLabel()]));
        }
    }

    private function branchFor(CorporateContract $contract, int|string|null $id): ?Branch
    {
        if ($id === null || $id === '') {
            return null;
        }
        $branch = Branch::query()->where('is_active', true)->find((int) $id);
        if (! $branch || ! $contract->allowsBranch($branch->id)) {
            throw new OrderException(__('Choose one of the contract\'s branches.'));
        }

        return $branch;
    }

    /** The company's voucher lasts as long as the contract, and at least past the visit. */
    private function companyExpiry(CorporateContract $contract, string $visitDay): string
    {
        $end = $contract->expires_on?->format('Y-m-d');

        return $end && $end > $visitDay ? $end : $visitDay;
    }

    /** Holds the table when the restaurant takes reservations; a full slot never blocks the confirmation. */
    private function holdTable(CorporateBooking $booking, User $by): void
    {
        if (! $booking->branch_id || ! Entitlements::current()->allows('reservations')) {
            return;
        }
        try {
            $reservation = app(ReservationService::class)->book([
                'date' => $booking->visit_at->format('Y-m-d'), 'time' => $booking->visit_at->format('H:i'),
                'party_size' => $booking->party_size, 'name' => $booking->rep_name, 'phone' => $booking->rep_phone,
                'notes' => trim($booking->contract->name.' · '.($booking->notes ?? ''), ' ·'),
            ], $booking->branch, $by);
            $booking->update(['reservation_id' => $reservation->id]);
        } catch (ReservationException) {
            // no table held: the visit stands, staff seat them as they come
        } catch (\Throwable $e) {
            report($e); // the visit is confirmed already; a table that could not be held never undoes that
        }
    }
}
