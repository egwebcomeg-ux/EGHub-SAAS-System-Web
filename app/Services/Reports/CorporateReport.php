<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\CorporateContract;
use App\Models\CorporateVoucher;
use App\Models\CorporateVoucherRedemption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "تقرير الفاوتشرات": corporate vouchers for a date range (restaurant local time), optionally one contract, branch,
 * cashier or mode. Uses = redemption rows that were not given back (a cancelled order or a cancelled paper bill is
 * left out). What a voucher still holds (outstanding, expiring soon) is as of today; issued, redeemed, lost,
 * exceptions and expired-unused are for the range. Aggregated in PHP, like SalesReport, so the same code runs on
 * MySQL and SQLite.
 */
final class CorporateReport
{
    public const EXPIRING_DAYS = 30;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $branchId = null,
        public readonly ?int $contractId = null,
        public readonly ?int $cashierId = null,
        public readonly ?string $mode = null,
    ) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $uses = $this->uses()->with(['voucher:id,serial,corporate_contract_id', 'approver:id,name', 'cashier:id,name'])->get();
        $today = CarbonImmutable::today()->format('Y-m-d');
        $vouchers = $this->vouchers();
        $live = $vouchers->filter(fn ($v) => $v->status !== 'void');
        $issued = $live->filter(fn ($v) => $v->created_at->between($this->from->startOfDay(), $this->to->endOfDay()));
        $outstanding = $live->filter(fn ($v) => $v->status === 'active' && $v->balance > 0 && $v->expires_on->format('Y-m-d') >= $today);
        $expired = $this->expiredUnused($live);
        $exceptions = $uses->whereNotNull('approved_by');

        return [
            'kpis' => [
                'issued' => round((float) $issued->sum('value'), 2), 'issued_count' => $issued->count(),
                'redeemed' => round((float) $uses->sum('amount'), 2), 'uses' => $uses->count(),
                'paper' => round((float) $uses->whereNull('order_id')->sum('amount'), 2),
                'outstanding' => round((float) $outstanding->sum('balance'), 2), 'outstanding_count' => $outstanding->count(),
                'lost' => round((float) $uses->sum('forfeited'), 2),
                'expired' => round((float) $expired->sum('balance'), 2), 'expired_count' => $expired->count(),
                'used_vouchers' => $live->filter(fn ($v) => $v->status === 'used' || $v->balance + 0.004 < $v->value)->count(),
                'unused_vouchers' => $live->filter(fn ($v) => $v->status === 'active' && $v->balance + 0.004 >= $v->value)->count(),
                'exceptions' => $exceptions->count(), 'exceptions_value' => round((float) $exceptions->sum('amount'), 2),
            ],
            'contracts' => $this->perContract($vouchers, $uses),
            'branches' => $this->groupUses($uses, 'branch_id', Branch::query()->get(['id', 'name', 'name_en'])->mapWithKeys(fn ($b) => [$b->id => tr($b->name, $b->name_en)])),
            'cashiers' => $this->groupUses($uses, 'user_id', User::query()->pluck('name', 'id')),
            'days' => $this->days($uses),
            'expiring' => $this->expiring($live),
            'exceptions' => $exceptions->sortByDesc('id')->take(100)->map(fn (CorporateVoucherRedemption $r) => [
                'at' => $r->created_at, 'serial' => $r->voucher?->serial, 'amount' => $r->amount, 'reason' => $r->exception_reason,
                'approver' => $r->approver?->name, 'cashier' => $r->cashier?->name,
            ])->values()->all(),
        ];
    }

    /** @return \Generator<int,list<mixed>> one line per use, for the CSV / Excel export */
    public function rows(): \Generator
    {
        $uses = $this->uses()->with(['voucher:id,serial,corporate_contract_id', 'contract:id,name', 'order:id,number', 'branch:id,name,name_en', 'cashier:id,name', 'approver:id,name'])
            ->orderBy('id')->get();
        foreach ($uses as $r) {
            yield [local_time($r->created_at, 'Y-m-d H:i'), $r->contract?->name, $r->voucher?->serial, $r->branch ? tr($r->branch->name, $r->branch->name_en) : '',
                $r->order ? '#'.$r->order->number : __('Paper bill').($r->reference ? ' '.$r->reference : ''), $r->amount, $r->forfeited,
                $r->exception_reason ? $r->exception_reason.' · '.$r->approver?->name : '', $r->cashier?->name];
        }
    }

    /** Uses in the range that still count (not given back), with every filter. */
    private function uses(): Builder
    {
        return CorporateVoucherRedemption::query()->whereNull('refunded_at')
            ->whereBetween('created_at', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->when($this->cashierId, fn ($q) => $q->where('user_id', $this->cashierId))
            ->when($this->contractId, fn ($q) => $q->where('corporate_contract_id', $this->contractId))
            ->when($this->mode, fn ($q) => $q->whereIn('corporate_contract_id', CorporateContract::query()->where('mode', $this->mode)->select('id')));
    }

    /** Vouchers of the contracts in the filter (a voucher belongs to no branch or cashier); a voided contract's vouchers count as void. */
    private function vouchers(): Collection
    {
        $voided = CorporateContract::query()->where('status', 'void')->pluck('id')->flip();

        return CorporateVoucher::query()
            ->when($this->contractId, fn ($q) => $q->where('corporate_contract_id', $this->contractId))
            ->when($this->mode, fn ($q) => $q->whereIn('corporate_contract_id', CorporateContract::query()->where('mode', $this->mode)->select('id')))
            ->get(['id', 'corporate_contract_id', 'value', 'balance', 'status', 'expires_on', 'created_at'])
            ->each(function (CorporateVoucher $v) use ($voided) {
                if ($voided->has($v->corporate_contract_id)) {
                    $v->status = 'void';
                }
            });
    }

    /** Active vouchers whose last day fell inside the range (and is over) with value left on them: breakage. */
    private function expiredUnused(Collection $vouchers): Collection
    {
        $today = CarbonImmutable::today()->format('Y-m-d');
        $from = $this->from->format('Y-m-d');
        $to = $this->to->format('Y-m-d');

        return $vouchers->filter(function ($v) use ($today, $from, $to) {
            $day = $v->expires_on->format('Y-m-d');

            return $v->status === 'active' && $v->balance > 0 && $day < $today && $day >= $from && $day <= $to;
        });
    }

    /** @return list<array<string,mixed>> */
    private function perContract(Collection $vouchers, Collection $uses): array
    {
        $contracts = CorporateContract::query()
            ->when($this->contractId, fn ($q) => $q->whereKey($this->contractId))
            ->when($this->mode, fn ($q) => $q->where('mode', $this->mode))
            ->orderByDesc('id')->get();
        $today = CarbonImmutable::today()->format('Y-m-d');
        // all-time consumption for the usage share (value + what single mode lost), uses given back left out
        $consumed = CorporateVoucherRedemption::query()->whereNull('refunded_at')->whereIn('corporate_contract_id', $contracts->pluck('id'))
            ->get(['corporate_contract_id', 'amount', 'forfeited'])->groupBy('corporate_contract_id');

        return $contracts->map(function (CorporateContract $c) use ($vouchers, $uses, $today, $consumed) {
            $mine = $vouchers->where('corporate_contract_id', $c->id);
            $live = $mine->filter(fn ($v) => $v->status !== 'void');
            $used = $uses->where('corporate_contract_id', $c->id);
            $total = round((float) $live->sum('value'), 2);
            $all = $consumed->get($c->id, collect());
            $spent = round((float) $all->sum('amount') + (float) $all->sum('forfeited'), 2);

            return [
                'contract' => $c, 'vouchers' => $mine->count(), 'issued' => $total,
                'redeemed' => round((float) $used->sum('amount'), 2), 'lost' => round((float) $used->sum('forfeited'), 2),
                'remaining' => round((float) $live->filter(fn ($v) => $v->status === 'active' && $v->expires_on->format('Y-m-d') >= $today)->sum('balance'), 2),
                'expired' => round((float) $live->filter(fn ($v) => $v->status === 'active' && $v->expires_on->format('Y-m-d') < $today)->sum('balance'), 2),
                'usage' => $total > 0 ? (int) round(min(100, $spent * 100 / $total)) : 0,
            ];
        })->values()->all();
    }

    /** @return list<array{name:string, uses:int, amount:float, lost:float}> biggest first */
    private function groupUses(Collection $uses, string $field, Collection $names): array
    {
        return $uses->groupBy(fn ($r) => (string) ($r->{$field} ?? ''))
            ->map(fn ($g, $k) => ['name' => $names[$k] ?? '—', 'uses' => $g->count(), 'amount' => round((float) $g->sum('amount'), 2), 'lost' => round((float) $g->sum('forfeited'), 2)])
            ->sortByDesc('amount')->values()->all();
    }

    /** @return array<string,float> day => value redeemed */
    private function days(Collection $uses): array
    {
        $days = [];
        foreach (CarbonPeriod::create($this->from->startOfDay(), $this->to->startOfDay()) as $day) {
            $days[$day->format('Y-m-d')] = 0.0;
        }
        foreach ($uses as $r) {
            $key = $r->created_at->timezone(config('app.timezone'))->format('Y-m-d');
            if (isset($days[$key])) {
                $days[$key] = round($days[$key] + $r->amount, 2);
            }
        }

        return $days;
    }

    /** @return list<array{contract:CorporateContract, count:int, value:float, first:string}> contracts with vouchers ending in the next 30 days */
    private function expiring(Collection $vouchers): array
    {
        $today = CarbonImmutable::today();
        $soon = $vouchers->filter(fn ($v) => $v->status === 'active' && $v->balance > 0
            && $v->expires_on->format('Y-m-d') >= $today->format('Y-m-d') && $v->expires_on->format('Y-m-d') <= $today->addDays(self::EXPIRING_DAYS)->format('Y-m-d'));
        $contracts = CorporateContract::query()->whereIn('id', $soon->pluck('corporate_contract_id')->unique())->get()->keyBy('id');

        return $soon->groupBy('corporate_contract_id')->map(fn ($g, $id) => [
            'contract' => $contracts[$id], 'count' => $g->count(), 'value' => round((float) $g->sum('balance'), 2),
            'first' => $g->min(fn ($v) => $v->expires_on->format('Y-m-d')),
        ])->sortBy('first')->values()->all();
    }
}
