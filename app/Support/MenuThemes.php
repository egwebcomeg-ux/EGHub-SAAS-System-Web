<?php

namespace App\Support;

/**
 * Public menu templates. Each one is a surface style (resources/views/menu/themes/styles) plus an item layout
 * (resources/views/menu/items/*). The restaurant's own colors still drive buttons and prices.
 */
final class MenuThemes
{
    /** key => [item layout, best for (business types)] */
    public const ALL = [
        'classic' => ['classic', ['cafe', 'restaurant', 'both', 'shop']],
        'grid' => ['grid', ['cafe', 'restaurant', 'both', 'shop']],
        'minimal' => ['minimal', ['restaurant', 'shop']],
        'dark' => ['classic', ['cafe']],
        'cafe' => ['cafe', ['cafe', 'both']],
        'bistro' => ['bistro', ['restaurant']],
        'fastfood' => ['grid', ['restaurant', 'both', 'shop']],
        'oriental' => ['classic', ['restaurant', 'both']],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function layout(string $theme): string
    {
        return self::ALL[$theme][0] ?? 'classic';
    }

    /** @return array{0:string,1:string} [name, one-line description] */
    public static function label(string $theme): array
    {
        return match ($theme) {
            'grid' => [__('Grid'), __('Big photos, two per row')],
            'minimal' => [__('Minimal'), __('Like a printed menu')],
            'dark' => [__('Dark'), __('Dark background, for evening cafes')],
            'cafe' => [__('Cozy cafe'), __('Warm beige and brown, round photos')],
            'bistro' => [__('Bistro'), __('Charcoal and gold, elegant and calm')],
            'fastfood' => [__('Fast food'), __('Bold colors and big photos')],
            'oriental' => [__('Oriental grill'), __('Cream with an oriental pattern')],
            default => [__('Classic'), __('Text with a photo on the side')],
        };
    }

    public static function suits(string $theme, string $businessType): bool
    {
        return in_array($businessType, self::ALL[$theme][1] ?? [], true);
    }
}
