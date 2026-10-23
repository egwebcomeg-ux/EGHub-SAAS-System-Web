<?php

namespace App\Support;

use App\Services\Settings;

/**
 * Cafe, restaurant, both, a pastry shop / bakery or a shop: picked at sign-up, changeable in Settings. It decides the starter categories
 * and prep stations, what the prep screen is called, the loyalty card defaults, the default menu template and
 * which add-ons the dashboard recommends. Nothing is locked by it; every feature stays available.
 * "Pastry" is the حلواني and the bakery: cakes ordered ahead for a day and an hour, boxes, pickup and delivery.
 * "Shop" covers places that sell products rather than dishes: butchers, juice bars, grocers, salons.
 */
final class BusinessType
{
    public const TYPES = [
        'cafe' => ['كافيه', 'Cafe'],
        'restaurant' => ['مطعم', 'Restaurant'],
        'both' => ['كافيه ومطعم', 'Cafe & restaurant'],
        'pastry' => ['حلواني ومخبز', 'Pastry & bakery'],
        'shop' => ['محل', 'Shop'],
    ];

    public const DEFAULT = 'cafe';

    public static function current(): string
    {
        $type = (string) Settings::get('business_type', self::DEFAULT);

        return isset(self::TYPES[$type]) ? $type : self::DEFAULT;
    }

    public static function label(?string $type = null): string
    {
        $l = self::TYPES[$type ?? self::current()] ?? self::TYPES[self::DEFAULT];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    /** The small picture next to the type in sign-up and settings. */
    public static function emoji(string $type): string
    {
        return match ($type) {
            'restaurant' => '🍽',
            'both' => '☕🍽',
            'pastry' => '🍰',
            'shop' => '🛍',
            default => '☕',
        };
    }

    /** @return list<array{0:string,1:string}> [arabic, english] starter categories */
    public static function categories(string $type): array
    {
        $cafe = [['قهوة سخنة', 'Hot coffee'], ['مشروبات ساقعة', 'Cold drinks'], ['شاي وأعشاب', 'Tea & herbs'], ['حلويات', 'Desserts']];
        $restaurant = [['مقبلات', 'Starters'], ['سلطات', 'Salads'], ['أطباق رئيسية', 'Main dishes'], ['مشويات', 'Grills'], ['مشروبات', 'Drinks'], ['حلويات', 'Desserts']];

        return match ($type) {
            'restaurant' => $restaurant,
            'both' => [['مقبلات', 'Starters'], ['أطباق رئيسية', 'Main dishes'], ['ساندوتشات', 'Sandwiches'], ['قهوة سخنة', 'Hot coffee'], ['مشروبات ساقعة', 'Cold drinks'], ['حلويات', 'Desserts']],
            'pastry' => [['جاتوه', 'Gâteaux'], ['تورت', 'Cakes'], ['مخبوزات', 'Bakery'], ['حلويات شرقية', 'Oriental sweets'], ['بوكسات', 'Boxes'], ['قهوة ومشروبات', 'Coffee & drinks']],
            'shop' => [['المنتجات', 'Products'], ['العروض', 'Offers'], ['الأكثر طلباً', 'Best sellers']],
            default => $cafe,
        };
    }

    /** @return list<array{0:string,1:string}> prep stations: the bar makes drinks, the kitchen makes food, a shop or a pastry shop prepares the order */
    public static function stations(string $type): array
    {
        return match ($type) {
            'restaurant' => [['المطبخ', 'Kitchen']],
            'both' => [['المطبخ', 'Kitchen'], ['البار', 'Bar']],
            'pastry', 'shop' => [['التجهيز', 'Prep']],
            default => [['البار', 'Bar']],
        };
    }

    /** What the prep screen is called: a cafe has a bar, a restaurant a kitchen, a shop or a pastry shop prepares orders. */
    public static function prepScreen(?string $type = null): string
    {
        return match ($type ?? self::current()) {
            'cafe' => __('Bar display'),
            'both' => __('Kitchen & bar display'),
            'pastry', 'shop' => __('Prep display'),
            default => __('Kitchen display'),
        };
    }

    public static function theme(string $type): string
    {
        return match ($type) {
            'restaurant' => 'bistro',
            'pastry', 'shop' => 'grid',
            default => 'cafe',
        };
    }

    /**
     * Attributes of a new loyalty program. Cafes, restaurants and pastry shops start with a stamp card (a pastry
     * shop's regulars buy a box or a cake a week, so the gift is a free box); a shop earns points on the amount
     * spent (type "points"), since a butcher's basket has no "one coffee" to stamp.
     *
     * @return array<string, mixed>
     */
    public static function loyaltyDefaults(?string $type = null): array
    {
        return match ($type ?? self::current()) {
            'restaurant' => ['stamps_required' => 8, 'reward_label' => __('A free dessert'), 'card_design' => ['bg' => '#0A2540', 'stamp_bg' => '#0F172A', 'icon' => 'star']],
            'pastry' => ['stamps_required' => 8, 'reward_label' => __('A free box of sweets'), 'card_design' => ['bg' => '#9D174D', 'stamp_bg' => '#701A3E', 'icon' => 'heart']],
            'shop' => ['type' => 'points', 'points_per_egp' => 1, 'stamps_required' => 10, 'reward_label' => __('10 EGP off'), 'card_design' => ['bg' => '#0F766E', 'stamp_bg' => '#115E59', 'icon' => 'star']],
            default => ['stamps_required' => 10, 'reward_label' => __('A free drink'), 'card_design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup']],
        };
    }

