<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PackagingRule;
use App\Models\Purchase;
use App\Models\RecipeLine;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Every stock change goes through here and leaves a StockMovement. An order deducts its recipes once
 * (orders.stock_deducted_at), with their takeaway-only packaging lines and the per-order packaging rules when it
 * leaves the place, and gives them back once if it is cancelled. Purchase invoices and count sheets are posted here
 * too, once each.
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

    /**
     * Some lines came back (a partial refund): puts back their share of what the order took. Only for an order whose
     * stock was deducted. @param array<int,int> $returned order item id => quantity returned
     */
    public function restoreLines(Order $order, array $returned): bool
    {
        if (! $order->stock_deducted_at || $returned === []) {
            return false;
        }
        $order->loadMissing('items.menuItem.comboParts');
        $lines = $order->items->filter(fn ($i) => ($returned[$i->id] ?? 0) > 0)
            ->map(fn ($i) => (clone $i)->setAttribute('quantity', min($i->quantity, (int) $returned[$i->id])));
        if ($lines->isEmpty()) {
            return false;
        }
        $partial = (clone $order)->setRelation('items', $lines->values());
        DB::transaction(function () use ($order, $partial) {
            foreach ($this->usage($partial, false) as $ingredientId => $qty) {
                $this->move($ingredientId, $order->branch_id, 'return', $qty, null, $order->id, 'refund');
            }
        });

        return true;
    }

    /** Goods received: adds stock and moves the average cost towards the price paid (see postPurchase). */
    public function purchase(Ingredient $ingredient, Branch $branch, float $qty, float $totalCost, ?User $by, ?string $note = null, ?Purchase $purchase = null): void
    {
        DB::transaction(function () use ($ingredient, $branch, $qty, $totalCost, $by, $note, $purchase) {
            $onHand = max(0.0, (float) IngredientStock::query()->where('ingredient_id', $ingredient->id)->sum('quantity'));
            $unitCost = $qty > 0 ? $totalCost / $qty : 0.0;
            if ($qty > 0 && $totalCost > 0) {
                $ingredient->cost_per_unit = round(($onHand * $ingredient->cost_per_unit + $totalCost) / ($onHand + $qty), 4);
                $ingredient->save();
            }
            $this->move($ingredient->id, $branch->id, 'purchase', $qty, $by, null, $note, $unitCost, ['purchase_id' => $purchase?->id]);
        });
    }

    /**
     * Posts a draft purchase invoice: every line is received into the purchase's branch. Cost method: weighted
     * average. The ingredient's cost becomes (on hand in all branches × old cost + amount paid) / (on hand + quantity
     * bought), stock under zero counting as none, so one cheap or dear delivery moves the cost by its share of the
     * stock instead of replacing it. Returns false (and changes nothing) unless the purchase is still a draft.
     */
    public function postPurchase(Purchase $purchase, User $by): bool
    {
        return DB::transaction(function () use ($purchase, $by) {
            $locked = Purchase::query()->with('supplier:id,name')->lockForUpdate()->find($purchase->id);
            if (! $locked?->isDraft() || ! $locked->lines()->exists()) {
                return false;
            }
            $branch = Branch::query()->findOrFail($locked->branch_id);
            $note = trim($locked->supplier?->name.' '.$locked->reference);
            foreach ($locked->lines()->orderBy('id')->get() as $line) {
                $ingredient = Ingredient::query()->lockForUpdate()->findOrFail($line->ingredient_id);
                $this->purchase($ingredient, $branch, $line->quantity, $line->quantity * $line->unit_cost, $by, $note, $locked);
            }
            $locked->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $by->id])->save();
            $purchase->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Cancels a posted purchase (a manager's call): its goods leave the branch again as negative `purchase` movements
     * and, while the stock left allows it, the weighted-average cost is moved back by the same share it moved when
     * posted. Returns false unless the purchase is posted.
     */
    public function cancelPurchase(Purchase $purchase, User $by, string $reason): bool
    {
        return DB::transaction(function () use ($purchase, $by, $reason) {
            $locked = Purchase::query()->with('supplier:id,name')->lockForUpdate()->find($purchase->id);
            if (! $locked?->isPosted()) {
                return false;
            }
            $note = trim(__('Cancelled').': '.$locked->supplier?->name.' '.$locked->reference);
            foreach ($locked->lines()->orderBy('id')->get() as $line) {
                $ingredient = Ingredient::query()->lockForUpdate()->findOrFail($line->ingredient_id);
                $onHand = max(0.0, (float) IngredientStock::query()->where('ingredient_id', $ingredient->id)->sum('quantity'));
                $paid = $line->quantity * $line->unit_cost;
                $left = $onHand - $line->quantity;
                if ($left > 0 && $paid > 0 && ($cost = ($onHand * $ingredient->cost_per_unit - $paid) / $left) >= 0) {
                    $ingredient->forceFill(['cost_per_unit' => round($cost, 4)])->save();
                }
                $this->move($ingredient->id, $locked->branch_id, 'purchase', -$line->quantity, $by, null, $note, $line->unit_cost, ['purchase_id' => $locked->id]);
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => mb_substr($reason, 0, 200)])->save();
            $purchase->setRawAttributes($locked->getAttributes(), true);

            return true;
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

    /**
     * Saves what staff counted on a draft sheet (line id => quantity, '' or null = not counted). A line whose quantity
     * changes also takes the branch's system quantity of that moment as its expected one. Returns the lines changed.
     *
     * @param  array<int|string, mixed>  $counted
     */
    public function saveCount(StockCount $count, array $counted): int
    {
        if (! $count->isDraft()) {
            return 0;
        }
        $stock = IngredientStock::query()->where('branch_id', $count->branch_id)->pluck('quantity', 'ingredient_id');
        $changed = 0;
        foreach ($count->lines()->get() as $line) {
            if (! array_key_exists($line->id, $counted)) {
                continue;
            }
            $value = $counted[$line->id];
            $qty = $value === null || $value === '' ? null : round((float) $value, 3);
            if ($qty === $line->counted) {
                continue;
            }
            $line->forceFill([
                'counted' => $qty, 'counted_at' => $qty === null ? null : now(),
                'expected' => $qty === null ? null : round((float) ($stock[$line->ingredient_id] ?? 0), 3),
            ])->save();
            $changed++;
        }

        return $changed;
    }

    /**
     * Posts a draft count sheet (a manager's call): each counted line moves the branch stock by counted − expected,
     * the system quantity when it was counted, so what was sold or returned between counting and posting stays
     * counted. Blank lines are skipped. Returns false (and changes nothing) unless the sheet is still a draft.
     */
    public function postCount(StockCount $count, User $by): bool
    {
        return DB::transaction(function () use ($count, $by) {
            $locked = StockCount::query()->lockForUpdate()->find($count->id);
            if (! $locked?->isDraft()) {
                return false;
            }
            $costs = Ingredient::query()->whereIn('id', $locked->lines()->select('ingredient_id'))->pluck('cost_per_unit', 'id');
            $locked->lines()->whereNotNull('counted')->orderBy('id')->get()->each(function (StockCountLine $line) use ($locked, $by, $costs) {
                $delta = round($line->counted - (float) $line->expected, 3);
                if (abs($delta) >= 0.0005) {
                    $this->move($line->ingredient_id, $locked->branch_id, 'count', $delta, $by, null, $locked->note, null, ['stock_count_id' => $locked->id]);
                }
                $line->forceFill(['adjustment' => $delta, 'unit_cost' => (float) ($costs[$line->ingredient_id] ?? 0)])->save();
            });
            $locked->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $by->id])->save();
            $count->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** Food cost percentage above which a menu item is flagged (setting food_cost_target, 35 by default). */
    public function foodCostTarget(): float
    {
        $target = (float) Settings::get('food_cost_target');

        return $target > 0 && $target <= 100 ? $target : 35.0;
    }

    /** Ingredient cost of one plain portion (no options); with $takeaway its takeaway-only packaging lines too. */
    public function itemCost(MenuItem $item, bool $takeaway = false): float
    {
        return round((float) RecipeLine::query()->where('menu_item_id', $item->id)->whereNull('item_option_id')
            ->when(! $takeaway, fn ($q) => $q->where('recipe_lines.takeaway_only', false))
            ->join('ingredients', 'ingredients.id', '=', 'recipe_lines.ingredient_id')
            ->sum(DB::raw('recipe_lines.quantity * ingredients.cost_per_unit')), 2);
    }

    /** @return array<string,float> order type (takeaway, delivery) => cost of the packaging every such order takes */
    public function orderPackagingCosts(): array
    {
        $costs = PackagingRule::query()->join('ingredients', 'ingredients.id', '=', 'packaging_rules.ingredient_id')
            ->groupBy('packaging_rules.order_type')->selectRaw('packaging_rules.order_type, SUM(packaging_rules.quantity * ingredients.cost_per_unit) as cost')
            ->pluck('cost', 'order_type');

        $out = [];
        foreach (array_keys(PackagingRule::ORDER_TYPES) as $type) {
            $out[$type] = round((float) ($costs[$type] ?? 0), 2);
        }

        return $out;
    }

    /**
     * @param  bool  $withOrderPackaging  false for a partial refund: the bag of the order is not given back per line
     * @return array<int,float> ingredient id => quantity used by the whole order
     */
    private function usage(Order $order, bool $withOrderPackaging = true): array
    {
        $order->loadMissing('items.menuItem.comboParts');
        $itemIds = $order->items->pluck('menu_item_id')
            ->merge($order->items->flatMap(fn ($i) => $i->menuItem?->comboParts->pluck('id') ?? []))->unique()->all();
        $recipes = RecipeLine::query()->whereIn('menu_item_id', $itemIds)->get()->groupBy('menu_item_id');
        $leavesPlace = $order->type !== 'dine_in';

        $use = [];
        $add = function (int $menuItemId, float $times, array $optionIds) use ($recipes, $leavesPlace, &$use) {
            foreach ($recipes->get($menuItemId, collect()) as $line) {
                if ($line->takeaway_only && ! $leavesPlace) {
                    continue;
                }
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
        if ($withOrderPackaging && isset(PackagingRule::ORDER_TYPES[$order->type]) && $order->items->isNotEmpty()) {
            foreach (PackagingRule::query()->where('order_type', $order->type)->get() as $rule) {
                $use[$rule->ingredient_id] = ($use[$rule->ingredient_id] ?? 0) + $rule->quantity;
            }
        }

        return array_map(fn ($q) => round($q, 3), array_filter($use, fn ($q) => $q > 0));
    }

    /** @param array{purchase_id?: ?int, stock_count_id?: ?int} $links the purchase invoice or count sheet behind it */
    private function move(int $ingredientId, int $branchId, string $type, float $qty, ?User $by = null, ?int $orderId = null, ?string $note = null, ?float $unitCost = null, array $links = []): void
    {
        DB::transaction(function () use ($ingredientId, $branchId, $type, $qty, $by, $orderId, $note, $unitCost, $links) {
            $stock = IngredientStock::query()->where('ingredient_id', $ingredientId)->where('branch_id', $branchId)->lockForUpdate()->first()
                ?? IngredientStock::query()->create(['ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'quantity' => 0]);
            $stock->quantity = round($stock->quantity + $qty, 3);
            $stock->save();
            StockMovement::query()->create([
                'ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'type' => $type, 'quantity' => round($qty, 3),
                'unit_cost' => $unitCost ?? (float) Ingredient::query()->whereKey($ingredientId)->value('cost_per_unit'),
                'order_id' => $orderId, 'user_id' => $by?->id, 'note' => $note ? mb_substr($note, 0, 200) : null,
            ] + array_filter($links));
        });
    }
}
