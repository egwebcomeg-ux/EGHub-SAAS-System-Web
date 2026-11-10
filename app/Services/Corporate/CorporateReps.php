<?php

namespace App\Services\Corporate;

use App\Models\CorporateContract;
use App\Models\CorporateRep;
use App\Models\CorporateRepMovement;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\Customer;
use App\Models\User;
use App\Services\Audit;
use App\Services\Orders\OrderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The reps of a company ("المناديب"). A company is added on its own; each rep under it then gets:
 *
 *  - a balance: an amount put for him (charged to the company at once, like a visit: a prepaid company needs to have
 *    paid ahead, one on account needs the credit), which can go back to the company while it is not on vouchers;
 *  - vouchers issued out of that balance, of any value, printed with his name;
 *  - when a manager allows it, orders on the company's account outside the vouchers: an allowance kept on one voucher
 *    of his own, linked to his mobile, so the cashier finds it by typing the rep's mobile like any linked voucher. It
 *    is charged to the company only as it is used, and shows on the statement apart from the vouchers.
 *
 * Every change runs with the contract row locked, so two managers can never put the same money twice.
 */
final class CorporateReps
{
    public function __construct(private CorporateAccount $account, private CorporateVouchers $vouchers) {}

    /** @param  array{name:string, phone?:?string}  $data */
    public function add(CorporateContract $contract, array $data): CorporateRep
    {
        if ($contract->status === 'void') {
            throw new OrderException(__('This contract was voided.'));
        }
        $rep = CorporateRep::query()->create(['corporate_contract_id' => $contract->id] + $this->attributes($data, $contract, null));
        Audit::record('corporate.rep_added', $contract->name.' · '.$rep->name);

        return $rep;
    }

    /**
     * His name, mobile and whether he is stopped. A new mobile takes his allowance on the company's account with it.
     *
     * @param  array{name:string, phone?:?string, is_active?:bool}  $data
     */
    public function update(CorporateRep $rep, array $data): void
    {
        DB::transaction(function () use ($rep, $data) {
            $locked = CorporateRep::query()->lockForUpdate()->findOrFail($rep->id);
            $attributes = $this->attributes($data, $locked->contract, $locked->id) + ['is_active' => (bool) ($data['is_active'] ?? $locked->is_active)];
            $direct = $locked->directVoucher()->lockForUpdate()->first();
            if ($direct && $attributes['phone'] === null) {
                throw new OrderException(__('This rep orders on the company\'s account: he is known at the cashier by his mobile.'));
            }
            $locked->update($attributes);
            $direct?->update([
                'customer_id' => $this->customerOf($locked)->id, 'handed_to' => $locked->name, 'handed_to_phone' => $locked->phone,
            ]);
            $rep->setRawAttributes($locked->getAttributes(), true);
            Audit::record('corporate.rep_updated', $locked->contract->name.' · '.$locked->name, ['active' => $locked->is_active]);
        });
    }

    /** Puts an amount on the rep's balance: charged to the company, which must have paid ahead (prepaid) or have the credit (on account). */
    public function topUp(CorporateRep $rep, float $amount, User $by, ?string $note = null): CorporateRepMovement
    {
        $amount = $this->amount($amount);

        return DB::transaction(function () use ($rep, $amount, $by, $note) {
            [$contract, $locked] = $this->lock($rep);
            $available = $this->account->available($contract);
            if ($available !== null && $amount > $available + 0.004) {
                throw new OrderException($contract->billing === 'account'
                    ? __('The company\'s credit left (:left) doesn\'t cover this amount. Record a payment or raise the limit.', ['left' => money(max(0, $available))])
                    : __('The company paid ahead :left only. Record its payment before putting a balance for its rep.', ['left' => money(max(0, $available))]));
            }
            $contract->update(['rep_value' => round($contract->rep_value + $amount, 2)]);
            $this->account->refreshPaid($contract);

            return $this->move($locked, $rep, 'topup', $amount, $by, $note);
        });
    }

    /** Takes what is on the rep's balance (not on vouchers yet) back to the company: no longer charged, free for another rep. */
    public function withdraw(CorporateRep $rep, float $amount, User $by, ?string $note = null): CorporateRepMovement
    {
        $amount = $this->amount($amount);

        return DB::transaction(function () use ($rep, $amount, $by, $note) {
            // a stopped rep's balance goes back too
            [$contract, $locked] = $this->lock($rep, mustBeActive: false);
            if ($amount > $locked->balance + 0.004) {
                throw new OrderException(__('Only :left is on this rep\'s balance.', ['left' => money($locked->balance)]));
            }
            $contract->update(['rep_value' => round(max(0, $contract->rep_value - $amount), 2)]);
            $this->account->refreshPaid($contract);

            return $this->move($locked, $rep, 'withdraw', $amount, $by, $note);
        });
    }

