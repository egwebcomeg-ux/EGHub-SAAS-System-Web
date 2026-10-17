<?php

namespace App\Services\Pwa;

use App\Services\Settings;

/**
 * Progressive web app declarations: every restaurant menu installs as its own app (name, colors and icon from the
 * restaurant's settings, scoped to its path or host), the dashboard as "EgMenu". layouts.base reads forRequest()
 * to add the manifest link, the Apple metas and the service worker registration on the right pages only.
 */
final class Pwa
{
    public const THEME = '#1E2D4F';

    public const BACKGROUND = '#FFFFFF';

    /**
     * What the current page declares, or null for pages that are not an app (landing, admin, print sheets, screens).
     *
     * @return array{manifest:string, sw:string, scope:string, theme:string, icon:string, title:string, key:string, install:bool}|null
     */
    public static function forRequest(): ?array
    {
        $name = (string) request()->route()?->getName();
        if (str_starts_with($name, 'dash.')) {
            return [
                'manifest' => route('dash.manifest'), 'sw' => route('dash.sw'), 'scope' => '/', 'theme' => self::THEME,
                'icon' => versioned_asset('icons/egmenu-192.png'), 'title' => 'EgMenu', 'key' => 'dashboard', 'install' => false,
            ];
        }
        if (! tenant() || ! preg_match('/^(menu|path|custom)\.(show|about)$/', $name)) {
            return null;
        }
        $s = Settings::all();

        return [
            'manifest' => route('menu.manifest'), 'sw' => route('menu.sw'), 'scope' => self::scope(),
            'theme' => is_hex_color($s['color_secondary']) ? $s['color_secondary'] : self::THEME,
            'icon' => route('menu.icon', ['size' => 192]), 'title' => self::brand($s), 'key' => (string) tenant()->getTenantKey(),
            'install' => str_ends_with($name, '.show'),
        ];
    }

    /**
     * The restaurant's manifest. start_url and scope are relative to the host serving it: "/{slug}/" on the main
     * domain, "/" on the subdomain alias or a custom domain (route('menu.*') is rewritten per host).
     *
     * @return array<string,mixed>
     */
    public static function tenantManifest(): array
    {
        $s = Settings::all();
        $brand = self::brand($s);
        $scope = self::scope();
        $locale = app()->getLocale();

        return [
            'id' => $scope,
            'name' => $brand,
            'short_name' => mb_substr($brand, 0, 12),
            'description' => trim(tr($s['tagline'], $s['tagline_en'])) ?: __('Menu of :name', ['name' => $brand]),
            'lang' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'start_url' => $scope.'?source=pwa',
            'scope' => $scope,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => is_hex_color($s['color_bg']) ? $s['color_bg'] : self::BACKGROUND,
            'theme_color' => is_hex_color($s['color_secondary']) ? $s['color_secondary'] : self::THEME,
            'icons' => array_map(fn (int $size) => [
                'src' => route('menu.icon', ['size' => $size]), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any maskable',
            ], PwaIcons::SIZES),
        ];
    }

    /** @return array<string,mixed> */
    public static function dashboardManifest(): array
    {
        $locale = app()->getLocale();

        return [
            'id' => '/',
            'name' => 'EgMenu',
            'short_name' => 'EgMenu',
            'description' => __('Your restaurant dashboard: menu, orders, tables and customers.'),
            'lang' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => self::BACKGROUND,
            'theme_color' => self::THEME,
            'icons' => [
                ['src' => versioned_asset('icons/egmenu-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => versioned_asset('icons/egmenu-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => versioned_asset('icons/egmenu-maskable-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => versioned_asset('icons/egmenu-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
    }

    /** Path the restaurant's app owns on this host: "/{slug}/" or "/". */
    public static function scope(): string
    {
        return rtrim((string) parse_url(route('menu.show'), PHP_URL_PATH), '/').'/';
    }

    /** @param array<string,string> $s */
    private static function brand(array $s): string
    {
        return trim(tr($s['brand_name'], $s['brand_name_en'])) ?: (string) tenant()->name;
    }
}
