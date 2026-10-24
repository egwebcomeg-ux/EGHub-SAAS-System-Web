<?php

namespace App\Support;

/**
 * EgWeb's one-time services shown on eghub.app/services, next to the subscriptions. Edit prices and copy here.
 * `price` is in EGP and means "from" (null = priced on request); `items` lists sizes priced per piece.
 * Requests from the page are stored as service_requests and emailed to the platform admins.
 */
final class SiteServices
{
    /** Where custom domains and branded email are priced and booked. */
    public const EGWEB_URL = 'https://egweb.com.eg';

    /**
     * @return array<string, array{key:string, icon:string, name:string, summary:string, includes:list<string>,
     *     delivery:string, price:?int, unit:string, note:?string, items:list<array{0:string,1:string,2:int}>, link:?string}>
     */
    public static function all(): array
    {
        static $cache = [];

        return $cache[app()->getLocale()] ??= collect(self::definitions())->keyBy('key')->all();
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    private static function definitions(): array
    {
        return [
            [
                'key' => 'menu_setup', 'icon' => 'pencil',
                'name' => __('Menu setup & data entry'),
                'summary' => __('Send us your paper menu, a photo or a PDF and we enter every item, price, size and add-on for you, in Arabic and English.'),
                'includes' => [
                    __('Categories, items, prices and options entered and checked'),
                    __('English names written for you'),
                    __('Your logo, colors and the menu design set up'),
                    __('Tables and QR codes ready to print'),
                ],
                'delivery' => __('1 to 3 working days'), 'price' => 300, 'unit' => __('per menu'),
                'note' => __('Up to 80 items; bigger menus are priced by size.'), 'items' => [], 'link' => null,
            ],
            [
                'key' => 'photography', 'icon' => 'sparkles',
                'name' => __('Food photography & photo editing'),
                'summary' => __('Photos that make people order: a shoot at your place, or editing the photos you already have so the whole menu looks the same.'),
                'includes' => [
                    __('A photographer at your place with lights and backgrounds'),
                    __('Editing: light, color, background clean-up and cropping'),
                    __('Photos sized for the menu, the TV screens and social media'),
                ],
                'delivery' => __('Shoot day agreed with you, photos within 5 working days'), 'price' => null, 'unit' => '',
                'note' => __('Priced by the number of dishes and the place: tell us what you need.'), 'items' => [], 'link' => null,
            ],
            [
                'key' => 'print', 'icon' => 'printer',
                'name' => __('Branded print products'),
                'summary' => __('Everything the guest touches on the table, printed with your logo and the QR of your EgHub menu.'),
                'includes' => [
                    __('Design with your logo and colors, sent to you before printing'),
                    __('Your real menu QR code on every piece'),
                    __('Delivery in Cairo and Giza, shipping to other cities'),
                ],
                'delivery' => __('5 to 7 working days after you approve the design'), 'price' => 15, 'unit' => __('per piece'),
                'note' => __('Quantity discounts from 20 pieces.'),
                'items' => [
                    [__('Acrylic QR table stand'), 'A6 · 10×15 cm', 150],
                    [__('Acrylic QR table stand'), 'A5 · 15×21 cm', 220],
                    [__('Table sticker'), '10×10 cm', 15],
                    [__('Printed menu, laminated'), 'A4', 60],
                    [__('Printed menu, folded'), 'A3', 120],
                    [__('Menu board'), '60×90 cm', 900],
                ],
                'link' => null,
            ],
            [
                'key' => 'training', 'icon' => 'users',
                'name' => __('Onboarding & training session'),
                'summary' => __('One session with your team, online or at your place: the dashboard, orders, the POS and the daily routine.'),
                'includes' => [
                    __('About 90 minutes with the owner, cashiers and waiters'),
                    __('Your settings reviewed with you'),
                    __('A short written guide for new staff'),
                ],
                'delivery' => __('Booked within 2 working days'), 'price' => 250, 'unit' => __('per session'),
                'note' => __('At your place in Cairo and Giza; online anywhere.'), 'items' => [], 'link' => null,
            ],
            [
                'key' => 'payment_gateway', 'icon' => 'wallet',
                'name' => __('Online payment with your own gateway'),
                'summary' => __('We connect your menu and online orders to the payment gateway you already have a contract with, so guests pay by card or wallet and the money goes straight to your account.'),
                'includes' => [
                    __('Fawry, Kashier, Paymob, Geidea or any gateway with an API'),
                    __('Card and mobile-wallet payment on your online orders'),
                    __('Paid orders marked automatically on the order board'),
                    __('Your contract and keys stay yours; EgHub takes no commission'),
                ],
                'delivery' => __('Timeline agreed after we review your gateway account'), 'price' => null, 'unit' => '',
                'note' => __('You need an active merchant account with the gateway; we help you through it.'), 'items' => [], 'link' => null,
            ],
            [
                'key' => 'domain_email', 'icon' => 'globe',
                'name' => __('Custom domain & branded email'),
                'summary' => __('Your menu on your own name (mycafe.com) and email addresses like hello@mycafe.com, from EgWeb.'),
                'includes' => [
                    __('Domain booking and renewal'),
                    __('Connected to your EgHub menu and QR codes'),
                    __('Branded email accounts on your domain'),
                ],
                'delivery' => __('Usually the same day'), 'price' => null, 'unit' => '',
                'note' => __('Domain and email prices are listed on egweb.com.eg.'), 'items' => [], 'link' => self::EGWEB_URL,
            ],
        ];
    }
}
