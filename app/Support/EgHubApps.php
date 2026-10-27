<?php

namespace App\Support;

/**
 * The EgHub apps the marketing site lists on /apps, honestly: an app is "available" only when it can really be
 * downloaded today (a file we serve, `download`); every other one is "coming soon", and no page claims a store
 * listing that does not exist. Until an app ships, what it adds is named as coming and what already works in the
 * browser is said plainly.
 */
final class EgHubApps
{
    public const AVAILABLE = 'available';

    public const SOON = 'soon';

    /** Downloadable from this page as an early build, before the store: works, still being tried on real devices. */
    public const BETA = 'beta';

    /**
     * @return array<string, array{key:string, name:string, icon:string, for:string, summary:string, status:string,
     *     download:?string, file:?string, features:list<string>, today:string, module:?string}>
     */
    public static function all(): array
    {
        $apps = [
            [
                'key' => 'screens', 'name' => __('EgHub Screens'), 'icon' => 'tv', 'for' => __('Android TV and TV boxes'),
                'summary' => __('The TV player of the menu screens: your menu, pictures, videos, offers and ready order numbers on the shop TVs.'),
                'status' => self::AVAILABLE, 'file' => 'tv.apk', 'module' => 'tv-screens',
                'features' => [
                    __('Pair the TV with the 6-digit code it shows'),
                    __('Updates by itself when you change a price'),
                    __('Keeps playing the last playlist when the internet drops'),
                ],
                'today' => __('Smart TVs without Android open eghub.app/tv in their browser.'),
            ],
            [
                'key' => 'pos', 'name' => __('EgHub Cashier'), 'icon' => 'cash', 'for' => __('Android tablets and cashier devices (Sunmi and others)'),
                'summary' => __('The POS on an Android till that prints by itself on your receipt and kitchen printers.'),
                'status' => self::BETA, 'file' => 'apps/eghub-pos.apk', 'module' => 'pos',
                'features' => [
                    __('The receipt prints by itself after the payment, and the cash drawer opens'),
                    __('A kitchen ticket for each station as soon as the order arrives'),
                    __('Bluetooth, USB, network and Sunmi built-in printers, 58 or 80 mm'),
                    __('Each copy prints once, even with several devices'),
                ],
                'today' => __('Today the POS runs in the browser and prints the receipt through the browser\'s print window.'),
            ],
            [
                'key' => 'restaurant', 'name' => __('EgHub Manager'), 'icon' => 'bell', 'for' => __('The owner\'s and manager\'s Android phone'),
                'summary' => __('Your dashboard on your phone, with a notification for every order that needs you.'),
                'status' => self::BETA, 'file' => 'apps/eghub-restaurant.apk', 'module' => 'online-ordering',
                'features' => [
                    __('A notification for each new online or call-center order'),
                    __('The guest\'s answer to the WhatsApp confirmation'),
                    __('New table bookings'),
                    __('Each person chooses their own notifications'),
                ],
                'today' => __('Today the dashboard works in the phone browser, and the bell in it shows new orders while it is open.'),
            ],
            [
                'key' => 'waiter', 'name' => __('EgHub Waiter'), 'icon' => 'users', 'for' => __('The waiters\' Android phones'),
                'summary' => __('Table orders from the waiter\'s phone, and a notification when an order is ready to serve.'),
                'status' => self::BETA, 'file' => 'apps/eghub-waiter.apk', 'module' => 'table-ordering',
                'features' => [
                    __('Take a table\'s order on the phone'),
                    __('«The order is ready» on the phone, even with the app closed'),
                ],
                'today' => __('Today waiters take orders from the POS screen in the phone browser.'),
            ],
            [
                'key' => 'driver', 'name' => __('EgHub Driver'), 'icon' => 'scooter', 'for' => __('The delivery riders\' Android phones'),
                'summary' => __('The driver screen as an app that keeps sharing the location with the screen off.'),
                'status' => self::BETA, 'file' => 'apps/eghub-driver.apk', 'module' => 'delivery-drivers',
                'features' => [
                    __('The location keeps reaching the guest\'s map in the background'),
                    __('It stops by itself once the order is delivered'),
                ],
                'today' => __('Today the driver screen works in the phone browser and shares the location while it is open.'),
            ],
            [
                'key' => 'kiosk', 'name' => __('EgHub Kiosk'), 'icon' => 'tablet', 'for' => __('Android tablets at the counter'),
                'summary' => __('The self-order kiosk locked on the tablet, with the screen always on.'),
                'status' => self::BETA, 'file' => 'apps/eghub-kiosk.apk', 'module' => 'kiosk',
                'features' => [
                    __('Guests cannot leave the kiosk page'),
                    __('The screen never sleeps'),
                ],
                'today' => __('Today the kiosk opens from its link in the tablet browser.'),
            ],
        ];

        return collect($apps)->map(fn (array $a) => $a + [
            // a download only for a file that is really on the server
            'download' => in_array($a['status'], [self::AVAILABLE, self::BETA], true) && $a['file'] && is_file(public_path($a['file'])) ? platform_url(null, $a['file']) : null,
        ])->map(fn (array $a) => in_array($a['status'], [self::AVAILABLE, self::BETA], true) && $a['download'] === null ? ['status' => self::SOON] + $a : $a)
            ->keyBy('key')->all();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::AVAILABLE => __('Available'),
            self::BETA => __('Early version'),
            default => __('Coming soon'),
        };
    }
}
