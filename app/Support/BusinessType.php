<?php

namespace App\Support;

use App\Services\Settings;

/**
 * Cafe, restaurant or both: picked at sign-up, changeable in Settings. It decides the starter categories and
 * prep stations, what the prep screen is called, the loyalty card defaults, the default menu template and which
 * add-ons the dashboard recommends. Nothing is locked by it; every feature stays available.
 */
final class BusinessType
{
    public const TYPES = [
        'cafe' => ['كافيه', 'Cafe'],
        'restaurant' => ['مطعم', 'Restaurant'],
        'both' => ['كافيه ومطعم', 'Cafe & restaurant'],
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

    /** @return list<array{0:string,1:string}> [arabic, english] starter categories */
    public static function categories(string $type): array
    {
        $cafe = [['قهوة سخنة', 'Hot coffee'], ['مشروبات ساقعة', 'Cold drinks'], ['شاي وأعشاب', 'Tea & herbs'], ['حلويات', 'Desserts']];
        $restaurant = [['مقبلات', 'Starters'], ['سلطات', 'Salads'], ['أطباق رئيسية', 'Main dishes'], ['مشويات', 'Grills'], ['مشروبات', 'Drinks'], ['حلويات', 'Desserts']];

        return match ($type) {
            'restaurant' => $restaurant,
            'both' => [['مقبلات', 'Starters'], ['أطباق رئيسية', 'Main dishes'], ['ساندوتشات', 'Sandwiches'], ['قهوة سخنة', 'Hot coffee'], ['مشروبات ساقعة', 'Cold drinks'], ['حلويات', 'Desserts']],
            default => $cafe,
        };
    }

    /** @return list<array{0:string,1:string}> prep stations: the bar makes drinks, the kitchen makes food */
    public static function stations(string $type): array
    {
        return match ($type) {
            'restaurant' => [['المطبخ', 'Kitchen']],
            'both' => [['المطبخ', 'Kitchen'], ['البار', 'Bar']],
            default => [['البار', 'Bar']],
        };
    }

    /** What the prep screen is called: a cafe has a bar, a restaurant a kitchen. */
    public static function prepScreen(?string $type = null): string
    {
        return match ($type ?? self::current()) {
            'cafe' => __('Bar display'),
            'both' => __('Kitchen & bar display'),
            default => __('Kitchen display'),
        };
    }

    public static function theme(string $type): string
    {
        return $type === 'restaurant' ? 'bistro' : 'cafe';
    }

    /** @return array<string, mixed> attributes of a new loyalty program */
    public static function loyaltyDefaults(?string $type = null): array
    {
        return ($type ?? self::current()) === 'restaurant'
            ? ['stamps_required' => 8, 'reward_label' => __('A free dessert'), 'card_design' => ['bg' => '#1E2D4F', 'stamp_bg' => '#0F172A', 'icon' => 'star']]
            : ['stamps_required' => 10, 'reward_label' => __('A free drink'), 'card_design' => ['bg' => '#B5451B', 'stamp_bg' => '#7A1F2B', 'icon' => 'cup']];
    }

    /**
     * Add-ons and features worth suggesting on the dashboard, most useful first.
     *
     * @return list<array{feature:string, title:string, text:string}>
     */
    public static function recommendations(?string $type = null): array
    {
        $all = [
            'loyalty_stamps' => [__('Loyalty card'), __('Regulars collect stamps on their phone and come back for the gift.')],
            'offers' => [__('Timed offers'), __('A happy hour fills the quiet hours of the day.')],
            'pos' => [__('POS'), __('Take counter orders and payments, with shifts and a cash drawer.')],
            'kitchen_display' => [self::prepScreen($type), __('Orders go straight to the screen with timers, no paper tickets.')],
            'call_center' => [__('Call center & delivery'), __('Phone orders with the caller’s saved addresses and delivery zones.')],
            'inventory' => [__('Inventory'), __('Recipes deduct stock with every order; know your food cost.')],
        ];
        $order = match ($type ?? self::current()) {
            'restaurant' => ['kitchen_display', 'call_center', 'inventory', 'pos', 'offers', 'loyalty_stamps'],
            'both' => ['kitchen_display', 'loyalty_stamps', 'pos', 'call_center', 'offers', 'inventory'],
            default => ['loyalty_stamps', 'offers', 'pos', 'kitchen_display', 'inventory', 'call_center'],
        };

        return array_map(fn (string $f) => ['feature' => $f, 'title' => $all[$f][0], 'text' => $all[$f][1]], $order);
    }
}