    /**
     * Issues $count vouchers of $value to the rep out of his balance, printed with his name. They are the company's
     * money already (charged when the balance was put), so the company account doesn't move.
     */
    public function issueVouchers(CorporateRep $rep, float $value, int $count, string $expiresOn, string $mode, User $by): int
    {
        $value = round($value, 2);
        if ($value < 1 || $value > CorporateVouchers::MAX_VALUE) {
            throw new OrderException(__('The voucher value is not valid.'));
        }
        if (! isset(CorporateContract::MODES[$mode])) {
            throw new OrderException(__('Choose how the vouchers are used.'));
        }
        $day = $this->futureDate($expiresOn);

        return DB::transaction(function () use ($rep, $value, $count, $day, $mode, $by) {
            [$contract, $locked] = $this->lock($rep);
            $total = round($value * $count, 2);
            if ($count >= 1 && $total > $locked->balance + 0.004) {
                throw new OrderException(__('The rep\'s balance (:left) doesn\'t cover :n vouchers of :value.', ['left' => money($locked->balance), 'n' => $count, 'value' => money($value)]));
            }
            $this->vouchers->issue($contract, $count, $locked->name, $locked->phone, [
                'value' => $value, 'expires_on' => $day, 'mode' => $mode, 'corporate_rep_id' => $locked->id,
            ]);
            $this->move($locked, $rep, 'issue', $total, $by, __(':n vouchers of :value', ['n' => $count, 'value' => money($value)]));

            return $count;
        });
    }

    /** A voided voucher of a rep (called with the voucher locked, inside its transaction): what was left on it is his balance again. */
    public function voucherVoided(CorporateVoucher $voucher): void
    {
        $rep = CorporateRep::query()->lockForUpdate()->find($voucher->corporate_rep_id);
        if ($rep) {
            $this->move($rep, $rep, 'return', $voucher->balance, auth()->user(), $voucher->serial);
        }
    }

    /**
     * How much the rep may still order on the company's account, outside the vouchers (0 = no more). Kept on one
     * voucher of his own linked to his mobile; what he already took stays charged to the company.
     */
    public function allowDirect(CorporateRep $rep, float $left, User $by): CorporateVoucher
    {
        $left = round($left, 2);
        if ($left < 0 || $left > CorporateAccount::MAX_PAYMENT) {
            throw new OrderException(__('Enter how much the rep may order on the company\'s account.'));
        }

        return DB::transaction(function () use ($rep, $left) {
            [$contract, $locked] = $this->lock($rep);
            if ($locked->phone === null) {
                throw new OrderException(__('Add the rep\'s mobile first: he is known at the cashier by it.'));
            }
            $direct = $locked->directVoucher()->lockForUpdate()->first();
            if (! $direct) {
                $direct = $this->vouchers->issueOne($contract, max($left, 0.01), $contract->expires_on->format('Y-m-d'), null, 'balance', null, $this->customerOf($locked)->id);
                $direct->forceFill(['direct' => true, 'corporate_rep_id' => $locked->id, 'handed_to' => $locked->name, 'handed_to_phone' => $locked->phone])->save();
            }
            $taken = round((float) $direct->redemptions()->whereNull('refunded_at')->sum('amount'), 2);
            $direct->update(['value' => round($taken + $left, 2), 'balance' => $left, 'status' => $left > 0 ? 'active' : 'used']);
            Audit::record('corporate.rep_direct', $contract->name.' · '.$locked->name, ['left' => $left]);

            return $direct;
        });
    }

