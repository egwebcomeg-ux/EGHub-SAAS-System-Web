<?php

namespace App\Support;

use App\Models\Category;
use App\Models\MenuItem;

/**
 * The tile an item without a photo shows on the menu (setting item_placeholder): a line icon picked from the item
 * name, then the category (the owner's pick or its name: cakes, croissants, konafa, pizza, juice…), then the kind of
 * place (pastry → cake, bakery → bread, cafe → cup, restaurant → plate), drawn in the theme accent on a surface-2
 * tile, so a menu without photos still looks complete and aligned.
 */
final class ItemPlaceholder
{
    /**
     * [icon, words (Arabic and English, lower case), category names only], checked in this order: the first word
     * found wins, so "cupcake" comes before "cake", "hot chocolate" before "chocolate" and "rice pudding" before
     * "rice". A Latin word must start a word ("tea" is not found in "steak" or "gâteaux"); an Arabic one may follow
     * a prefix (ال، و، ب). The generic groups (weak: drinks, sweets, bakery, pastries) are read from category names
     * only (an item called "بطاطس حلوة" is no dessert), and in such a category the item names refine the icon ("Drinks": the tea
     * gets a tea cup, the juice a glass with a straw; "Bakery": the croissants a croissant).
     *
     * @var list<array{0: string, 1: list<string>, 2?: bool}>
     */
    private const WORDS = [
        ['cupcake', ['كب كيك', 'كاب كيك', 'كبكيك', 'مافن', 'cupcake', 'cup cake', 'muffin']],
        ['icecream', ['آيس كريم', 'ايس كريم', 'آيسكريم', 'ايسكريم', 'جيلاتي', 'جيلاتو', 'بوظة', 'ice cream', 'icecream', 'gelato', 'sorbet', 'sundae', 'glace']],
        ['cup', ['هوت شوكليت', 'شوكولاتة ساخنة', 'شوكولاته ساخنه', 'hot chocolate', 'chocolat chaud']],
        ['tea', ['شاي', 'ينسون', 'كركديه', 'أعشاب', 'اعشاب', 'tea', 'chai', 'herbal', 'infusion']],
        ['juice', ['عصير', 'عصاير', 'عصائر', 'سموذي', 'موهيتو', 'ليموناد', 'كوكتيل', 'ميلك شيك', 'ليمون نعناع', 'ليمون بالنعناع', 'juice', 'lemon mint', 'mint lemon', 'smoothie', 'mojito', 'lemonade', 'cocktail', 'milkshake', 'shake', 'soda', 'soft drink']],
        ['cup', ['قهو', 'كافي', 'لاتيه', 'اسبريسو', 'إسبريسو', 'كابتشينو', 'كابوتشينو', 'موكا', 'فرابيه', 'coffee', 'café', 'cafe', 'latte', 'espresso', 'cappuccino', 'mocha', 'frappe', 'americano']],
        ['cookie', ['كوكيز', 'كوكي', 'بسكوت', 'بسكويت', 'بيتي فور', 'بيتيفور', 'غريبة', 'كحك', 'كعك', 'petit four', 'petits four', 'cookie', 'biscuit', 'biscotti', 'shortbread', 'ghorayeba', 'kahk']],
        ['donut', ['دونات', 'دونتس', 'دوناتس', 'donut', 'doughnut']],
        ['croissant', ['كرواسون', 'كرواسان', 'كروسان', 'فينوازري', 'باتيه', 'فطير', 'فطائر', 'croissant', 'viennoiserie', 'pain au', 'danish', 'puff']],
        ['oriental', ['حلويات شرقية', 'حلويات شرقيه', 'حلو شرقي', 'كنافة', 'كنافه', 'بسبوس', 'بقلاو', 'جلاش', 'قطايف', 'بلح الشام', 'زلابي', 'لقمة القاضي', 'أم علي', 'ام علي', 'رز بلبن', 'رز باللبن', 'أرز باللبن', 'ارز باللبن', 'مهلبي', 'عيش السرايا', 'هريسة',
            'oriental sweet', 'oriental dessert', 'konafa', 'kunafa', 'knafeh', 'kunafeh', 'basbousa', 'baklava', 'baklawa', 'qatayef', 'om ali', 'umm ali', 'rice pudding', 'mahalabia', 'zalabia']],
        ['cake', ['تورت', 'كيك', 'جاتو', 'gateau', 'gâteau', 'cake', 'cheesecake', 'tart', 'torte', 'black forest', 'red velvet', 'opera', 'tiramisu', 'تيراميسو']],
        ['chocolate', ['شوكولا', 'شيكولا', 'شوكلت', 'براوني', 'ترافل', 'برالين', 'chocolate', 'chocolat', 'choco', 'brownie', 'truffle', 'praline', 'cacao', 'cocoa']],
        ['box', ['بوكس', 'علب', 'تشكيل', 'هدية', 'هدايا', 'box', 'assort', 'gift', 'tray', 'hamper']],
        ['fish', ['سمك', 'أسماك', 'اسماك', 'سوشي', 'بحري', 'بحريات', 'جمبري', 'كاليماري', 'fish', 'sushi', 'seafood', 'shrimp', 'prawn', 'maki', 'salmon', 'calamari']],
        ['pizza', ['بيتزا', 'pizza']],
        ['wrap', ['شاورم', 'شاورما', 'تورتيلا', 'فاهيتا', 'بوريتو', 'shawarma', 'shawerma', 'wrap', 'tortilla', 'fajita', 'burrito', 'kebab roll']],
        ['burger', ['برجر', 'برغر', 'بيرجر', 'ساندو', 'سندو', 'ساندويتش', 'ساندويش', 'كريسبي', 'هوت دوج', 'burger', 'sandwich', 'sub', 'hot dog', 'crispy']],
        ['bread', ['عيش', 'خبز', 'فينو', 'بقسماط', 'توست', 'سميط', 'baguette', 'bread', 'loaf', 'bun', 'toast', 'sourdough', 'brioche', 'focaccia', 'ciabatta']],
        ['bread', ['مخبوز', 'مخبز', 'مخابز', 'bakery', 'boulangerie'], true],
        ['croissant', ['معجنات', 'pastries', 'pastry'], true],
        ['rice', ['أرز', 'ارز', 'الرز', 'كبسة', 'مندي', 'برياني', 'بخاري', 'كشري', 'rice', 'biryani', 'kabsa', 'mandi', 'risotto', 'koshary', 'koshari']],
        ['salad', ['سلط', 'سلاطة', 'خضار', 'salad', 'greens', 'veg']],
        ['dessert', ['وافل', 'كريب', 'بان كيك', 'بودينج', 'waffle', 'crepe', 'pancake', 'pudding', 'mousse']],
        ['cup', ['مشروب', 'مشاريب', 'ساقع', 'drink', 'beverage'], true],
        ['dessert', ['حلو', 'ديزرت', 'dessert', 'sweet'], true],
    ];

