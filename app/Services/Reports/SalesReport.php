<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\CorporateVoucherRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\ShiftService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Sales figures for a date range (restaurant local time). Aggregated in PHP rather than with SQL date
 * functions so the same code runs on MySQL in production and SQLite in tests; ranges are capped at a year.
 * "Sales" = orders that were not cancelled.
 */
final class SalesReport
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $branchId = null,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $orders = $this->orders()->get(['id', 'branch_id', 'customer_id', 'status', 'type', 'source', 'payment_status', 'payment_method', 'corporate_amount', 'total', 'discount', 'created_at']);
        $sold = $orders->where('status', '!=', 'cancelled');
        $revenue = round((float) $sold->sum('total'), 2);

        $days = [];
        foreach (CarbonPeriod::create($this->from->startOfDay(), $this->to->startOfDay()) as $day) {
            $days[$day->format('Y-m-d')] = ['orders' => 0, 'revenue' => 0.0];
        }
        $hours = array_fill(0, 24, 0);
        foreach ($sold as $o) {
            $key = $o->created_at->format('Y-m-d');
            if (isset($days[$key])) {
                $days[$key]['orders']++;
                $days[$key]['revenue'] = round($days[$key]['revenue'] + $o->total, 2);
            }
            $hours[(int) $o->created_at->format('G')]++;
        }

        $customerIds = $sold->pluck('customer_id')->filter()->unique();
        $newCustomers = $customerIds->isEmpty() ? 0 : Customer::query()->whereIn('id', $customerIds)
            ->where('created_at', '>=', $this->from->startOfDay())->count();

        return [
            'orders' => $sold->count(),
            'revenue' => $revenue,
            'average' => $sold->count() ? round($revenue / $sold->count(), 2) : 0.0,
            'discounts' => round((float) $sold->sum('discount'), 2),
            'cancelled' => $orders->where('status', 'cancelled')->count(),
            'unpaid' => round((float) $sold->where('payment_status', 'unpaid')->sum('total'), 2),
            'days' => $days,
            'hours' => $hours,
            'by_method' => $this->byMethod($sold->where('payment_status', 'paid')),
            'by_source' => $this->share($sold, 'source'),
            'by_type' => $this->share($sold, 'type'),
            'by_branch' => $this->byBranch($sold),
            'top_items' => $this->topItems($sold->pluck('id')),
            'customers' => ['with_phone' => $customerIds->count(), 'new' => $newCustomers, 'returning' => $customerIds->count() - $newCustomers],
            'paper_vouchers' => $this->paperVouchers(),
        ];
    }

    /**
     * Corporate vouchers taken on bills written on paper ("صرف الفاوتشرات" screen, no order in the system): not in
     * the sales above, shown next to them so the vouchers' value is never missing from the day.
     *
     * @return array{count:int, total:float}
     */
    private function paperVouchers(): array
    {
        $rows = CorporateVoucherRedemption::query()->whereNull('order_id')->whereNotNull('bill_amount')->whereNull('refunded_at')
            ->whereBetween('created_at', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->get(['batch', 'amount']);

        return ['count' => $rows->pluck('batch')->unique()->count(), 'total' => round((float) $rows->sum('amount'), 2)];
    }

    /** Rows for the CSV export: one line per order. */
    public function rows(): \Generator
    {
        foreach ($this->orders()->with(['table', 'branch'])->orderBy('id')->cursor() as $o) {
            yield [$o->number, local_time($o->created_at, 'Y-m-d H:i'), $o->branch?->name, $o->type, $o->source, $o->table?->name,
                $o->status, $o->payment_status, $o->payment_method, $o->subtotal, $o->discount, $o->service, $o->tax, $o->total];
        }
    }

    private function orders()
    {
        return Order::query()->whereBetween('created_at', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId));
    }

    /** @return list<array{key:string, count:int, total:float}> biggest first */
    private function share($orders, string $field): array
    {
        return $orders->groupBy(fn ($o) => (string) ($o->{$field} ?? ''))
            ->map(fn ($g, $k) => ['key' => $k, 'count' => $g->count(), 'total' => round((float) $g->sum('total'), 2)])
            ->sortByDesc('total')->values()->all();
    }

    /**
     * Paid sales by payment method; an order paid partly by corporate vouchers counts under both of its methods, each
     * with its own part (ShiftService::split).
     *
     * @return list<array{key:string, count:int, total:float}> biggest first
     */
    private function byMethod($orders): array
    {
        $out = [];
        foreach ($orders as $o) {
            foreach (ShiftService::split($o) as $method => $amount) {
                $out[$method] ??= ['key' => $method, 'count' => 0, 'total' => 0.0];
                $out[$method]['count']++;
                $out[$method]['total'] = round($out[$method]['total'] + $amount, 2);
            }
        }

        return collect($out)->sortByDesc('total')->values()->all();
    }

    private function byBranch($orders): array
    {
        $names = Branch::query()->pluck('name', 'id');

        return collect($this->share($orders, 'branch_id'))->map(fn ($r) => $r + ['name' => $names[(int) $r['key']] ?? '—'])->all();
    }

    /** @return list<array{name:string, qty:int, revenue:float}> */
    private function topItems($orderIds): array
    {
        if ($orderIds->isEmpty()) {
            return [];
        }

        return OrderItem::query()->whereIn('order_id', $orderIds)
            ->selectRaw('name, SUM(quantity) as qty, SUM(line_total) as revenue')
            ->groupBy('name')->orderByDesc('qty')->limit(15)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => round((float) $r->revenue, 2)])->all();
    }
}
