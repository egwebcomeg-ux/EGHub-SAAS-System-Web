<?php

namespace App\Support;

/**
 * The ready designs of a "promo" screen slide («تصميمات جاهزة»). Each one is drawn by the player itself
 * (resources/views/screen/partials/designs.blade.php: CSS + an ES5 function), never an image, so it takes the
 * restaurant's logo and colours, an item's live price and photo or free text, fits landscape and portrait screens and
 * follows the screen's text size. Backgrounds are solid only: the dark colour, white or the brand colour (bg option).
 * The dashboard shows the same drawing in small previews (the design preview page).
 */
final class ScreenDesigns
{
    /**
     * key => [label, hint, whether it wants a photo]
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function all(): array
    {
        return [
            'spotlight' => [__('Spotlight'), __('Big photo with the name and price beside it'), true],
            'poster' => [__('Poster'), __('The photo fills the top, the name and price under it'), true],
            'price-tag' => [__('Price tag'), __('The price in a big round tag over the photo'), true],
            'split' => [__('Two colours'), __('The screen in two solid colours: words on one side, photo on the other'), true],
            'new-item' => [__('New item'), __('A big «New» label to launch an item'), true],
            'discount' => [__('Discount'), __('The old price crossed out and the new one big'), true],
            'minimal' => [__('Clean'), __('White and quiet: a round photo, the name and the price'), true],
            'frame' => [__('Frame'), __('The photo in a thick frame of your colour'), true],
            'stamp' => [__('Stamp'), __('A round price stamp at the corner of the photo'), true],
            'combo' => [__('Combo'), __('For a meal or a bundle: what is in it and its price'), true],
            'menu-card' => [__('Card'), __('One card in the middle like a menu page'), true],
            'ticket' => [__('Coupon'), __('A cut-out coupon with the price'), false],
            'countdown' => [__('Countdown'), __('The offer with the time left counting down'), true],
            'headline' => [__('Announcement'), __('Big words only: opening hours, a new branch, any news'), false],
            'scan' => [__('Scan to order'), __('Your menu QR, big enough to scan from the queue'), false],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function label(?string $key): string
    {
        return self::all()[$key ?? ''][0] ?? (string) $key;
    }

    /**
     * Words the designs print, in the screen's language.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'new' => __('New'), 'offer' => __('Offer'), 'now' => __('Now'), 'instead_of' => __('instead of'),
            'ends_in' => __('Ends in'), 'days' => __('days'), 'scan' => __('Scan to order'),
            'scan_hint' => __('Open the camera and point it at the code'), 'combo' => __('Combo'), 'only' => __('Only'),
        ];
    }
}