    /** Inner SVG (24×24, stroke = currentColor, 1.5) of each icon. */
    private const PATHS = [
        'cup' => '<path d="M4 9h12v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5V9Z"/><path d="M16 10h1.5a2.5 2.5 0 0 1 0 5H16"/><path d="M8 3c0 1.2 1 1.2 1 2.4M12 3c0 1.2 1 1.2 1 2.4"/>',
        'tea' => '<path d="M4 10h11v3.5a5.5 5.5 0 0 1-11 0V10Z"/><path d="M15 11.5h1.2a2.3 2.3 0 0 1 0 4.6H14.4"/><path d="M3 21h13"/><path d="M8.5 10V6.8c0-1 .6-1.8 1.6-1.8h.4"/><path d="M10.5 3.5h3v3h-3z"/>',
        'juice' => '<path d="M6 8.5h12l-1.4 11.6a1 1 0 0 1-1 .9H8.4a1 1 0 0 1-1-.9L6 8.5Z"/><path d="M6.6 13.5h10.8"/><path d="M13 8.5 15 3h2.5"/><circle cx="17.5" cy="8.5" r="2.5"/>',
        'dessert' => '<path d="M5 13h14l-1.6 7H6.6L5 13Z"/><path d="M6 13a6 6 0 0 1 12 0"/><path d="M12 7V4"/><circle cx="12" cy="3.5" r=".8"/>',
        'cake' => '<path d="M3 14 21 8v12H3v-6Z"/><path d="M3 17h18"/><path d="M3 14c1.2 1 2.4 1 3.6 0 1.2 1 2.4 1 3.6 0"/><path d="M18.5 7.4V5.5"/><circle cx="18.5" cy="4.3" r="1.2"/>',
        'cupcake' => '<path d="M6 13h12l-1.5 7.5h-9L6 13Z"/><path d="M10 13.5l.4 7M14 13.5l-.4 7"/><path d="M6 13a3 3 0 0 1 1-5.4A5.2 5.2 0 0 1 17 7.6a3 3 0 0 1 1 5.4"/><path d="M12 4.6V3"/>',
        'croissant' => '<path d="M9 6.5c2-1.2 4-1.2 6 0l-1 9.5h-4L9 6.5Z"/><path d="M9 7.5 5.3 9.6a2.4 2.4 0 0 0-.6 3.6L8 16.5l2-.5"/><path d="M15 7.5l3.7 2.1a2.4 2.4 0 0 1 .6 3.6L16 16.5l-2-.5"/><path d="M4.8 12.8 3 17.5M19.2 12.8 21 17.5"/>',
        'cookie' => '<path d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5 3.2 3.2 0 0 1-4-3.9A3.4 3.4 0 0 1 12 3.5Z"/><circle cx="8.5" cy="10" r=".9"/><circle cx="12" cy="15.5" r=".9"/><circle cx="15.8" cy="13.8" r=".9"/><circle cx="8.3" cy="15.3" r=".7"/><circle cx="11.5" cy="9.2" r=".7"/>',
        'donut' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="2.8"/><path d="M4.2 10.2c1.4 1.2 2.6-.8 4-.1M15.6 8.4c1.2.8 2.4-.4 3.6.5M8.2 17.4c1.2-1.1 2.6.4 3.8-.6"/><path d="M11.5 5.8l1.2.5M16.4 14.6l.6 1.1M6.8 13.6l1.1-.3"/>',
        'icecream' => '<path d="M6.5 10.5 12 22l5.5-11.5"/><path d="M5.5 10.5a6.5 6.5 0 1 1 13 0Z"/><path d="M8.6 14.2l6.2-2.2M10.2 17.6l3.4-1.2"/>',
        'chocolate' => '<path d="M6 3.5h9l3 3v14H6V3.5Z"/><path d="M6 9h12M6 14.5h12M12 3.5v17"/><path d="M15 3.5v3h3"/>',
        'bread' => '<g transform="rotate(-45 12 12)"><rect x="1.5" y="8.5" width="21" height="7" rx="3.5"/><path d="M7 9.8l1.6 4.4M11.2 9.8l1.6 4.4M15.4 9.8l1.6 4.4"/></g>',
        'oriental' => '<rect x="3" y="6" width="18" height="12" rx="1.5"/><path d="M3 9l3-3 6 6 6-6 3 3M3 15l3 3 6-6 6 6 3-3"/><circle cx="6" cy="12" r=".8"/><circle cx="12" cy="7.6" r=".8"/><circle cx="12" cy="16.4" r=".8"/><circle cx="18" cy="12" r=".8"/>',
        'box' => '<path d="M4 10.5h16v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9Z"/><path d="M3 7.5h18v3H3z"/><path d="M12 7.5v13"/><path d="M12 7.5C10.8 4.5 7.5 4 7.5 5.8S10 7.5 12 7.5Zm0 0c1.2-3 4.5-3.5 4.5-1.7S14 7.5 12 7.5Z"/>',
        'pizza' => '<path d="M12 3 3.5 19a17 17 0 0 0 17 0L12 3Z"/><path d="M5.6 15.2a13 13 0 0 0 12.8 0"/><circle cx="11" cy="11" r="1"/><circle cx="13.5" cy="15.5" r="1"/><circle cx="9.5" cy="16" r=".8"/>',
        'burger' => '<path d="M4 11a8 8 0 0 1 16 0H4Z"/><path d="M3.5 14.5h17"/><path d="M5 18h14a1 1 0 0 0 1-1v-.5H4v.5a1 1 0 0 0 1 1Z"/>',
        'wrap' => '<path d="M7 21 3 17 13.8 5.2a3.6 3.6 0 0 1 5 5L7 21Z"/><path d="M5.5 14.2l4.3 4.3M8 11.5l4.5 4.5"/><path d="M13.8 5.2c1.6.2 4.8 3.4 5 5"/>',
        'rice' => '<path d="M3.5 12.5h17a8.5 8.5 0 0 1-17 0Z"/><path d="M6.5 12.5a5.5 5.5 0 0 1 11 0"/><path d="M9.5 9.8l.8-.4M12.5 8.8h.9M14.5 10.4l.6.6M11.2 11l.8.2"/>',
        'fish' => '<path d="M3 12c3-4.5 7.5-6 11.5-4.5 2 .8 3.6 2.5 4.5 4.5-.9 2-2.5 3.7-4.5 4.5C10.5 18 6 16.5 3 12Z"/><path d="M19 12l2.5-3v6L19 12Z"/><circle cx="14.5" cy="11" r=".8"/>',
        'salad' => '<path d="M3.5 11h17a8.5 8.5 0 0 1-17 0Z"/><path d="M8 11c0-2.5 1.5-4.5 4-4.5M12 11c.3-3 2.2-5 5-5.2M6 11c-.5-1.8 0-3.5 1.5-4.5"/>',
        'plate' => '<circle cx="12" cy="13" r="6.5"/><circle cx="12" cy="13" r="3.5"/><path d="M3 4v5a1.5 1.5 0 0 0 1.5 1.5v9.5M3 4v3M6 4v5a1.5 1.5 0 0 1-1.5 1.5M21 4c-1.4 0-2 1.6-2 4v3h2v9"/>',
    ];

