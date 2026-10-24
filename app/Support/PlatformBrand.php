<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's own look (admin › الهوية والشكل): site name, logos, favicon and the three brand colors. Stored in
 * PlatformSetting; the defaults are the EgHub identity, so nothing changes until an admin saves. The colors reach the
 * pages as CSS custom properties in the base layout (they override the Tailwind theme variables), never by
 * rebuilding the CSS. Guest menus keep the restaurant's own colors.
 */
final class PlatformBrand
{
    public const DEFAULTS = [
        'brand_site_name' => 'EgHub',
        'brand_color_primary' => '#F26522',   // orange: buttons, links, accents
        'brand_color_secondary' => '#005BA6', // blue: the "menu" of the wordmark, secondary accents
        'brand_color_navy' => '#0A2540',      // navy: dark surfaces (sidebar, footer)
        'brand_logo' => '',                   // logo for light backgrounds (public disk path)
        'brand_logo_on_dark' => '',           // white / light logo for dark backgrounds
        'brand_favicon' => '',
    ];

    public const COLORS = ['brand_color_primary', 'brand_color_secondary', 'brand_color_navy'];

    private const CACHE_KEY = 'platform_brand';

    /** @var array<string, string>|null */
    private static ?array $memo = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        try {
            $saved = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()
                ->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')
                ->filter(fn ($value) => $value !== null && $value !== '')->map(fn ($value) => (string) $value)->all());
        } catch (\Throwable) {
            return self::DEFAULTS; // error pages render while the database is down
        }

        return self::$memo = array_merge(self::DEFAULTS, $saved);
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function siteName(): string
    {
        return self::get('brand_site_name') ?: self::DEFAULTS['brand_site_name'];
    }

    /** Public URL of an uploaded logo/favicon, or null when none was uploaded. */
    public static function url(string $key): ?string
    {
        $path = self::get($key);

        return $path === '' ? null : '/storage/'.$path;
    }

    /** @param array<string, string|null> $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            PlatformSetting::put($key, $value);
        }
        self::flush();
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** Has an admin picked colors other than the EgHub identity? */
    public static function hasCustomColors(): bool
    {
        foreach (self::COLORS as $key) {
            if (strtolower(self::get($key)) !== strtolower(self::DEFAULTS[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Tailwind theme variables derived from the three colors (same steps as resources/css/app.css), e.g.
     * "--color-brand-600:#f26522;...". Empty while the colors are the defaults, so pages stay exactly as built.
     */
    public static function cssVariables(): string
    {
        if (! self::hasCustomColors()) {
            return '';
        }

        return collect(self::palette(self::get('brand_color_primary'), self::get('brand_color_secondary'), self::get('brand_color_navy')))
            ->map(fn (string $hex, string $name) => "--color-$name:$hex")->implode(';');
    }

    /** @return array<string, string> */
    public static function palette(string $primary, string $secondary, string $navy): array
    {
        return [
            'brand-50' => self::mix($primary, '#ffffff', 0.93),
            'brand-100' => self::mix($primary, '#ffffff', 0.86),
            'brand-200' => self::mix($primary, '#ffffff', 0.68),
            'brand-500' => self::mix($primary, '#ffffff', 0.22),
            'brand-600' => strtolower($primary),
            'brand-700' => self::mix($primary, '#000000', 0.1),
            // primary buttons: white text needs the deeper shades
            'brand-800' => self::mix($primary, '#000000', 0.2),
            'brand-900' => self::mix($primary, '#000000', 0.36),
            'navy-600' => strtolower($secondary),
            'navy-700' => self::mix($secondary, '#000000', 0.25),
            'navy-800' => strtolower($navy),
            'navy-900' => self::mix($navy, '#000000', 0.3),
        ];
    }

    /** WCAG contrast ratio between two colors (1 to 21). White text on buttons wants 4.5, large text and icons 3. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
    }

    /** Mixes $hex with $with by $amount (0 = $hex, 1 = $with). */
    private static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($with);
        $channel = fn (int $x, int $y) => str_pad(dechex((int) round($x + ($y - $x) * $amount)), 2, '0', STR_PAD_LEFT);

        return '#'.$channel($r1, $r2).$channel($g1, $g2).$channel($b1, $b2);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private static function luminance(string $hex): float
    {
        $linear = function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }
}
