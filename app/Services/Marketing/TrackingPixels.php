<?php

namespace App\Services\Marketing;

use App\Services\Entitlements;
use App\Services\Settings;

/**
 * Ad tracking pixels of the current restaurant (Meta Pixel, Google Analytics 4, TikTok Pixel), injected into the
 * public menu pages only — never the dashboard — with the standard events: PageView, ViewContent (item sheet),
 * AddToCart and Purchase (order placed, value in EGP). Available with timed offers or full branding, i.e. any paid plan
 * from Basic up.
 */
final class TrackingPixels
{
    public const FEATURE = 'offers|full_branding';

    /** setting => validation regex */
    public const RULES = [
        'meta_pixel_id' => 'regex:/^[0-9]{8,20}$/',
        'ga4_measurement_id' => 'regex:/^G-[A-Z0-9]{4,16}$/',
        'tiktok_pixel_id' => 'regex:/^[A-Z0-9]{8,32}$/',
    ];

    public static function enabled(): bool
    {
        return tenant() !== null && Entitlements::current()->allows(self::FEATURE);
    }

    /**
     * The ids to inject: empty strings when unset, invalid or not included in the plan.
     *
     * @return array{meta: string, ga4: string, tiktok: string}
     */
    public static function ids(): array
    {
        if (! self::enabled()) {
            return ['meta' => '', 'ga4' => '', 'tiktok' => ''];
        }
        $valid = fn (string $key) => preg_match(self::pattern($key), Settings::get($key)) === 1 ? Settings::get($key) : '';

        return ['meta' => $valid('meta_pixel_id'), 'ga4' => $valid('ga4_measurement_id'), 'tiktok' => $valid('tiktok_pixel_id')];
    }

    public static function any(): bool
    {
        return array_filter(self::ids()) !== [];
    }

    /** The PCRE pattern of a setting's validation rule. */
    public static function pattern(string $key): string
    {
        return substr(self::RULES[$key], strlen('regex:'));
    }
}