    /** Icons an owner may pick for a category (the round category row of the menu). */
    public const ICONS = ['cup', 'tea', 'juice', 'dessert', 'cake', 'cupcake', 'croissant', 'cookie', 'donut', 'icecream', 'chocolate',
        'bread', 'oriental', 'box', 'pizza', 'burger', 'wrap', 'rice', 'fish', 'salad', 'plate'];

    /** @var array<string, array{0: ?string, 1: bool}> text => [icon, weak], kept for the process (pure lookups) */
    private static array $found = [];

    /**
     * The kind of place behind the last fallback: the business type, or "bakery" for a pastry shop that serves
     * bakery only (the cuisine setting, App\Support\Cuisines).
     */
    public static function kind(string $businessType, string $cuisine = ''): string
    {
        $cuisines = array_filter(explode(',', $cuisine));
        if ($businessType === 'pastry' && in_array('bakery', $cuisines, true) && ! in_array('desserts', $cuisines, true)) {
            return 'bakery';
        }

        return $businessType;
    }

    /**
     * The icon of a category on the menu: the one the owner picked (categories.icon), else one from its name.
     */
    public static function forCategory(Category $category, string $kind = 'cafe'): string
    {
        $chosen = (string) $category->icon;

        return in_array($chosen, self::ICONS, true) ? $chosen : self::icon($category->name.' '.$category->name_en, $kind);
    }

