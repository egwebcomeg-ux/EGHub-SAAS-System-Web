<?php

namespace App\Services\Menu;

use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\Category;
use App\Models\MenuItem;
use App\Services\Entitlements;

/**
 * The menu as one branch sees it: active categories/items with branch price, availability and sold-out overrides
 * applied (missing override = main menu). Used by the public menu and by the order service, so the price a guest
 * sees is the price the server charges.
 */
final class MenuCatalog
{
    /** @var array<int, BranchItem>|null */
    private ?array $overrides = null;

    public function __construct(public readonly Branch $branch)
    {
    }

    public static function forBranch(?Branch $branch): self
    {
        return new self($branch ?? self::defaultBranch());
    }

    public static function defaultBranch(): Branch
    {
        return Branch::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, Category> categories with only the items this branch offers */
    public function categories()
    {
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->with(['items' => fn ($q) => $q->where('is_active', true)->orderBy('id')->with('optionGroups.options')])
            ->get();

        foreach ($categories as $category) {
            $category->setRelation('items', $category->items->filter(fn (MenuItem $i) => $this->isAvailable($i))
                ->each(fn (MenuItem $i) => $this->apply($i))->values());
        }
        return $categories->filter(fn (Category $c) => $c->items->isNotEmpty())->values();
    }

    public function isAvailable(MenuItem $item): bool
    {
        if (!$item->is_active) {
            return false;
        }
        $o = $this->override($item);
        return !$o || $o->is_available;
    }

    /** Applies the branch price/sold-out override to the model in memory (never saved). */
    public function apply(MenuItem $item): MenuItem
    {
        if ($o = $this->override($item)) {
            if ($o->price !== null) {
                $item->price = $o->price;
            }
            $item->is_sold_out = $item->is_sold_out || $o->is_sold_out;
        }
        return $item;
    }

    private function override(MenuItem $item): ?BranchItem
    {
        if (!Entitlements::current()->allows('branch_pricing')) {
            return null;
        }
        $this->overrides ??= BranchItem::query()->where('branch_id', $this->branch->id)->get()->keyBy('menu_item_id')->all();
        return $this->overrides[$item->id] ?? null;
    }
}
