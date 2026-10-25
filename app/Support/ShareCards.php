<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The share images (Open Graph / Twitter cards) of the marketing pages: one 1200×630 PNG per page and language, so a
 * link sent on WhatsApp or Facebook shows a picture about that page (a feature page shows its own name and drawing).
 *
 * The images are drawn from resources/views/share-cards/card.blade.php by headless Chrome on a developer machine
 * (`php artisan egmenu:share-images`) and committed under public/og/{ar|en}/{key}.png: the server never draws them.
 * A page names its card with @section('share_card', 'features/pager'); a missing file falls back to og-image.png.
 * Every text on a card is copy the page itself already shows (SiteModules, the page headings).
 */
final class ShareCards
{
    /** languages the marketing site is shown in, each with its own set of cards */
    public const LOCALES = ['ar', 'en'];

    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /**
     * Every card for the current language, keyed by its file name under public/og/{locale}/ (without .png).
     * site = main (eghub.app) or directory (the earlier domain that keeps the restaurant directory).
     *
     * @return array<string, array{headline:string, subline:string, eyebrow:string, icon:string, visual:string, site:string, cta:bool}>
     */
    public static function all(): array
    {
        $cards = [
            'home' => self::card(__('one system for your whole place'), __('For restaurants, cafés, pastry shops and bakeries — from the menu to the till to delivery'), 'EgHub', 'sparkles', 'home'),
            'pricing' => self::card(__('Start free. Pay only when you need more.'), __('Prices in Egyptian pounds, paid by InstaPay or Vodafone Cash. No commission on your orders and no contract.'), __('Pricing'), 'tag', 'pricing'),
            'services' => self::card(__('We set it up for you, once'), __('One-time services from EgWeb for cafes and restaurants: menu setup and data entry, food photography, QR table stands, stickers, printed menus, staff training and a custom domain.'), __('Services'), 'sparkles', 'services'),
            'features' => self::card(__('Everything a cafe or a restaurant needs, one module at a time'), implode(' · ', array_column(SiteModules::groups(), 0)), __('Features'), 'layers', 'features'),
            'qr-code-generator' => self::card(__('Free QR generator with shapes, colours and your logo'), __('Turn a link, WhatsApp, Wi-Fi or a location into a QR code in your colours with your logo, and download it right away. No sign-up needed.'), __('Free tool'), 'qr', 'qr-tool'),
            'supported-screens' => self::card(__('Screens that work with EgHub'), __('Samsung, LG, Xiaomi, Fire TV, Google TV, Android boxes, computers and tablets: the steps for each one.'), __('TV menu screens'), 'tv', 'supported-screens'),
            'register' => self::card(__('Create your free account'), __('Your QR menu is free, forever. Add ordering, loyalty and the rest when you need them.'), __('Free plan · no card'), 'check', 'register'),
            'blog' => self::card(__('Notes from behind the counter'), __('Short, practical pieces on menus, ordering, loyalty and the daily running of a cafe or restaurant in Egypt. No fluff.'), __('Blog'), 'book', 'blog'),
            'terms' => self::card(__('Terms of service'), '', 'EgHub', 'file', 'legal', cta: false),
            'privacy' => self::card(__('Privacy policy'), '', 'EgHub', 'shield', 'legal', cta: false),
            'refund-policy' => self::card(__('Refund policy'), __('How EgHub subscriptions are paid, cancelled and refunded.'), 'EgHub', 'wallet', 'legal', cta: false),
        ];

        $groups = SiteModules::groups();
        foreach (SiteModules::all() as $slug => $module) {
            $cards["features/$slug"] = self::card($module['name'], $module['tagline'], $groups[$module['group']][0], $groups[$module['group']][1], $slug);
        }

        $directoryLine = __('Real menus with up-to-date prices, from the places themselves.');
        $cards['directory'] = self::card(__('Restaurants and cafes in Egypt'), $directoryLine, __('EgMenu directory'), 'store', 'directory', site: 'directory', cta: false);
        foreach (array_keys(Cities::ALL) as $city) {
            $cards["directory/$city"] = self::card(__('Restaurants and cafes in :city', ['city' => Cities::name($city)]), $directoryLine, __('EgMenu directory'), 'store', 'directory', site: 'directory', cta: false);
        }

        return $cards;
    }

    /** Public URL of a card for the current language, with a version stamp; og-image.png when the card was never drawn. */
    public static function url(?string $key): string
    {
        $path = $key === null ? null : self::path($key, self::locale());

        return versioned_asset($path !== null && is_file(public_path($path)) ? $path : 'og-image.png');
    }

    /** Text alternative of a card in the current language: what the card says, or $fallback (the page title) without a card. */
    public static function alt(?string $key, string $fallback = ''): string
    {
        $card = $key === null ? null : (self::all()[$key] ?? null);
        if ($card === null) {
            return $fallback;
        }

        return $card['subline'] !== '' ? $card['headline'].' — '.$card['subline'] : $card['headline'];
    }

    /** Path of a card's file under public/, e.g. og/ar/features/pager.png; null for a key that is not a safe file name. */
    public static function path(string $key, string $locale): ?string
    {
        if (! preg_match('~^[a-z0-9-]+(/[a-z0-9-]+)?$~', $key)) {
            return null;
        }

        return 'og/'.(in_array($locale, self::LOCALES, true) ? $locale : self::LOCALES[0])."/$key.png";
    }

    /** The card language for the page being shown: English cards for English pages, Arabic for the rest. */
    public static function locale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }

    /** @return array{headline:string, subline:string, eyebrow:string, icon:string, visual:string, site:string, cta:bool} */
    private static function card(string $headline, string $subline, string $eyebrow, string $icon, string $visual, string $site = 'main', bool $cta = true): array
    {
        $headline = Str::ucfirst($headline);

        return compact('headline', 'subline', 'eyebrow', 'icon', 'visual', 'site', 'cta');
    }
}
