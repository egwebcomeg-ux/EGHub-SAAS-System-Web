<?php

namespace App\Services\Corporate;

use App\Models\CorporateBooking;
use App\Models\CorporateContract;
use App\Models\CorporatePayment;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\User;
use App\Services\Audit;
use App\Services\Orders\OrderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The company account of a corporate contract: what the company is charged (the vouchers issued to it), what it paid
 * (corporate_payments), what its people drew (voucher uses) and what is still on its vouchers. Prepaid or on account,
 * the statement is the same ledger: charges and payments by date, with the balance after each line (positive = the
 * company owes, negative = it paid ahead). corporate_contracts.paid_amount / paid_status follow the payments.
 */
final class CorporateAccount
{
    public const MAX_PAYMENT = 100000000;

    /**
     * @param  array{amount:float|string, method:string, paid_on?:?string, reference?:?string, note?:?string}  $data
     */
    public function recordPayment(CorporateContract $contract, array $data, User $by): CorporatePayment
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0 || $amount > self::MAX_PAYMENT) {
            throw new OrderException(__('Enter the amount the company paid.'));
        }
        if (! isset(CorporatePayment::METHODS[$data['method']])) {
            throw new OrderException(__('Choose how the company paid.'));
        }

        return DB::transaction(function () use ($contract, $data, $amount, $by) {
            $locked = CorporateContract::query()->lockForUpdate()->findOrFail($contract->id);
            $payment = CorporatePayment::query()->create([
                'corporate_contract_id' => $locked->id, 'amount' => $amount, 'method' => $data['method'],
                'paid_on' => $data['paid_on'] ?? local_today(),
                'reference' => ($v = trim((string) ($data['reference'] ?? ''))) !== '' ? mb_substr($v, 0, 60) : null,
                'note' => ($v = trim((string) ($data['note'] ?? ''))) !== '' ? mb_substr($v, 0, 200) : null,
                'received_by' => $by->id,
            ]);
            $this->refreshPaid($locked);
            $contract->setRawAttributes($locked->getAttributes(), true);
            Audit::record('corporate.payment', $locked->name, ['amount' => $amount, 'method' => $payment->method, 'reference' => $payment->reference]);

            return $payment;
        });
    }

    /** A payment entered by mistake: kept on the statement, crossed out, no longer counted. */
    public function cancelPayment(CorporatePayment $payment, User $by, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new OrderException(__('Write why the payment is cancelled.'));
        }
        DB::transaction(function () use ($payment, $by, $reason) {
            // the contract first, like confirming a visit, so a visit never counts a payment being cancelled
            $contract = CorporateContract::query()->lockForUpdate()->findOrFail($payment->corporate_contract_id);
            $locked = CorporatePayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->cancelled_at) {
                throw new OrderException(__('This payment was already cancelled.'));
            }
            $locked->update(['cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => mb_substr($reason, 0, 200)]);
            $this->refreshPaid($contract);
            Audit::record('corporate.payment_cancelled', $contract->name, ['amount' => $locked->amount, 'reason' => $reason]);
        });
    }

    /**
     * The account in figures: charged (vouchers issued), paid, owed (charged − paid; negative = paid ahead), drawn
     * (voucher uses that stand), lost (the rest of one-use vouchers), and the value still on valid and expired vouchers.
     *
     * @return array{charged:float, paid:float, owed:float, drawn:float, lost:float, voucher_balance:float, expired_balance:float, credit_left:?float}
     */
    public function summary(CorporateContract $contract): array
    {
        $paid = $this->paidTotal($contract);
        $charged = $contract->totalValue();
        $uses = CorporateVoucherRedemption::query()->where('corporate_contract_id', $contract->id)->whereNull('refunded_at');
        // a void contract's vouchers can no longer be used: nothing is left on them
        $vouchers = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->where('status', 'active')
            ->when($contract->status === 'void', fn ($q) => $q->whereRaw('1 = 0'));
        $today = local_today();
        $owed = round($charged - $paid, 2);

        return [
            'charged' => $charged, 'paid' => $paid, 'owed' => $owed,
            'drawn' => round((float) (clone $uses)->sum('amount'), 2), 'lost' => round((float) (clone $uses)->sum('forfeited'), 2),
            'voucher_balance' => round((float) (clone $vouchers)->where('expires_on', '>=', $today)->sum('balance'), 2),
            'expired_balance' => round((float) (clone $vouchers)->where('expires_on', '<', $today)->sum('balance'), 2),
            'credit_left' => $contract->billing === 'account' && $contract->credit_limit !== null ? round($contract->credit_limit - $owed, 2) : null,
        ];
    }

    /**
     * The ledger, oldest first: each issue of vouchers and each confirmed visit (charges) and each payment (a credit,
     * a cancelled one shown but not counted), with the balance after it.
     *
     * @return Collection<int, array{at:CarbonImmutable, kind:string, label:string, charge:float, credit:float, balance:float, payment:?CorporatePayment}>
     */
    public function ledger(CorporateContract $contract): Collection
    {
        $issues = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->whereNull('corporate_booking_id')
            ->selectRaw('created_at, COUNT(*) as n')->groupBy('created_at')->orderBy('created_at')->get()
            ->map(fn ($row) => [
                'at' => CarbonImmutable::parse($row->created_at), 'kind' => 'issue',
                'label' => __(':n vouchers of :value', ['n' => (int) $row->n, 'value' => money($contract->voucher_value)]),
                'charge' => round((int) $row->n * $contract->voucher_value, 2), 'credit' => 0.0, 'payment' => null,
            ]);
        $payments = CorporatePayment::query()->where('corporate_contract_id', $contract->id)->with(['receiver:id,name', 'canceller:id,name'])->get()
            ->map(fn (CorporatePayment $p) => [
                'at' => CarbonImmutable::parse($p->paid_on->toDateString().' '.local_at($p->created_at)->format('H:i:s'), local_timezone())->utc(), 'kind' => 'payment',
                'label' => trim($p->methodLabel().' '.($p->reference ? '· '.$p->reference : '')),
                'charge' => 0.0, 'credit' => $p->cancelled_at ? 0.0 : $p->amount, 'payment' => $p,
            ]);

        // a confirmed visit charges its whole budget (a cancelled one is taken back, so it is not listed)
        $visits = CorporateBooking::query()->where('corporate_contract_id', $contract->id)->whereIn('status', ['confirmed', 'closed'])->get()
            ->map(fn (CorporateBooking $b) => [
                'at' => CarbonImmutable::parse($b->decided_at), 'kind' => 'visit',
                'label' => __('Visit of :name on :date · :n guests', ['name' => $b->rep_name, 'date' => local_time($b->visit_at, 'Y/m/d'), 'n' => $b->party_size]),
                'charge' => $b->budget, 'credit' => 0.0, 'payment' => null,
            ]);
        $balance = 0.0;

        return $issues->concat($visits)->concat($payments)->sortBy(fn ($line) => [$line['at']->getTimestamp(), $line['kind'] === 'payment' ? 1 : 0])->values()
            ->map(function (array $line) use (&$balance) {
                $balance = round($balance + $line['charge'] - $line['credit'], 2);

                return $line + ['balance' => $balance];
            });
    }

    private function paidTotal(CorporateContract $contract): float
    {
        return round((float) CorporatePayment::query()->where('corporate_contract_id', $contract->id)->whereNull('cancelled_at')->sum('amount'), 2);
    }

    /** paid_amount / paid_status follow the payments that stand and what the company is charged. */
    public function refreshPaid(CorporateContract $contract): void
    {
        $paid = $this->paidTotal($contract);
        $contract->update(['paid_amount' => $paid, 'paid_status' => CorporateContract::paidStatusFor($paid, $contract->totalValue())]);
    }
}
