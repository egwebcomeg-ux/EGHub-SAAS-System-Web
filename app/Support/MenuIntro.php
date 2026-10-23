<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Branded splash on the first open of the public menu per browser session (setting menu_intro).
 * The page decides before its first paint whether to play it (sessionStorage, prefers-reduced-motion); crawlers
 * never get the markup, so search engines and link previews see the menu itself.
 */
final class MenuIntro
{
    public const STYLES = ['off', 'logo', 'full'];

    public const BACKGROUNDS = ['auto', 'brand', 'light'];

    /** User agents of crawlers and link-preview fetchers. */
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview|embedly|bingpreview/i';

    /** The intro style for this request, or 'off'. */
    public static function style(array $settings, Request $request): string
    {
        $style = in_array($settings['menu_intro'] ?? 'logo', self::STYLES, true) ? $settings['menu_intro'] : 'logo';
        if ($style === 'off' || self::isBot((string) $request->userAgent())) {
            return 'off';
        }

        return $style;
    }

    /**
     * The colours of the intro screen (setting intro_background): brand = the header colour with the accent glow,
     * light = the page background with accent rings and dots and the logo as drawn. auto picks light when the page
     * background is light and the logo is shown bare (no white tile): a dark logo on the dark brand colour would vanish.
     *
     * @return 'brand'|'light'
     */
    public static function background(array $settings, bool $logoTile, string $pageBackground): string
    {
        $choice = in_array($settings['intro_background'] ?? 'auto', self::BACKGROUNDS, true) ? $settings['intro_background'] : 'auto';
        if ($choice !== 'auto') {
            return $choice;
        }
        $lightPage = is_hex_color($pageBackground) && MenuThemes::textOn($pageBackground) !== '#FFFFFF';
        $bareLogo = (string) ($settings['logo'] ?? '') !== '' && ! $logoTile;

        return $lightPage && $bareLogo ? 'light' : 'brand';
    }

    public static function isBot(string $userAgent): bool
    {
        return $userAgent !== '' && preg_match(self::BOTS, $userAgent) === 1;
    }
}
