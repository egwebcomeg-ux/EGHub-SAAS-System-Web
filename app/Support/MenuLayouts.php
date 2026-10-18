<?php

namespace App\Support;

use App\Models\Category;
use App\Models\MenuItem;
use App\Services\Entitlements;

/**
 * How the public menu lays its items out, apart from the colors of the theme (App\Support\MenuThemes).
 *
 * The owner picks a layout for the whole menu (setting menu_layout) and may override it per category
 * (categories.menu_layout, null = follow the menu). "auto" keeps each theme's own layout, as before this setting
 * existed; in "auto" a category without a single photo reads as a price list only when the photo placeholders are
 * off (with placeholders on it keeps the theme's tiles). Choosing a layout needs the plan feature menu_themes,
 * like the theme itself; the price-list fallback of "auto" works on every plan.
 *
 * Every layout reuses an item blade of resources/views/menu/items: list = classic (smaller photo), grid = grid,
 * cards = grid (one wide photo card per row, with the description), text = minimal without any image.
 */
final class MenuLayouts
{
    /** Values of the menu setting. */
    public const CHOICES = ['auto', 'list', 'grid', 'cards', 'text'];

    /** Values a category may override with (null = the menu setting). */
    public const OVERRIDES = ['list', 'grid', 'cards', 'text'];

    /**
     * Resolved layout => [item blade, wrapper classes, extra blade variables].
     *
     * @var array<string, array{0:string, 1:string, 2:array<string,bool>}>
     */
    private const VIEWS = [
        'classic' => ['classic', 'space-y-3', []],
        'cafe' => ['cafe', 'space-y-3', []],
        'grid' => ['grid', 'grid grid-cols-2 gap-3', []],
        'minimal' => ['minimal', 'divide-y m-divide', []],
        'bistro' => ['bistro', '', []],
        'list' => ['classic', 'space-y-2', ['compact' => true]],
        'cards' => ['grid', 'space-y-4', ['big' => true]],
        'text' => ['minimal', 'divide-y m-divide', ['noPhoto' => true]],
    ];

    /** @return array{0:string, 1:string} [name, one-line description] */
    public static function label(string $layout): array
    {
        return match ($layout) {
            'list' => [__('List with small photos'), __('One item per row, a small photo on the side')],
            'grid' => [__('Two-column grid'), __('Square photos, two items per row')],
            'cards' => [__('Big photo cards'), __('A wide photo for every item')],
            'text' => [__('Price list'), __('Name, description and price, no photos at all')],
            default => [__('Follow the design'), __('The layout of the design you chose')],
        };
    }

    /**
     * What a tap on an item opens (setting menu_display): "simple" = the bottom sheet (default), "pro" = a full item
     * page with its own shareable link (/item/{id}). The professional display needs the plan feature menu_themes.
     */
    public static function display(array $settings): string
    {
        return ($settings['menu_display'] ?? 'simple') === 'pro' && Entitlements::current()->allows('menu_themes') ? 'pro' : 'simple';
    }

    /** The menu-wide choice of the current restaurant ("auto" without the plan feature). */
    public static function setting(array $settings): string
    {
        $choice = (string) ($settings['menu_layout'] ?? 'auto');

        return in_array($choice, self::CHOICES, true) && Entitlements::current()->allows('menu_themes') ? $choice : 'auto';
    }

    /**
     * The layout a category is drawn with: its own override, else the menu setting, else (auto) the theme's layout
     * — or the price list when nothing in it has a photo and the placeholders are off.
     */
    public static function forCategory(Category $category, string $theme, array $settings): string
    {
        $override = (string) $category->menu_layout;
        if (in_array($override, self::OVERRIDES, true) && Entitlements::current()->allows('menu_themes')) {
            return $override;
        }
        $choice = self::setting($settings);
        if ($choice !== 'auto') {
            return $choice;
        }
        $placeholders = ($settings['item_placeholder'] ?? '1') !== '0';
        if (! $placeholders && $category->items->isNotEmpty() && $category->items->every(fn (MenuItem $item) => ! $item->image)) {
            return 'text';
        }

        return MenuThemes::layout($theme);
    }

    /** @return array{blade:string, wrapper:string, vars:array<string,bool>} */
    public static function view(string $layout): array
    {
        [$blade, $wrapper, $vars] = self::VIEWS[$layout] ?? self::VIEWS['classic'];

        return ['blade' => $blade, 'wrapper' => $wrapper, 'vars' => $vars];
    }

    /**
     * Where the quick "+" sits on an item of this layout: over the photo's top corner (grid, cards with a photo), over the
     * bottom corner of the side photo (classic, list) or centred at the end of the row, which then keeps room
     * for it (price lists, cafe, rows without a photo).
     */
    public static function quickSpot(string $layout, MenuItem $item, bool $placeholders): string
    {
        $photo = $item->image || $placeholders;

        return match (true) {
            $layout === 'grid', $layout === 'cards' && $photo => 'top',
            $layout === 'classic' && $photo => 'photo',
            $layout === 'list' && $photo => 'photo-sm',
            default => 'row',
        };
    }

    /**
     * The quick "+" of an item: none when sold out, "sheet" when a size or a required choice must be picked first
     * (the tap opens the item sheet), else "add" (one tap adds one to the cart).
     */
    public static function quickAdd(MenuItem $item): ?string
    {
        if ($item->is_sold_out) {
            return null;
        }
        $needsChoice = $item->optionGroups->contains(fn ($group) => $group->is_required || $group->type === 'single');

        return $needsChoice ? 'sheet' : 'add';
    }
}