    /**
     * Add-ons and features worth suggesting on the dashboard, most useful first.
     *
     * @return list<array{feature:string, title:string, text:string}>
     */
    public static function recommendations(?string $type = null): array
    {
        $type ??= self::current();
        $all = [
            'loyalty_stamps' => [__('Loyalty card'), __('Regulars collect stamps on their phone and come back for the gift.')],
            'offers' => [__('Timed offers'), __('A happy hour fills the quiet hours of the day.')],
            'pos' => [__('POS'), __('Take counter orders and payments, with shifts and a cash drawer.')],
            'kitchen_display' => [self::prepScreen($type), __('Orders go straight to the screen with timers, no paper tickets.')],
            'call_center' => [__('Call center & delivery'), __('Phone orders with the caller’s saved addresses and delivery zones.')],
            'inventory' => [__('Inventory'), __('Recipes deduct stock with every order; know your food cost.')],
            // delivery drivers come with delivery zones (Complete): worth pointing out to places that deliver
            'delivery_zones' => [__('Delivery drivers'), __('Give delivery orders to your riders, follow them on a map and settle their cash.')],
        ];
        if ($type === 'pastry') {
            $all['scheduled_orders'] = [__('Orders for a day and an hour'), __('Customers order a whole cake ahead and pick the day and hour of pickup or delivery.')];
            $all['online_ordering'] = [__('Online ordering'), __('Pickup and delivery orders from your own link, with no commission.')];
            $all['loyalty_stamps'] = [__('Loyalty card'), __('Regulars collect a stamp with every box and come back for a free one.')];
            $all['offers'] = [__('Timed offers'), __('An offer on the boxes for the holidays, or on what is left at the end of the day.')];
            $all['kitchen_display'] = [self::prepScreen($type), __('Orders go straight to the prep screen, ready for pickup or delivery.')];
        }
        if ($type === 'shop') {
            $all['loyalty_stamps'] = [__('Loyalty points'), __('Customers collect points on every pound they spend and come back to use them.')];
            $all['offers'] = [__('Timed offers'), __('A weekend offer or a price cut on what is left at the end of the day.')];
            $all['pos'] = [__('POS'), __('Sell at the counter by weight or piece, with shifts and a cash drawer.')];
            $all['kitchen_display'] = [self::prepScreen($type), __('Orders go straight to the prep screen, ready for pickup or delivery.')];
            $all['inventory'] = [__('Inventory'), __('Every sale deducts stock; know what to reorder and what you earn on each product.')];
        }
        $order = match ($type) {
            'restaurant' => ['kitchen_display', 'call_center', 'inventory', 'pos', 'delivery_zones', 'offers', 'loyalty_stamps'],
            'both' => ['kitchen_display', 'loyalty_stamps', 'pos', 'call_center', 'delivery_zones', 'offers', 'inventory'],
            'pastry' => ['scheduled_orders', 'online_ordering', 'loyalty_stamps', 'offers', 'pos', 'kitchen_display', 'inventory'],
            'shop' => ['pos', 'offers', 'loyalty_stamps', 'call_center', 'inventory', 'kitchen_display'],
            default => ['loyalty_stamps', 'offers', 'pos', 'kitchen_display', 'inventory', 'call_center'],
        };

        return array_map(fn (string $f) => ['feature' => $f, 'title' => $all[$f][0], 'text' => $all[$f][1]], $order);
    }
}
