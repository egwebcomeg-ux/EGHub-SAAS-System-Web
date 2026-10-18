<?php

namespace App\Support;

/**
 * Whether a menu category is prepared as food (the kitchen) or as drinks (the bar). Kept on the category and copied
 * onto each order line, so an order with both shows "food ready" / "drinks ready" separately. A new category
 * starts from its name (coffee, juice, drinks → drinks) and the owner can change it in the category form.
 */
final class PrepKind
{
    public const FOOD = 'food';

    public const DRINKS = 'drinks';

    /** key => [arabic, english] */
    public const KINDS = [
        self::FOOD => ['أكل', 'Food'],
        self::DRINKS => ['مشروبات', 'Drinks'],
    ];

    /** English words of a drinks category name, matched as whole words (plural allowed): "Cold drinks", "Juices". */
    private const DRINK_WORDS_EN = ['drink', 'beverage', 'coffee', 'espresso', 'latte', 'tea', 'juice', 'smoothie', 'shake',
        'milkshake', 'mojito', 'soda', 'cocktail', 'frappe', 'frappuccino', 'lemonade', 'cappuccino'];

    /** Arabic stems found anywhere in the name (they take "ال" and suffixes). */
    private const DRINK_WORDS_AR = ['مشروب', 'مشاريب', 'قهوة', 'قهوه', 'كافيه', 'اسبريسو', 'إسبريسو', 'شاي', 'شاى', 'عصير', 'عصاير',
        'عصائر', 'سموزي', 'ميلك شيك', 'موهيتو', 'كوكتيل', 'فرابيه', 'فرابتشينو', 'كابتشينو', 'ليمونادة'];

    public static function guess(?string ...$names): string
    {
        $text = mb_strtolower(implode(' ', array_filter($names)));
        if (preg_match('/(?<![a-z])('.implode('|', self::DRINK_WORDS_EN).')(e?s)?(?![a-z])/u', $text)) {
            return self::DRINKS;
        }
        foreach (self::DRINK_WORDS_AR as $word) {
            if (str_contains($text, $word)) {
                return self::DRINKS;
            }
        }

        return self::FOOD;
    }

    public static function label(string $kind): string
    {
        $l = self::KINDS[$kind] ?? [$kind, $kind];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    public static function valid(?string $kind): string
    {
        return isset(self::KINDS[$kind ?? '']) ? $kind : self::FOOD;
    }
}
