<?php

namespace App\Services\Reports;

use App\Billing\PaymentMethods;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PosTerminal;
use App\Models\Shift;
use App\Services\Orders\ShiftService;
use Carbon\CarbonImmutable;

/**
 * End-of-day (Z) report of one branch, optionally one terminal, for one day (restaurant time): sales by payment
 * method, refunds, discounts, voids / cancels, cash in / out, each shift's expected and counted cash, and the sales
 * that were made offline and synced. Sales are counted on the day they happened (an offline sale's own time).
 */
final class ZReport
{
    public function __construct(
        public readonly CarbonImmutable $day,
        public readonly int $branchId,
        public readonly ?int $terminalId = null,
    ) {}

    /**
     * @return array{orders:int, gross:float, discounts:float, service:float, tax:float, refunds:array{count:int, total:float, by_method:array<string,float>},
     *               net:float, by_method:array<string,float>, net_by_method:array<string,float>, cancelled:array{count:int, total:float},
     *               unpaid:array{count:int, total:float}, cash:array{in:float, out:float, expenses:float},
     *               shifts:list<array<string,mixed>>, offline:array{count:int, total:float, review:int}, terminals:list<array{name:string, orders:int, total:float}>}
     */
    public function build(): array
    {
        [$from, $to] = [local_day_start($this->day->toDateString()), local_day_end($this->day->toDateString())];
        $orders = Order::query()->where('branch_id', $this->branchId)->whereBetween('created_at', [$from, $to])
            ->when($this->terminalId, fn ($q) => $q->where('terminal_id', $this->terminalId))
            ->get(['id', 'status', 'payment_status', 'payment_method', 'payments', 'corporate_amount', 'subtotal', 'discount', 'service', 'tax', 'total', 'offline_ref', 'needs_review', 'terminal_id']);
        $sold = $orders->where('status', '!=', 'cancelled');
        $paid = $sold->where('payment_status', 'paid');

        $byMethod = [];
        foreach ($paid as $o) {
            foreach (ShiftService::split($o) as $method => $amount) {
                $byMethod[$method] = round(($byMethod[$method] ?? 0) + $amount, 2);
            }
        }
        $refundRows = OrderRefund::query()->where('branch_id', $this->branchId)->whereBetween('created_at', [$from, $to])
            ->when($this->terminalId, fn ($q) => $q->where('terminal_id', $this->terminalId))->get(['amount', 'methods']);
        $refunds = [];
        foreach ($refundRows as $r) {
            foreach ((array) $r->methods as $method => $amount) {
                $refunds[$method] = round(($refunds[$method] ?? 0) + (float) $amount, 2);
            }
        }
        $net = $byMethod;
        foreach ($refunds as $method => $amount) {
            $net[$method] = round(($net[$method] ?? 0) - $amount, 2);
        }
        $gross = round((float) $paid->sum('total'), 2);
        $refundTotal = round((float) $refundRows->sum('amount'), 2);

        $shifts = Shift::query()->where('branch_id', $this->branchId)
            ->when($this->terminalId, fn ($q) => $q->where('terminal_id', $this->terminalId))
            ->where(fn ($q) => $q->whereBetween('opened_at', [$from, $to])->orWhereBetween('closed_at', [$from, $to])
                ->orWhere(fn ($q) => $q->where('opened_at', '<', $from)->whereNull('closed_at')))
            ->with(['user', 'terminal'])->orderBy('opened_at')->get();
        $movements = CashMovement::query()->whereIn('shift_id', $shifts->pluck('id'))->whereIn('type', ['in', 'out'])->whereBetween('created_at', [$from, $to])
            ->get(['type', 'category', 'amount']);
        $service = app(ShiftService::class);

        $terminals = [];
        if (! $this->terminalId) {
            $names = PosTerminal::query()->where('branch_id', $this->branchId)->get()->keyBy('id');
            foreach ($paid->groupBy(fn ($o) => (int) $o->terminal_id) as $id => $group) {
                $terminals[] = ['name' => $id ? ($names[$id]?->label() ?? '#'.$id) : __('No terminal'), 'orders' => $group->count(), 'total' => round((float) $group->sum('total'), 2)];
            }
        }
        $offline = $paid->whereNotNull('offline_ref');

        return [
            'orders' => $paid->count(), 'gross' => $gross,
            'discounts' => round((float) $paid->sum('discount'), 2), 'service' => round((float) $paid->sum('service'), 2), 'tax' => round((float) $paid->sum('tax'), 2),
            'refunds' => ['count' => $refundRows->count(), 'total' => $refundTotal, 'by_method' => $refunds],
            'net' => round($gross - $refundTotal, 2), 'by_method' => $byMethod, 'net_by_method' => $net,
            'cancelled' => ['count' => $orders->where('status', 'cancelled')->count(), 'total' => round((float) $orders->where('status', 'cancelled')->sum('total'), 2)],
            'unpaid' => ['count' => $sold->where('payment_status', '!=', 'paid')->count(), 'total' => round((float) $sold->where('payment_status', '!=', 'paid')->sum('total'), 2)],
            'cash' => [
                'in' => round((float) $movements->where('type', 'in')->sum('amount'), 2), 'out' => round((float) $movements->where('type', 'out')->sum('amount'), 2),
                'expenses' => round((float) $movements->where('type', 'out')->where('category', 'expense')->sum('amount'), 2),
            ],
            'shifts' => $shifts->map(function (Shift $s) use ($service) {
                $summary = $service->summary($s);
                $expected = $s->expected_cash ?? $summary['expected_cash'];

                return [
                    'id' => $s->id, 'user' => $s->user?->name, 'terminal' => $s->terminal?->label(), 'opened_at' => $s->opened_at, 'closed_at' => $s->closed_at,
                    'opening' => $s->opening_cash, 'expected' => $expected, 'counted' => $s->closing_cash,
                    'difference' => $s->closing_cash !== null ? round($s->closing_cash - $expected, 2) : null,
                ];
            })->values()->all(),
            'offline' => ['count' => $offline->count(), 'total' => round((float) $offline->sum('total'), 2), 'review' => $offline->where('needs_review', true)->count()],
            'terminals' => $terminals,
        ];
    }

