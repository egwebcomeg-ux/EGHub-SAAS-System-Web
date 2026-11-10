<?php

namespace App\Services\Corporate;

use App\Models\CorporateBooking;
use App\Models\CorporateContract;
use App\Models\CorporatePayment;
use App\Models\CorporateRep;
use App\Models\CorporateRepMovement;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\User;
use App\Services\Audit;
use App\Services\Orders\OrderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The company account of a corporate contract: what the company is charged (the vouchers issued to it, its visits,
 * what was put on its reps' balances and what its reps ordered on its account), what it paid (corporate_payments),
 * what its people drew (voucher uses) and what is still on its vouchers. Prepaid or on account,
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
     * The account in figures: charged, paid, owed (charged − paid; negative = paid ahead), drawn (voucher uses that
     * stand), direct (the reps' orders on the company's account), lost (the rest of one-use vouchers), the value still
     * on valid and expired vouchers, and what is on the reps' balances and not on vouchers yet.
     *
     * @return array{charged:float, paid:float, owed:float, drawn:float, direct:float, lost:float, voucher_balance:float, expired_balance:float, rep_balance:float, credit_left:?float}
     */
    public function summary(CorporateContract $contract): array
    {
        $paid = $this->paidTotal($contract);
        $charged = $contract->totalValue();
        $uses = CorporateVoucherRedemption::query()->where('corporate_contract_id', $contract->id)->whereNull('refunded_at');
        // a void contract's vouchers can no longer be used: nothing is left on them
        $vouchers = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->where('status', 'active')->where('direct', false)
            ->when($contract->status === 'void', fn ($q) => $q->whereRaw('1 = 0'));
        $today = local_today();
        $owed = round($charged - $paid, 2);

        return [
            'charged' => $charged, 'paid' => $paid, 'owed' => $owed,
            'drawn' => round((float) (clone $uses)->sum('amount') - $contract->direct_value, 2), 'direct' => $contract->direct_value,
            'lost' => round((float) (clone $uses)->sum('forfeited'), 2),
            'voucher_balance' => round((float) (clone $vouchers)->where('expires_on', '>=', $today)->sum('balance'), 2),
            'expired_balance' => round((float) (clone $vouchers)->where('expires_on', '<', $today)->sum('balance'), 2),
            'rep_balance' => $contract->status === 'void' ? 0.0 : round((float) CorporateRep::query()->where('corporate_contract_id', $contract->id)->sum('balance'), 2),
            'credit_left' => $contract->billing === 'account' && $contract->credit_limit !== null ? round($contract->credit_limit - $owed, 2) : null,
        ];
    }

    /**
     * What the company can still take: a prepaid company what it paid ahead, one on account what is left of its
     * credit (null = no limit).
     */
    public function available(CorporateContract $contract): ?float
    {
        $summary = $this->summary($contract);

        return $contract->billing === 'account' ? $summary['credit_left'] : round(-$summary['owed'], 2);
    }

    /**
     * The ledger, oldest first: each issue of vouchers, each confirmed visit, each amount put on a rep's balance and
     * each order of a rep on the company's account (charges), and each payment or amount taken back from a rep (a
     * credit; a cancelled payment is shown but not counted), with the balance after it.
     *
     * @return Collection<int, array{at:CarbonImmutable, kind:string, label:string, charge:float, credit:float, balance:float, payment:?CorporatePayment}>
     */
    public function ledger(CorporateContract $contract): Collection
    {
        $issues = CorporateVoucher::query()->where('corporate_contract_id', $contract->id)->whereNull('corporate_booking_id')->whereNull('corporate_rep_id')
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
        $reps = CorporateRepMovement::query()->where('corporate_contract_id', $contract->id)->whereIn('kind', ['topup', 'withdraw'])->with('rep:id,name')->get()
            ->map(fn (CorporateRepMovement $m) => [
                'at' => CarbonImmutable::parse($m->created_at), 'kind' => $m->kind === 'topup' ? 'rep' : 'rep_back',
                'label' => trim(($m->rep?->name ?? '').($m->note ? ' · '.$m->note : '')),
                'charge' => $m->kind === 'topup' ? $m->amount : 0.0, 'credit' => $m->kind === 'topup' ? 0.0 : $m->amount, 'payment' => null,
            ]);
        $direct = CorporateVoucherRedemption::query()->where('corporate_contract_id', $contract->id)->whereNull('refunded_at')
            ->whereIn('corporate_voucher_id', CorporateVoucher::query()->where('direct', true)->select('id'))->with(['voucher.rep:id,name', 'order:id,number'])->get()
            ->map(fn (CorporateVoucherRedemption $r) => [
                'at' => CarbonImmutable::parse($r->created_at), 'kind' => 'direct',
                'label' => trim(($r->voucher?->rep?->name ?? '').($r->order ? ' · #'.$r->order->number : ($r->reference ? ' · '.$r->reference : ''))),
                'charge' => $r->amount, 'credit' => 0.0, 'payment' => null,
            ]);
        $balance = 0.0;

        return $issues->concat($visits)->concat($reps)->concat($direct)->concat($payments)->sortBy(fn ($line) => [$line['at']->getTimestamp(), $line['kind'] === 'payment' ? 1 : 0])->values()
            ->map(function (array $line) use (&$balance) {
                $balance = round($balance + $line['charge'] - $line['credit'], 2);

                return $line + ['balance' => $balance];
            });
    }

    private function paidTotal(CorporateContract $contract): float
    {
        return round((float) CorporatePayment::query()->where('corporate_contract_id', $contract->id)->whereNull('cancelled_at')->sum('amount'), 2);
    }

    /** direct_value follows the uses that stand of the reps' allowances on the company's account (a refunded one no longer counts). */
    public function refreshDirect(int $contractId): void
    {
        $contract = CorporateContract::query()->lockForUpdate()->find($contractId);
        if (! $contract) {
            return;
        }
        $contract->update(['direct_value' => round((float) CorporateVoucherRedemption::query()->where('corporate_contract_id', $contract->id)->whereNull('refunded_at')
            ->whereIn('corporate_voucher_id', CorporateVoucher::query()->where('direct', true)->select('id'))->sum('amount'), 2)]);
        $this->refreshPaid($contract);
    }

    /** paid_amount / paid_status follow the payments that stand and what the company is charged. */
    public function refreshPaid(CorporateContract $contract): void
    {
        $paid = $this->paidTotal($contract);
        $contract->update(['paid_amount' => $paid, 'paid_status' => CorporateContract::paidStatusFor($paid, $contract->totalValue())]);
    }
}
