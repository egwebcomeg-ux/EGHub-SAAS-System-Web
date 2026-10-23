<?php

namespace App\Support;

use App\Models\MenuItem;

/**
 * The badge set of menu items (MenuItem::TAGS) split by business type: the item form shows the badges that suit the
 * place first and the others under "more". The owner may turn up to MAX_FILTERS badges into quick filter chips on
 * the public menu (setting menu_badge_filters, off by default); a chip shows only while some item on the menu has
 * its badge. Badges always show an icon and a word, never a color alone.
 */
final class ItemBadges
{
    public const MAX_FILTERS = 4;

    private const RESTAURANT = ['spicy', 'chef_pick', 'healthy', 'vegetarian', 'vegan', 'high_protein', 'low_fat', 'sharing', 'new', 'bestseller', 'gluten_free', 'nuts'];

    /** A pastry shop: what a guest asks before buying a cake or a box (sugar, gluten, nuts, eggs) first. */
    private const PASTRY = ['sugar_free', 'gluten_free', 'nuts', 'vegan', 'bestseller', 'new', 'seasonal', 'sharing', 'healthy', 'vegetarian'];

    private const CAFE = ['barista_pick', 'iced', 'hot', 'sugar_free', 'plant_milk', 'decaf', 'strong', 'seasonal', 'healthy', 'new', 'bestseller', 'vegetarian'];

    /**
     * The badges that suit the business type first, then the rest.
     *
     * @return array{0:list<string>, 1:list<string>} [main, more]
     */
    public static function forBusiness(string $type): array
    {
        $main = match ($type) {
            'cafe' => self::CAFE,
            'pastry' => self::PASTRY,
            'both' => array_values(array_unique([...self::CAFE, ...self::RESTAURANT])),
            default => self::RESTAURANT,
        };

        return [$main, array_values(array_diff(array_keys(MenuItem::TAGS), $main))];
    }

    /** @return array{key:string, icon:string, label:string} */
    public static function badge(string $key): array
    {
        [$icon, $ar, $en] = MenuItem::TAGS[$key];

        return ['key' => $key, 'icon' => $icon, 'label' => app()->getLocale() === 'en' ? $en : $ar];
    }

    /**
     * The owner's filter chips (setting menu_badge_filters, a JSON list of badge keys).
     *
     * @return list<string>
     */
    public static function filters(array $settings): array
    {
        $keys = json_decode((string) ($settings['menu_badge_filters'] ?? ''), true);

        return is_array($keys) ? array_slice(array_values(array_intersect(array_keys(MenuItem::TAGS), $keys)), 0, self::MAX_FILTERS) : [];
    }

    /**
     * The filter chips the menu shows: chosen by the owner and carried by at least one item on it.
     *
     * @param  iterable<MenuItem>  $items
     * @return list<array{key:string, icon:string, label:string}>
     */
    public static function chips(array $settings, iterable $items): array
    {
        $wanted = self::filters($settings);
        if ($wanted === []) {
            return [];
        }
        $present = [];
        foreach ($items as $item) {
            $present = [...$present, ...$item->badgeKeys()];
        }

        return array_map(fn (string $key) => self::badge($key), array_values(array_intersect($wanted, $present)));
    }
}
