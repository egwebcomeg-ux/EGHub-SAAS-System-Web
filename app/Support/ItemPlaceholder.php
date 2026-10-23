<?php

namespace App\Support;

use App\Models\Category;

/**
 * The tile an item without a photo shows on the menu (setting item_placeholder): a line icon picked from the
 * category name (drinks, desserts, pizza, sandwiches…) or the business type, drawn in the theme accent on a
 * surface-2 tile, so a menu without photos still looks complete and aligned.
 */
final class ItemPlaceholder
{
    /** icon => words in category names (Arabic and English, lower case) */
    private const WORDS = [
        'cup' => ['قهو', 'مشروب', 'عصير', 'عصائر', 'شاي', 'كافي', 'لاتيه', 'اسبريسو', 'إسبريسو', 'موهيتو', 'سموذي', 'ميلك', 'فريش', 'ساقع', 'سخن', 'بارد', 'ساخن', 'كوكتيل',
            'coffee', 'drink', 'juice', 'tea', 'latte', 'espresso', 'smoothie', 'shake', 'mojito', 'soda', 'beverage', 'frappe', 'cocktail'],
        'dessert' => ['حلو', 'حلويات', 'كيك', 'تورت', 'آيس', 'ايس', 'وافل', 'كريب', 'بان كيك', 'دونات', 'شوكولا', 'ديزرت',
            'جاتوه', 'مخبوز', 'مخبز', 'بيتي فور', 'كنافة', 'كنافه', 'بسبوس', 'كرواسون', 'بوكس',
            'dessert', 'sweet', 'cake', 'ice cream', 'waffle', 'crepe', 'pancake', 'donut', 'chocolate', 'pastry', 'gateau', 'gâteau', 'bakery', 'croissant'],
        'pizza' => ['بيتزا', 'pizza'],
        'burger' => ['برجر', 'برغر', 'ساندوتش', 'ساندويتش', 'سندوتش', 'شاورم', 'كريسبي', 'burger', 'sandwich', 'shawarma', 'wrap'],
        'fish' => ['سمك', 'أسماك', 'اسماك', 'سوشي', 'بحري', 'بحريات', 'جمبري', 'fish', 'sushi', 'seafood', 'shrimp', 'maki'],
        'salad' => ['سلط', 'سلاطة', 'خضار', 'salad', 'greens', 'veg'],
    ];

    /** Inner SVG (24×24, stroke = currentColor) of each icon. */
    private const PATHS = [
        'cup' => '<path d="M4 9h12v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V9Z"/><path d="M16 10h1.5a2.5 2.5 0 0 1 0 5H16"/><path d="M8 3c0 1.2 1 1.2 1 2.4M12 3c0 1.2 1 1.2 1 2.4"/>',
        'dessert' => '<path d="M5 13h14l-1.6 7H6.6L5 13Z"/><path d="M6 13a6 6 0 0 1 12 0"/><path d="M12 7V4"/><circle cx="12" cy="3.5" r=".8"/>',
        'pizza' => '<path d="M12 3 3.5 19a17 17 0 0 0 17 0L12 3Z"/><path d="M5.6 15.2a13 13 0 0 0 12.8 0"/><circle cx="11" cy="11" r="1"/><circle cx="13.5" cy="15.5" r="1"/><circle cx="9.5" cy="16" r=".8"/>',
        'burger' => '<path d="M4 11a8 8 0 0 1 16 0H4Z"/><path d="M3.5 14.5h17"/><path d="M5 18h14a1 1 0 0 0 1-1v-.5H4v.5a1 1 0 0 0 1 1Z"/>',
        'fish' => '<path d="M3 12c3-4.5 7.5-6 11.5-4.5 2 .8 3.6 2.5 4.5 4.5-.9 2-2.5 3.7-4.5 4.5C10.5 18 6 16.5 3 12Z"/><path d="M19 12l2.5-3v6L19 12Z"/><circle cx="14.5" cy="11" r=".8"/>',
        'salad' => '<path d="M3.5 11h17a8.5 8.5 0 0 1-17 0Z"/><path d="M8 11c0-2.5 1.5-4.5 4-4.5M12 11c.3-3 2.2-5 5-5.2M6 11c-.5-1.8 0-3.5 1.5-4.5"/>',
        'plate' => '<circle cx="12" cy="13" r="6.5"/><circle cx="12" cy="13" r="3.5"/><path d="M3 4v5a1.5 1.5 0 0 0 1.5 1.5v9.5M3 4v3M6 4v5a1.5 1.5 0 0 1-1.5 1.5M21 4c-1.4 0-2 1.6-2 4v3h2v9"/>',
    ];

    /** Icons an owner may pick for a category (the round category row of the menu). */
    public const ICONS = ['cup', 'dessert', 'pizza', 'burger', 'fish', 'salad', 'plate'];

    /**
     * The icon of a category on the menu: the one the owner picked (categories.icon), else one from its name.
     */
    public static function forCategory(Category $category, string $businessType = 'cafe'): string
    {
        $chosen = (string) $category->icon;

        return in_array($chosen, self::ICONS, true) ? $chosen : self::icon($category->name.' '.$category->name_en, $businessType);
    }

    /** Label of an icon in the category form. */
    public static function label(string $icon): string
    {
        return match ($icon) {
            'cup' => __('Drinks'),
            'dessert' => __('Desserts'),
            'pizza' => __('Pizza'),
            'burger' => __('Sandwiches & burgers'),
            'fish' => __('Fish & sushi'),
            'salad' => __('Salads'),
            default => __('Dishes'),
        };
    }

    /** The icon of a category ("مشروبات ساخنة Hot drinks"), else the usual one of the business type. */
    public static function icon(string $categoryText, string $businessType = 'cafe'): string
    {
        $text = mb_strtolower($categoryText);
        foreach (self::WORDS as $icon => $words) {
            foreach ($words as $word) {
                if ($word !== '' && str_contains($text, $word)) {
                    return $icon;
                }
            }
        }

        return match ($businessType) {
            'cafe' => 'cup',
            'pastry' => 'dessert',
            default => 'plate',
        };
    }

    public static function svg(string $icon, string $class = ''): string
    {
        $paths = self::PATHS[$icon] ?? self::PATHS['plate'];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="'.e($class).'">'.$paths.'</svg>';
    }
}