    /**
     * Each rep of the company in figures, for the company page and its statement: what was put for him, what went back,
     * his vouchers (issued, drawn, still on them), and his orders on the company's account.
     *
     * @return Collection<int, array{rep:CorporateRep, put:float, issued:float, vouchers:int, unprinted:int, drawn:float, on_vouchers:float, direct:float, direct_left:?float}>
     */
    public function summaries(CorporateContract $contract): Collection
    {
        $reps = CorporateRep::query()->where('corporate_contract_id', $contract->id)->with('directVoucher')->orderBy('id')->get();
        $moves = CorporateRepMovement::query()->where('corporate_contract_id', $contract->id)
            ->selectRaw('corporate_rep_id, kind, SUM(amount) as total')->groupBy('corporate_rep_id', 'kind')->get()->groupBy('corporate_rep_id');
        $vouchers = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->whereNotNull('corporate_rep_id')->where('direct', false)
            ->selectRaw("corporate_rep_id, SUM(CASE WHEN status != 'void' THEN 1 ELSE 0 END) as n, SUM(CASE WHEN status != 'void' AND print_count = 0 THEN 1 ELSE 0 END) as unprinted,
                SUM(CASE WHEN status = 'active' THEN balance ELSE 0 END) as left_on")->groupBy('corporate_rep_id')->get()->keyBy('corporate_rep_id');
        $uses = CorporateVoucherRedemption::query()->where('corporate_voucher_redemptions.corporate_contract_id', $contract->id)->whereNull('refunded_at')
            ->join('corporate_vouchers', 'corporate_vouchers.id', '=', 'corporate_voucher_redemptions.corporate_voucher_id')->whereNotNull('corporate_vouchers.corporate_rep_id')
            ->selectRaw('corporate_vouchers.corporate_rep_id as rep_id, corporate_vouchers.direct as direct, SUM(corporate_voucher_redemptions.amount) as total')
            ->groupBy('corporate_vouchers.corporate_rep_id', 'corporate_vouchers.direct')->get()->groupBy('rep_id');

        return $reps->map(function (CorporateRep $rep) use ($moves, $vouchers, $uses) {
            $kind = fn (string $k) => round((float) ($moves->get($rep->id)?->firstWhere('kind', $k)?->total ?? 0), 2);
            $used = fn (bool $direct) => round((float) ($uses->get($rep->id)?->first(fn ($u) => (bool) $u->direct === $direct)?->total ?? 0), 2);
            $own = $vouchers->get($rep->id);

            return [
                'rep' => $rep, 'put' => round($kind('topup') - $kind('withdraw'), 2), 'issued' => round($kind('issue') - $kind('return'), 2),
                'vouchers' => (int) ($own->n ?? 0), 'unprinted' => (int) ($own->unprinted ?? 0),
                'drawn' => $used(false), 'on_vouchers' => round((float) ($own->left_on ?? 0), 2),
                'direct' => $used(true), 'direct_left' => $rep->directVoucher ? (float) $rep->directVoucher->balance : null,
            ];
        });
    }

    /** @return array{0:CorporateContract, 1:CorporateRep} the contract first, then the rep, both locked and usable */
    private function lock(CorporateRep $rep, bool $mustBeActive = true): array
    {
        $contract = CorporateContract::query()->lockForUpdate()->findOrFail($rep->corporate_contract_id);
        $locked = CorporateRep::query()->lockForUpdate()->findOrFail($rep->id);
        if ($contract->status === 'void' || ($mustBeActive && $contract->status !== 'active')) {
            throw new OrderException(__('This contract is not active.'));
        }
        if ($mustBeActive && ! $locked->is_active) {
            throw new OrderException(__('This rep is stopped.'));
        }

        return [$contract, $locked];
    }

    /** Writes the move and the balance after it on the locked rep (and on the caller's copy). */
    private function move(CorporateRep $locked, CorporateRep $rep, string $kind, float $amount, ?User $by, ?string $note): CorporateRepMovement
    {
        $balance = round($locked->balance + (in_array($kind, ['topup', 'return'], true) ? $amount : -$amount), 2);
        $locked->update(['balance' => max(0, $balance)]);
        $rep->balance = $locked->balance;
        $rep->syncOriginalAttribute('balance');
        Audit::record('corporate.rep_'.$kind, $locked->name, ['amount' => $amount, 'balance' => $locked->balance], $by?->id);

        return CorporateRepMovement::query()->create([
            'corporate_rep_id' => $locked->id, 'corporate_contract_id' => $locked->corporate_contract_id, 'kind' => $kind, 'amount' => $amount,
            'balance_after' => $locked->balance, 'note' => ($v = trim((string) $note)) !== '' ? mb_substr($v, 0, 200) : null, 'user_id' => $by?->id,
        ]);
    }

    /** The guest record of the rep's mobile: his allowance on the company's account is linked to it. */
    private function customerOf(CorporateRep $rep): Customer
    {
        return Customer::query()->firstOrCreate(['phone' => $rep->phone], ['name' => $rep->name, 'source' => 'pos']);
    }

    /**
     * @return array{name:string, phone:?string} cleaned; the mobile is a valid Egyptian one, not another rep's of this company
     */
    private function attributes(array $data, CorporateContract $contract, ?int $exceptId): array
    {
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 100);
        if ($name === '') {
            throw new OrderException(__('Write the rep\'s name.'));
        }
        $phone = null;
        if (trim((string) ($data['phone'] ?? '')) !== '') {
            $phone = normalize_phone((string) $data['phone']) ?? throw new OrderException(__('Enter a valid Egyptian mobile number.'));
            if (CorporateRep::query()->where('corporate_contract_id', $contract->id)->where('phone', $phone)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
                throw new OrderException(__('Another rep of this company has this mobile.'));
            }
        }

        return ['name' => $name, 'phone' => $phone];
    }

    private function amount(float $amount): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > CorporateAccount::MAX_PAYMENT) {
            throw new OrderException(__('Enter the amount.'));
        }

        return $amount;
    }

    private function futureDate(string $date): string
    {
        try {
            $day = CarbonImmutable::parse($date)->format('Y-m-d');
        } catch (\Throwable) {
            throw new OrderException(__('Choose a date.'));
        }
        if ($day < local_today()) {
            throw new OrderException(__('The new date can\'t be in the past.'));
        }

        return $day;
    }
}
