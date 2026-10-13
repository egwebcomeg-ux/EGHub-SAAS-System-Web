<?php

namespace App\Services;

use App\Models\TenantSetting;

/**
 * Key/value settings of the current restaurant with their defaults. Read once per request and memoised per tenant.
 */
final class Settings
{
    public const DEFAULTS = [
        // brand
        'brand_name' => '',
        'brand_name_en' => '',
        'tagline' => '',
        'tagline_en' => '',
        'logo' => '',
        'cover' => '',
        'currency' => 'ج.م',
        'currency_en' => 'EGP',
        // look
        'color_primary' => '#E8642C',
        'color_secondary' => '#1E2D4F',
        'color_bg' => '#FFFFFF',
        'font' => 'Cairo',
        'menu_theme' => 'classic',
        'business_type' => 'cafe',          // cafe | restaurant | both (App\Support\BusinessType)
        'default_locale' => 'ar',
        'english_enabled' => '1',
        // contact
        'phone' => '',
        'whatsapp' => '',
        'instagram' => '',
        'facebook' => '',
        'tiktok' => '',
        'google_review_url' => '',
        // ordering
        'ordering_enabled' => '1',
        'whatsapp_ordering' => '0',
        // online ordering from the menu link (plan feature online_ordering)
        'online_takeaway' => '1',
        'online_delivery' => '0',
        'online_prep_minutes' => '20',
        'service_percent' => '0',
        'tax_percent' => '0',
        // AI menu import with the restaurant's own key (ai_key is encrypted, see AiProviders)
        'ai_provider' => '',
        'ai_model' => '',
        'ai_key' => '',
    ];

    /** @var array<string,array<string,string>> */
    private static array $memo = [];

    public static function get(string $key, ?string $default = null): string
    {
        return self::all()[$key] ?? $default ?? (self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $id = (string) tenant()?->getTenantKey();

        return self::$memo[$id] ??= array_merge(self::DEFAULTS,
            TenantSetting::query()->pluck('value', 'key')->map(fn ($v) => (string) $v)->all());
    }

    /** @param array<string,string|null> $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            TenantSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        self::flush();
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
