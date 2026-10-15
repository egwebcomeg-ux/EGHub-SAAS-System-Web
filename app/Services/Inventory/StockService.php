<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RecipeLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Every stock change goes through here and leaves a StockMovement. An order deducts its recipes once
 * (orders.stock_deducted_at) and gives them back once if it is cancelled.
 */
class StockService
{
    public function enabled(): bool
    {
        return Entitlements::current()->allows('inventory');
    }

    /** Takes the order's ingredients out of its branch stock. Safe to call twice. */
    public function deductForOrder(Order $order): void
    {
        if (! $this->enabled() || $order->stock_deducted_at) {
            return;
        }
        DB::transaction(function () use ($order) {
            foreach ($this->usage($order) as $ingredientId => $qty) {
                $this->move($ingredientId, $order->branch_id, 'sale', -$qty, null, $order->id);
            }
            $order->forceFill(['stock_deducted_at' => now()])->save();
        });
    }

    /** Cancelled order: puts back exactly what was taken for it. */
    public function restoreForOrder(Order $order): void
    {
        if (! $order->stock_deducted_at) {
            return;
        }
        DB::transaction(function () use ($order) {
            $taken = StockMovement::query()->where('order_id', $order->id)->whereIn('type', ['sale', 'return'])
                ->selectRaw('ingredient_id, SUM(quantity) as qty')->groupBy('ingredient_id')->pluck('qty', 'ingredient_id');
            foreach ($taken as $ingredientId => $qty) {
                if ((float) $qty < 0) {
                    $this->move((int) $ingredientId, $order->branch_id, 'return', -(float) $qty, null, $order->id);
                }
            }
            $order->forceFill(['stock_deducted_at' => null])->save();
        });
    }

    /** Goods received: adds stock and moves the average cost towards the price paid. */
    public function purchase(Ingredient $ingredient, Branch $branch, float $qty, float $totalCost, ?User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($ingredient, $branch, $qty, $totalCost, $by, $note) {
            $onHand = max(0.0, (float) IngredientStock::query()->where('ingredient_id', $ingredient->id)->sum('quantity'));
            $unitCost = $qty > 0 ? $totalCost / $qty : 0.0;
            if ($qty > 0 && $totalCost > 0) {
                $ingredient->cost_per_unit = round(($onHand * $ingredient->cost_per_unit + $totalCost) / ($onHand + $qty), 4);
                $ingredient->save();
            }
            $this->move($ingredient->id, $branch->id, 'purchase', $qty, $by, null, $note, $unitCost);
        });
    }

    public function waste(Ingredient $ingredient, Branch $branch, float $qty, ?User $by, ?string $note = null): void
    {
        $this->move($ingredient->id, $branch->id, 'waste', -abs($qty), $by, null, $note);
    }

    /** Physical count: records the difference so the stock equals what is on the shelf. */
    public function count(Ingredient $ingredient, Branch $branch, float $actual, ?User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($ingredient, $branch, $actual, $by, $note) {
            $current = (float) (IngredientStock::query()->where('ingredient_id', $ingredient->id)->where('branch_id', $branch->id)
                ->lockForUpdate()->value('quantity') ?? 0);
            if (abs($actual - $current) >= 0.0005) {
                $this->move($ingredient->id, $branch->id, 'count', round($actual - $current, 3), $by, null, $note);
            }
        });
    }

    /** Ingredient cost of one plain portion (no options). */
    public function itemCost(MenuItem $item): float
    {
        return round((float) RecipeLine::query()->where('menu_item_id', $item->id)->whereNull('item_option_id')
            ->join('ingredients', 'ingredients.id', '=', 'recipe_lines.ingredient_id')
            ->sum(DB::raw('recipe_lines.quantity * ingredients.cost_per_unit')), 2);
    }

    /** @return array<int,float> ingredient id => quantity used by the whole order */
    private function usage(Order $order): array
    {
        $order->loadMissing('items.menuItem.comboParts');
        $itemIds = $order->items->pluck('menu_item_id')
            ->merge($order->items->flatMap(fn ($i) => $i->menuItem?->comboParts->pluck('id') ?? []))->unique()->all();
        $recipes = RecipeLine::query()->whereIn('menu_item_id', $itemIds)->get()->groupBy('menu_item_id');

        $use = [];
        $add = function (int $menuItemId, float $times, array $optionIds) use ($recipes, &$use) {
            foreach ($recipes->get($menuItemId, collect()) as $line) {
                if ($line->item_option_id === null || in_array($line->item_option_id, $optionIds, true)) {
                    $use[$line->ingredient_id] = ($use[$line->ingredient_id] ?? 0) + $line->quantity * $times;
                }
            }
        };
        foreach ($order->items as $orderItem) {
            $optionIds = array_values(array_filter(array_map(fn ($o) => (int) ($o['id'] ?? 0), $orderItem->options ?? [])));
            $add((int) $orderItem->menu_item_id, (float) $orderItem->quantity, $optionIds);
            foreach ($orderItem->menuItem?->comboParts ?? [] as $part) {
                $add($part->id, $orderItem->quantity * max(1, (int) $part->pivot->quantity), []);
            }
        }

        return array_map(fn ($q) => round($q, 3), array_filter($use, fn ($q) => $q > 0));
    }

    private function move(int $ingredientId, int $branchId, string $type, float $qty, ?User $by = null, ?int $orderId = null, ?string $note = null, ?float $unitCost = null): void
    {
        DB::transaction(function () use ($ingredientId, $branchId, $type, $qty, $by, $orderId, $note, $unitCost) {
            $stock = IngredientStock::query()->where('ingredient_id', $ingredientId)->where('branch_id', $branchId)->lockForUpdate()->first()
                ?? IngredientStock::query()->create(['ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'quantity' => 0]);
            $stock->quantity = round($stock->quantity + $qty, 3);
            $stock->save();
            StockMovement::query()->create([
                'ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'type' => $type, 'quantity' => round($qty, 3),
                'unit_cost' => $unitCost ?? (float) Ingredient::query()->whereKey($ingredientId)->value('cost_per_unit'),
                'order_id' => $orderId, 'user_id' => $by?->id, 'note' => $note ? mb_substr($note, 0, 200) : null,
            ]);
        });
    }
}
