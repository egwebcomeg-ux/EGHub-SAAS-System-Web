<?php

namespace App\Support;

use App\Models\Season;
use App\Services\Entitlements;
use App\Services\Settings;

/**
 * Public menu templates. Each one is a surface style (resources/views/menu/themes/styles) plus an item layout
 * (resources/views/menu/items/*). The restaurant's own colors still drive buttons and prices.
 */
final class MenuThemes
{
    /** key => [item layout, best for (business types)] */
    public const ALL = [
        'classic' => ['classic', ['cafe', 'restaurant', 'both', 'pastry', 'shop']],
        'grid' => ['grid', ['cafe', 'restaurant', 'both', 'pastry', 'shop']],
        'minimal' => ['minimal', ['restaurant', 'pastry', 'shop']],
        'dark' => ['classic', ['cafe']],
        'cafe' => ['cafe', ['cafe', 'both', 'pastry']],
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

    /**
     * Surface colors of each template: page, sheets/cards, inputs/rows, text, muted text, borders and the theme's own
     * accent (buttons, prices, active chips) with the text color on it. Dark templates are flagged so tinted notices
     * (offers, warnings) switch to their dark variants.
     *
     * @var array<string, array{dark:bool, bg:string, surface:string, surface2:string, text:string, muted:string, border:string, accent:string, accentText:string}>
     */
    public const PALETTES = [
        'classic' => ['dark' => false, 'bg' => '', 'surface' => '#FFFFFF', 'surface2' => '#F0F6FC', 'text' => '#061A2E', 'muted' => '#3E5F80', 'border' => '#E1EBF5', 'accent' => '#F26522', 'accentText' => '#FFFFFF'],
        'grid' => ['dark' => false, 'bg' => '', 'surface' => '#FFFFFF', 'surface2' => '#F0F6FC', 'text' => '#061A2E', 'muted' => '#3E5F80', 'border' => '#E1EBF5', 'accent' => '#F26522', 'accentText' => '#FFFFFF'],
        'minimal' => ['dark' => false, 'bg' => '', 'surface' => '#FFFFFF', 'surface2' => '#F4F4F5', 'text' => '#18181B', 'muted' => '#52525B', 'border' => '#E4E4E7', 'accent' => '#F26522', 'accentText' => '#FFFFFF'],
        'dark' => ['dark' => true, 'bg' => '#0F1420', 'surface' => '#1A2130', 'surface2' => '#232C3D', 'text' => '#F3F4F6', 'muted' => '#A3ACBE', 'border' => '#2E384D', 'accent' => '#FF8A4C', 'accentText' => '#10131A'],
        'cafe' => ['dark' => false, 'bg' => '#F6EFE6', 'surface' => '#FFFAF4', 'surface2' => '#F3E7D8', 'text' => '#3B2A20', 'muted' => '#7A604D', 'border' => '#EAD9C6', 'accent' => '#F26522', 'accentText' => '#FFFFFF'],
        'bistro' => ['dark' => true, 'bg' => '#141414', 'surface' => '#1E1E1E', 'surface2' => '#282828', 'text' => '#ECE6DA', 'muted' => '#A59C8C', 'border' => '#3A3428', 'accent' => '#C9A45C', 'accentText' => '#141414'],
        'fastfood' => ['dark' => false, 'bg' => '#FFF8E7', 'surface' => '#FFFFFF', 'surface2' => '#FFF1CC', 'text' => '#1F1300', 'muted' => '#6B5A3A', 'border' => '#F3DFAE', 'accent' => '#F26522', 'accentText' => '#FFFFFF'],
        'oriental' => ['dark' => false, 'bg' => '#F8F1E3', 'surface' => '#FFFDF8', 'surface2' => '#F5EAD6', 'text' => '#3A2316', 'muted' => '#6E5643', 'border' => '#E3CFA8', 'accent' => '#F26522', 'accentText' => '#FFFFFF'],
    ];

    /** The template the current restaurant's guest pages use (themes need the plan feature menu_themes). */
    public static function current(array $settings): string
    {
        $theme = (string) ($settings['menu_theme'] ?? 'classic');

        return Entitlements::current()->allows('menu_themes') && array_key_exists($theme, self::ALL) ? $theme : 'classic';
    }

    /**
     * CSS variables of a template for this restaurant. The accent is the restaurant's own main color when it set
     * one (anything but the platform default), else the template's accent; the page background follows the
     * restaurant's background color on the light templates that have none of their own.
     *
     * @return array{dark:bool, vars:array<string,string>}
     */
    public static function tokens(string $theme, array $settings): array
    {
        $p = self::PALETTES[$theme] ?? self::PALETTES['classic'];
        $own = static fn (string $key) => is_hex_color($settings[$key] ?? '') ? strtoupper($settings[$key]) : null;
        $primary = $own('color_primary');
        $custom = $primary !== null && $primary !== strtoupper(Settings::DEFAULTS['color_primary']);
        $accent = $custom ? $primary : $p['accent'];
        $bg = $p['bg'] !== '' ? $p['bg'] : ($own('color_bg') ?? '#FFFFFF');

        return ['dark' => $p['dark'], 'vars' => [
            '--menu-primary' => $primary ?? '#F26522',
            '--menu-secondary' => $own('color_secondary') ?? '#0A2540',
            '--menu-bg' => $bg,
            '--menu-surface' => $p['surface'],
            '--menu-surface-2' => $p['surface2'],
            '--menu-text' => $p['text'],
            '--menu-muted' => $p['muted'],
            '--menu-border' => $p['border'],
            '--menu-accent' => $accent,
            '--menu-accent-text' => $custom ? self::textOn($accent) : $p['accentText'],
        ]];
    }

    /**
     * A running season paints the accent (buttons, prices, chips) with its own color, on every guest surface.
     *
     * @param  array{dark:bool, vars:array<string,string>}  $tokens
     * @return array{dark:bool, vars:array<string,string>}
     */
    public static function withSeason(array $tokens, ?Season $season): array
    {
        if ($season && is_hex_color($season->accent)) {
            $accent = strtoupper((string) $season->accent);
            $tokens['vars']['--menu-accent'] = $accent;
            $tokens['vars']['--menu-accent-text'] = self::textOn($accent);
            $tokens['vars']['--menu-season'] = $accent;
        }

        return $tokens;
    }

    /**
     * Whether the logo sits on a white tile (setting logo_background): auto = only on light templates, so a
     * transparent logo shows on the dark ones as drawn.
     */
    public static function logoTile(string $theme, array $settings): bool
    {
        return match ($settings['logo_background'] ?? 'auto') {
            'white' => true,
            'none' => false,
            default => ! (self::PALETTES[$theme]['dark'] ?? false),
        };
    }

    /** Black or white, whichever reads better on $hex (WCAG relative luminance). */
    public static function textOn(string $hex): string
    {
        $channel = static function (string $two): float {
            $c = hexdec($two) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $hex = ltrim($hex, '#');
        $l = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        // white stays on mid-tones (big bold button text needs 3:1); light colors get near-black text
        return 1.05 / ($l + 0.05) >= 3 ? '#FFFFFF' : '#111111';
    }
}
