<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;

/**
 * Stock variance of one branch over a period, per ingredient or packaging item, read from the movement log: opening, purchases, sales
 * usage (what the orders' recipes took, net of cancelled orders and refunds: the theoretical use), waste, count
 * corrections and closing. Actual use is opening + purchases − closing; the part neither sales nor recorded waste
 * explain is the unexplained difference, which only a count reveals. Money is valued at each movement's unit cost.
 *
 * @phpstan-type Row array{ingredient: Ingredient, opening: float, purchases: float, purchases_cost: float, sales: float,
 *     sales_cost: float, waste: float, waste_cost: float, counts: float, closing: float, actual: float,
 *     unexplained: float, unexplained_cost: float}
 */
final class VarianceReport
{
    /** @param  string|null  $kind  only ingredients or only packaging (Ingredient::KINDS), null for both */
    public function __construct(private Branch $branch, private CarbonImmutable $from, private CarbonImmutable $to, private ?string $kind = null) {}

    /** @return list<Row> sorted by money lost, the biggest loss first */
    public function rows(): array
    {
        $start = local_day_start($this->from->toDateString());
        $end = local_day_end($this->to->toDateString());
        $period = StockMovement::query()->where('branch_id', $this->branch->id)->whereBetween('created_at', [$start, $end])
            ->groupBy('ingredient_id', 'type')->selectRaw('ingredient_id, type, SUM(quantity) as qty, SUM(quantity * unit_cost) as value')
            ->get()->groupBy('ingredient_id');
        $after = StockMovement::query()->where('branch_id', $this->branch->id)->where('created_at', '>', $end)
            ->groupBy('ingredient_id')->selectRaw('ingredient_id, SUM(quantity) as qty')->pluck('qty', 'ingredient_id');
        $stock = IngredientStock::query()->where('branch_id', $this->branch->id)->pluck('quantity', 'ingredient_id');

        $ids = $period->keys()->merge($stock->filter(fn ($q) => abs((float) $q) >= 0.0005)->keys())->unique();
        $rows = [];
        foreach (Ingredient::query()->whereIn('id', $ids)->when($this->kind, fn ($q) => $q->where('kind', $this->kind))->orderBy('name')->get() as $ingredient) {
            $moves = $period->get($ingredient->id, collect())->keyBy('type');
            $qty = fn (string ...$types) => array_sum(array_map(fn ($t) => (float) ($moves[$t]->qty ?? 0), $types));
            $value = fn (string ...$types) => array_sum(array_map(fn ($t) => (float) ($moves[$t]->value ?? 0), $types));

            $closing = (float) ($stock[$ingredient->id] ?? 0) - (float) ($after[$ingredient->id] ?? 0);
            $opening = $closing - $qty('purchase', 'sale', 'return', 'waste', 'count');
            $sales = -$qty('sale', 'return');
            $waste = -$qty('waste');
            $actual = $opening + $qty('purchase') - $closing;
            $rows[] = [
                'ingredient' => $ingredient,
                'opening' => round($opening, 3), 'purchases' => round($qty('purchase'), 3), 'purchases_cost' => round($value('purchase'), 2),
                'sales' => round($sales, 3), 'sales_cost' => round(-$value('sale', 'return'), 2),
                'waste' => round($waste, 3), 'waste_cost' => round(-$value('waste'), 2),
                'counts' => round($qty('count'), 3), 'closing' => round($closing, 3), 'actual' => round($actual, 3),
                'unexplained' => round($actual - $sales - $waste, 3), 'unexplained_cost' => round(-$value('count'), 2),
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['unexplained_cost'] <=> $a['unexplained_cost']);

        return $rows;
    }

    /**
     * @param  list<Row>  $rows
     * @return array{purchases: float, sales: float, waste: float, unexplained: float}
     */
    public static function totals(array $rows): array
    {
        $sum = fn (string $key) => round(array_sum(array_column($rows, $key)), 2);

        return ['purchases' => $sum('purchases_cost'), 'sales' => $sum('sales_cost'), 'waste' => $sum('waste_cost'), 'unexplained' => $sum('unexplained_cost')];
    }

    /**
     * The same totals for ingredients and for packaging apart.
     *
     * @param  list<Row>  $rows
     * @return array<string, array{purchases: float, sales: float, waste: float, unexplained: float}> kind => totals
     */
    public static function totalsByKind(array $rows): array
    {
        $out = [];
        foreach (array_keys(Ingredient::KINDS) as $kind) {
            $out[$kind] = self::totals(array_values(array_filter($rows, fn (array $r) => $r['ingredient']->kind === $kind)));
        }

        return $out;
    }

    /**
     * The report as spreadsheet rows (header first) for the CSV / Excel download.
     *
     * @param  list<Row>  $rows
     * @return list<list<mixed>>
     */
    public static function sheet(array $rows): array
    {
        $out = [[__('Ingredient'), __('Kind'), __('Unit'), __('Opening'), __('Purchases'), __('Sales usage (recipes)'), __('Waste'), __('Count corrections'),
            __('Closing'), __('Actual usage'), __('Unexplained difference'), __('Unexplained (money)'), __('Unit cost')]];
        foreach ($rows as $r) {
            $out[] = [$r['ingredient']->name, $r['ingredient']->kindLabel(), $r['ingredient']->unitLabel(), $r['opening'], $r['purchases'], $r['sales'], $r['waste'], $r['counts'],
                $r['closing'], $r['actual'], $r['unexplained'], $r['unexplained_cost'], $r['ingredient']->cost_per_unit];
        }

        return $out;
    }
}
