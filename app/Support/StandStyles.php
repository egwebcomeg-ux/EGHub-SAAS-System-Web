<?php

namespace App\Support;

use App\Services\Entitlements;
use App\Services\Settings;

/**
 * Designs of the printed table QR cards (the card that goes into an acrylic or wooden table stand).
 * One palette per material feeds the print CSS, the on-screen stand preview and the SVG download.
 * The QR itself always stays dark on a white panel with its quiet zone; the accent only colors frames and text.
 */
final class StandStyles
{
    /** @var array<string, array{bg1:string, bg2:string, ink:string, muted:string, edge:string}> */
    public const STYLES = [
        'white' => ['bg1' => '#FFFFFF', 'bg2' => '#EDF2F8', 'ink' => '#13233F', 'muted' => '#5A6B85', 'edge' => '#CBD7E4'],
        'navy' => ['bg1' => '#21437A', 'bg2' => '#0E2246', 'ink' => '#FFFFFF', 'muted' => '#C9D6EC', 'edge' => '#0A1A38'],
        'wood' => ['bg1' => '#EBD4B4', 'bg2' => '#D2AE86', 'ink' => '#3B2412', 'muted' => '#6B4729', 'edge' => '#A97F55'],
        'frosted' => ['bg1' => '#F6F9FB', 'bg2' => '#DAE5EE', 'ink' => '#12324A', 'muted' => '#4E687E', 'edge' => '#BACCDA'],
    ];

    /** classic = the small cards grid (9 per A4), a6 = one A6 card per page, a4 = four A6 cards per A4 page */
    public const LAYOUTS = ['classic', 'a6', 'a4'];

    /** @var array{accent:string, waiter:bool, payment:bool, wifi_name:string, wifi_password:string, url:bool} */
    public const OPTION_DEFAULTS = [
        'accent' => '', 'waiter' => true, 'payment' => false, 'wifi_name' => '', 'wifi_password' => '', 'url' => true,
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::STYLES);
    }

    public static function label(string $style): string
    {
        return match ($style) {
            'navy' => __('Navy acrylic'),
            'wood' => __('Natural wood'),
            'frosted' => __('Frosted clear'),
            default => __('White acrylic'),
        };
    }

    /** @return array{0:string, 1:string} [name, one-line description] */
    public static function layoutLabel(string $layout): array
    {
        return match ($layout) {
            'a6' => [__('A6, one per page'), __('The stand size (10.5 × 14.8 cm), for a print shop or a small printer')],
            'a4' => [__('A6, four per A4 page'), __('Print on A4 and cut along the light lines')],
            default => [__('Small cards'), __('9 cards per A4 page, cut along the borders')],
        };
    }

    /** @return array{bg1:string, bg2:string, ink:string, muted:string, edge:string} */
    public static function palette(string $style): array
    {
        return self::STYLES[$style] ?? self::STYLES['white'];
    }

    /**
     * The restaurant's saved design, with defaults filled in and every value checked.
     *
     * @return array{style:string, layout:string, options:array{accent:string, waiter:bool, payment:bool, wifi_name:string, wifi_password:string, url:bool}}
     */
    public static function current(): array
    {
        $style = Settings::get('stand_style');
        $layout = Settings::get('stand_layout');
        $saved = json_decode(Settings::get('stand_options') ?: '{}', true);
        $saved = is_array($saved) ? $saved : [];

        $options = [];
        foreach (self::OPTION_DEFAULTS as $key => $default) {
            $value = $saved[$key] ?? $default;
            $options[$key] = is_bool($default) ? (bool) $value : mb_substr((string) $value, 0, 40);
        }
        if (! is_hex_color($options['accent'])) {
            $options['accent'] = is_hex_color(Settings::get('color_primary')) ? Settings::get('color_primary') : '#F26522';
        }

        return [
            'style' => array_key_exists($style, self::STYLES) ? $style : 'white',
            'layout' => in_array($layout, self::LAYOUTS, true) ? $layout : 'classic',
            'options' => $options,
        ];
    }

    /** Guests can call the waiter from a scanned table only when table ordering is in the plan and switched on. */
    public static function canCallWaiter(): bool
    {
        return Entitlements::current()->allows('qr_ordering') && Settings::get('ordering_enabled') === '1';
    }

    /** The brand color when it reads clearly on white (contrast 7:1 or more), else black: scanners need contrast. */
    public static function qrColor(?string $hex): string
    {
        if (! is_hex_color($hex)) {
            return '#000000';
        }
        $channels = array_map(function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, sscanf(ltrim((string) $hex, '#'), '%02x%02x%02x'));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05) >= 7 ? strtoupper((string) $hex) : '#000000';
    }

    /** Places a QR <svg> (from App\Services\Qr) inside another SVG at x/y with the given size. */
    public static function nestSvg(string $svg, float $x, float $y, float $size): string
    {
        return (string) preg_replace('/^<svg\b([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/',
            sprintf('<svg$1 x="%s" y="%s" width="%s" height="%s"', $x, $y, $size, $size), ltrim($svg), 1);
    }

    /** A font size that keeps one line of text inside $width (SVG has no wrapping). */
    public static function fitFont(string $text, float $max, float $width): float
    {
        $chars = max(1, mb_strlen($text));

        return round(min($max, $width / ($chars * 0.56)), 1);
    }
}
