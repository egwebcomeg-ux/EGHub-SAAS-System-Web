<?php

namespace App\Services\Menu;

use App\Models\Branch;
use App\Models\BranchItem;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Offer;
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

    /** @var \Illuminate\Support\Collection<int, Offer>|null offers running right now in this branch */
    private $offers = null;

    private bool $withOffers = true;

    public function __construct(public readonly Branch $branch)
    {
    }

    /** Regular prices only: for the printed menu, where a happy-hour price would be wrong most of the day. */
    public function withoutOffers(): self
    {
        $this->withOffers = false;
        return $this;
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
            ->with(['items' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->with(['optionGroups.options', 'pairings', 'comboParts'])])
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

    /**
     * Applies the branch override and a running timed offer to the model in memory (never saved).
     * With an offer, `offer_from` keeps the price before it and `offer_percent` the discount.
     */
    public function apply(MenuItem $item): MenuItem
    {
        if ($item->getAttribute('catalog_applied')) {
            return $item;
        }
        if ($o = $this->override($item)) {
            if ($o->price !== null) {
                $item->price = $o->price;
            }
            $item->is_sold_out = $item->is_sold_out || $o->is_sold_out;
        }
        if ($percent = $this->offerPercent($item)) {
            $item->setAttribute('offer_from', (float) $item->price);
            $item->setAttribute('offer_percent', $percent);
            $item->price = round($item->price * (100 - $percent) / 100, 2);
        }
        $item->setAttribute('catalog_applied', true);
        return $item;
    }

    /** Best running offer for the item (whole-menu or its category), 0 when none. */
    public function offerPercent(MenuItem $item): int
    {
        if (!$this->withOffers || !Entitlements::current()->allows('offers')) {
            return 0;
        }
        $this->offers ??= Offer::query()->where('is_active', true)->get()->filter(fn (Offer $o) => $o->runsAt(now(), $this->branch->id))->values();
        return (int) $this->offers->filter(fn (Offer $o) => $o->category_id === null || $o->category_id === $item->category_id)->max('discount_percent');
    }

    /** @return \Illuminate\Support\Collection<int, Offer> */
    public function runningOffers()
    {
        $this->offerPercent(new MenuItem());
        return $this->offers ?? collect();
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