    /** @return list<list<mixed>> the report as rows (CSV / Excel) */
    public function rows(array $z): array
    {
        $rows = [[__('Figure'), __('Value')], [__('Paid orders'), $z['orders']], [__('Sales'), $z['gross']], [__('Discounts'), $z['discounts']],
            [__('Service'), $z['service']], [__('Tax'), $z['tax']], [__('Refunds'), $z['refunds']['total']], [__('Net sales'), $z['net']],
            [__('Cancelled orders'), $z['cancelled']['count']], [__('Cancelled value'), $z['cancelled']['total']], [__('Not paid yet'), $z['unpaid']['total']],
            [__('Cash in'), $z['cash']['in']], [__('Cash out'), $z['cash']['out']], [__('Of it expenses'), $z['cash']['expenses']],
            [__('Offline sales synced'), $z['offline']['count']], [__('Offline sales value'), $z['offline']['total']], [''],
            [__('Payment method'), __('Sales'), __('Refunds'), __('Net')]];
        foreach (array_unique(array_merge(array_keys($z['by_method']), array_keys($z['refunds']['by_method']))) as $m) {
            $rows[] = [PaymentMethods::label($m), $z['by_method'][$m] ?? 0, $z['refunds']['by_method'][$m] ?? 0, $z['net_by_method'][$m] ?? 0];
        }
        $rows[] = [''];
        $rows[] = [__('Cashier'), __('Terminal'), __('From'), __('To'), __('Opening cash'), __('Expected cash'), __('Counted'), __('Difference')];
        foreach ($z['shifts'] as $s) {
            $rows[] = [$s['user'], $s['terminal'] ?? '', local_time($s['opened_at'], 'Y-m-d H:i'), $s['closed_at'] ? local_time($s['closed_at'], 'Y-m-d H:i') : '',
                $s['opening'], $s['expected'], $s['counted'] ?? '', $s['difference'] ?? ''];
        }

        return $rows;
    }
}