    /**
     * The icon of an item: its category's when the owner picked it or the category name is specific ("Cookies":
     * every cookie looks alike, whatever its flavour), else from its own name ("Konafa with cream" in "Specials",
     * "Mint tea" in "Drinks"), else the category's.
     */
    public static function forItem(MenuItem $item, Category $category, string $kind = 'cafe'): string
    {
        $categoryIcon = self::forCategory($category, $kind);
        if (in_array((string) $category->icon, self::ICONS, true)) {
            return $categoryIcon;
        }
        [$found, $weak] = self::match($category->name.' '.$category->name_en);
        if ($found !== null && ! $weak) {
            return $categoryIcon;
        }

        return self::match($item->name.' '.$item->name_en, false)[0] ?? $categoryIcon;
    }

    /** Label of an icon in the category form. */
    public static function label(string $icon): string
    {
        return match ($icon) {
            'cup' => __('Drinks'),
            'tea' => __('Tea & herbs'),
            'juice' => __('Juices'),
            'dessert' => __('Desserts'),
            'cake' => __('Cakes'),
            'cupcake' => __('Cupcakes'),
            'croissant' => __('Croissants & pastries'),
            'cookie' => __('Cookies & petits fours'),
            'donut' => __('Donuts'),
            'icecream' => __('Ice cream'),
            'chocolate' => __('Chocolate'),
            'bread' => __('Bread & bakery'),
            'oriental' => __('Oriental sweets'),
            'box' => __('Boxes & assortments'),
            'pizza' => __('Pizza'),
            'burger' => __('Sandwiches & burgers'),
            'wrap' => __('Shawarma & wraps'),
            'rice' => __('Rice dishes'),
            'fish' => __('Fish & sushi'),
            'salad' => __('Salads'),
            default => __('Dishes'),
        };
    }

    /** The icon of a category ("مشروبات ساخنة Hot drinks"), else the usual one of the kind of place. */
    public static function icon(string $categoryText, string $kind = 'cafe'): string
    {
        return self::match($categoryText)[0] ?? match ($kind) {
            'cafe' => 'cup',
            'pastry' => 'cake',
            'bakery' => 'bread',
            'shop' => 'box',
            default => 'plate',
        };
    }

    /**
     * The first icon whose word is in the text (see WORDS) and whether it came from a weak group; [null, true]
     * when none is. An item name ($category false) skips the weak groups.
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function match(string $text, bool $category = true): array
    {
        $key = ($category ? 'c|' : 'i|').$text;
        if (isset(self::$found[$key])) {
            return self::$found[$key];
        }
        if (count(self::$found) > 5000) {
            self::$found = [];
        }
        $text = mb_strtolower(trim($text));
        $result = [null, true];
        if ($text !== '') {
            foreach (self::WORDS as $group) {
                $weak = $group[2] ?? false;
                if (! $category && $weak) {
                    continue;
                }
                foreach ($group[1] as $word) {
                    if (self::has($text, $word)) {
                        $result = [$group[0], $weak];
                        break 2;
                    }
                }
            }
        }

        return self::$found[$key] = $result;
    }

    /** A Latin word must start a word of the text; an Arabic one may sit anywhere (after ال، و، ب…). */
    private static function has(string $text, string $word): bool
    {
        if (! preg_match('/^[a-zà-ÿ]/u', $word)) {
            return str_contains($text, $word);
        }

        return (bool) preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'/u', $text);
    }

    public static function svg(string $icon, string $class = ''): string
    {
        $paths = self::PATHS[$icon] ?? self::PATHS['plate'];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="'.e($class).'">'.$paths.'</svg>';
    }
}
