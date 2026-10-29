<?php

namespace App\Support;

use App\Models\User;
use App\Services\Entitlements;

/**
 * Video tutorials of the dashboard ("شرح بالفيديو"), like Odoo's: a short recording of each feature on a demo
 * restaurant, with a caption for every step. The videos are public/tutorials/{key}.mp4, recorded from the scenarios
 * in docs/tutorials (Playwright on the demo restaurant, then ffmpeg); a tutorial without its file is not offered.
 * Each one belongs to a dashboard page (route) and shows to staff who may open it, when the plan has its feature.
 */
final class Tutorials
{
    /**
     * key => [route, permission ('a|b' = any, null = everyone), plan feature or null, title, steps]
     *
     * @return array<string, array{route:string, permission:?string, feature:?string, title:string, steps:list<string>}>
     */
    public static function all(): array
    {
        $t = fn (string $route, ?string $permission, ?string $feature, string $title, array $steps) => compact('route', 'permission', 'feature', 'title', 'steps');

        return [
            'pos-sale' => $t('dash.pos', 'pos.use', 'pos', __('Selling at the cashier'), [
                __('Open the shift with the cash in the drawer.'),
                __('Tap the items; choose the size or extras when asked.'),
                __('Tap "Pay", choose how the guest pays and type what they handed over: the change shows by itself.'),
                __('Print the receipt or send it on WhatsApp.'),
            ]),
            'order-board' => $t('dash.orders', 'orders.view', 'order_board', __('The order board'), [
                __('Every order lands here: tables, online, cashier and call center.'),
                __('Move it: preparing, ready (the guest gets a message), served.'),
                __('Cancelling needs a manager; a paid order is refunded from the cashier.'),
            ]),
            'order-flow-settings' => $t('dash.settings', 'settings.manage', null, __('How orders move'), [
                __('Settings → Orders: a paid cashier sale goes to the kitchen or is done once paid (automatic follows the screens you use).'),
                __('A paid order left "ready" closes by itself after the minutes you set.'),
                __('Ask for the guest\'s mobile confirmed by a WhatsApp code before delivery or every online order.'),
            ]),
            'company-visits' => $t('dash.corporate.bookings', 'contracts.manage|reservations.manage', 'corporate_vouchers', __('Company visits'), [
                __('Record what the company paid on its statement.'),
                __('Make the company\'s private link and send it: it asks for visits and sees its account.'),
                __('Confirm each visit with the meal price per guest: the meals go on a code for the visit day, the rest on a voucher for the company.'),
                __('Send the code to the rep on WhatsApp; after the visit what the meals did not use moves to the company\'s voucher.'),
            ]),
        ];
    }

    /** @return array{route:string, permission:?string, feature:?string, title:string, steps:list<string>, key:string, video:string}|null */
    public static function find(string $key): ?array
    {
        $tutorial = self::all()[$key] ?? null;
        if (! $tutorial || ! is_file(public_path("tutorials/$key.mp4"))) {
            return null;
        }

        return $tutorial + ['key' => $key, 'video' => versioned_asset("tutorials/$key.mp4")];
    }

    /** The tutorials of a dashboard page this user may watch. @return list<array<string,mixed>> */
    public static function forRoute(?string $route, User $user): array
    {
        return array_values(array_filter(self::visibleTo($user), fn (array $t) => $t['route'] === $route));
    }

    /** Every tutorial with its video that this user may open the page of. @return list<array<string,mixed>> */
    public static function visibleTo(User $user): array
    {
        $rights = Entitlements::current();
        $out = [];
        foreach (array_keys(self::all()) as $key) {
            $t = self::find($key);
            if (! $t || ($t['feature'] && ! $rights->allows($t['feature']))) {
                continue;
            }
            if ($t['permission'] && ! collect(explode('|', $t['permission']))->contains(fn (string $p) => $user->can($p))) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }
}
