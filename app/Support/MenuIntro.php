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

    public static function isBot(string $userAgent): bool
    {
        return $userAgent !== '' && preg_match(self::BOTS, $userAgent) === 1;
    }
}
