<?php

namespace App\Services\Menu;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "الأكثر طلباً" on the public menu: the items this branch really sold most in the last DAYS days, counted as the
 * number of orders (not cancelled) that had the item, an item needing at least MIN_ORDERS of them. Free gift lines
 * (line total 0) do not count. The ranking is cached for CACHE_SECONDS per restaurant and branch; what is on the
 * menu right now (available, not sold out) is checked on every page view. With fewer than MIN_ITEMS such items
 * the menu keeps its "Recommended" row (the owner's featured items) instead. No count is ever shown to guests.
 */
final class BestSellers
{
    public const DAYS = 7;

    public const MIN_ORDERS = 3;

    public const MIN_ITEMS = 2;

    public const MAX_ITEMS = 6;

    public const CACHE_SECONDS = 600;

    /** How many ranked ids are kept in the cache, so items that left the menu can be skipped. */
    private const RANKED = 20;

    /**
     * Item id => number of orders, best first, for the branch (cached).
     *
     * @return array<int, int>
     */
    public static function ranking(Branch $branch): array
    {
        return Cache::remember(self::cacheKey($branch), self::CACHE_SECONDS, fn () => self::count($branch));
    }

    /**
     * The best sellers to show: on the menu now, not sold out, up to MAX_ITEMS; empty when fewer than MIN_ITEMS.
     *
     * @param  Collection<int, MenuItem>  $available  items on the menu now, keyed by id
     * @return Collection<int, MenuItem>
     */
    public static function forMenu(Branch $branch, Collection $available): Collection
    {
        $items = collect(array_keys(self::ranking($branch)))
            ->map(fn (int $id) => $available->get($id))
            ->filter(fn (?MenuItem $item) => $item !== null && ! $item->is_sold_out)
            ->take(self::MAX_ITEMS)
            ->values();

        return $items->count() >= self::MIN_ITEMS ? $items : collect();
    }

    public static function cacheKey(Branch $branch): string
    {
        return 'menu.best-sellers.'.tenant()->getTenantKey().'.'.$branch->id;
    }

    /** @return array<int, int> */
    private static function count(Branch $branch): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', tenant()->getTenantKey())
            ->where('orders.branch_id', $branch->id)
            ->where('orders.status', '!=', 'cancelled')
            ->where('orders.created_at', '>=', now()->subDays(self::DAYS))
            ->whereNotNull('order_items.menu_item_id')
            ->where('order_items.line_total', '>', 0)
            ->groupBy('order_items.menu_item_id')
            ->havingRaw('COUNT(DISTINCT order_items.order_id) >= ?', [self::MIN_ORDERS])
            ->orderByRaw('COUNT(DISTINCT order_items.order_id) DESC')
            ->orderBy('order_items.menu_item_id')
            ->limit(self::RANKED)
            ->selectRaw('order_items.menu_item_id AS item_id, COUNT(DISTINCT order_items.order_id) AS orders_count')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->item_id => (int) $row->orders_count])
            ->all();
    }
}
