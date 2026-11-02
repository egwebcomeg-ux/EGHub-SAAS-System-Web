<?php

namespace App\Services\Reports;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\StockMovement;
use App\Services\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the restaurant kept in a period: sales net of refunds and of the tax collected, less the cost of what was sold
 * and wasted (only with the inventory pack and recipes, so `costed` says whether it is counted), the expenses written
 * down, and the expenses paid from the shift drawer. Staff tied to a branch see that branch, without the expenses of
 * the whole restaurant.
 */
final class ProfitReport
{
    public function __construct(
        private CarbonImmutable $from,
        private CarbonImmutable $to,
        private ?int $branchId = null,
    ) {}

    /** @return array{sales:float, tax:float, cost_of_sales:float, waste:float, costed:bool, expenses:float, by_category:array<string,float>, drawer:float, net:float} */
    public function build(): array
    {
        $report = (new SalesReport($this->from, $this->to, $this->branchId))->build();
        $range = [local_day_start($this->from->toDateString()), local_day_end($this->to->toDateString())];
        $costed = Entitlements::current()->allows('inventory');
        $stock = fn (array $types) => $costed ? round(-(float) StockMovement::query()->whereIn('type', $types)->whereBetween('created_at', $range)
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))->sum(DB::raw('quantity * unit_cost')), 2) : 0.0;
        $costOfSales = $stock(['sale', 'return']);
        $waste = $stock(['waste']);
        $byCategory = Expense::query()->whereBetween('spent_on', [$this->from->toDateString(), $this->to->toDateString()])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->groupBy('category')->selectRaw('category, SUM(amount) as total')->pluck('total', 'category')
            ->map(fn ($total) => round((float) $total, 2))->all();
        $expenses = round(array_sum($byCategory), 2);
        $drawer = round((float) CashMovement::query()->where('type', 'out')->where('category', 'expense')->whereBetween('created_at', $range)
            ->when($this->branchId, fn ($q) => $q->whereHas('shift', fn ($s) => $s->where('branch_id', $this->branchId)))->sum('amount'), 2);

        return [
            'sales' => $report['revenue'], 'tax' => $report['tax'], 'cost_of_sales' => $costOfSales, 'waste' => $waste, 'costed' => $costed,
            'expenses' => $expenses, 'by_category' => $byCategory, 'drawer' => $drawer,
            'net' => round($report['revenue'] - $report['tax'] - $costOfSales - $waste - $expenses - $drawer, 2),
        ];
    }
}
