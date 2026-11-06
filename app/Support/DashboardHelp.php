<?php

namespace App\Support;

use App\Models\User;
use App\Services\Entitlements;

/**
 * How-to answers of the dashboard assistant (App\Services\DashboardAssistant): the tasks owners ask about most, each
 * with the page it happens on and the steps, quoting the buttons as the dashboard labels them (__(), so the steps read
 * in the user's language). A topic is offered only to whoever may open its app (App\Support\DashboardApps) and, with a
 * feature, while the plan has it. Its link opens the page, on the right settings tab or section when it has one.
 */
final class DashboardHelp
{
    /**
     * key => [app route the topic lives in (null = every signed-in user), route to open, query + #anchor, plan feature
     * or null, title, steps]
     *
     * @return array<string, array{app: ?string, route: string, query: array<string, string>, feature: ?string, title: string, steps: list<string>}>
     */
    public static function all(): array
    {
        $q = fn (string $label) => '«'.$label.'»';
        $t = fn (?string $app, string $route, array $query, ?string $feature, string $title, array $steps) => compact('app', 'route', 'query', 'feature', 'title', 'steps');

        return [
            'stop-orders' => $t('dash.settings', 'dash.home', [], 'online_ordering', __('Stop or start taking orders'), [
                'On the dashboard home page the first card reads '.$q(__('Taking orders: on')).'. Tap '.$q(__('Stop orders')).': the menu then shows the dishes and prices only, with no ordering (the hall, takeaway and delivery all stop).',
                'Tap '.$q(__('Start taking orders')).' on the same card to take orders again.',
                'The same switch is in '.$q(__('Settings')).' → '.$q(__('Orders')).': '.$q(__('Guests can order from the table QR')).'.',
            ]),
            'hall-mode' => $t('dash.settings', 'dash.settings', ['tab' => 'orders', '#' => 'dine-in'], null, __('How guests order inside the place'), [
                $q(__('Settings')).' → '.$q(__('Orders')).' → '.$q(__('In the hall (table QR and "in the place")')).', pick one:',
                $q(__('Send the order from the table')).': the order goes from the guest\'s phone to the order board.',
                $q(__('A list to show the waiter')).': the guest builds a list and shows it to the waiter, nothing is sent.',
                $q(__('Menu only')).': the guest only browses the menu inside the place; takeaway and delivery from the menu link are not affected.',
                'Then tap the save button at the bottom of the page.',
            ]),
            'online-ordering' => $t('dash.settings', 'dash.settings', ['tab' => 'orders', '#' => 'ordering'], 'online_ordering', __('Takeaway and delivery from the menu link'), [
                $q(__('Settings')).' → '.$q(__('Orders')).' → '.$q(__('Online ordering from your menu link (no table)')).': tick '.$q(__('Takeaway (pickup)')).' and/or '.$q(__('Delivery (uses your delivery zones)')).', and set '.$q(__('Ready in')).' minutes.',
                'Delivery shows to guests only once there is at least one area in '.$q(__('Delivery zones')).'.',
            ]),
            'busy-mode' => $t('dash.orders', 'dash.orders', [], 'order_board', __('Pause online orders for a while'), [
                'On '.$q(__('Orders')).' tap '.$q(__('Taking online orders')).' and choose until when (busy mode).',
                'Online, pickup and delivery orders from the menu link pause until then; table orders keep coming.',
            ]),
            'menu-items' => $t('dash.menu', 'dash.menu', [], null, __('Add or edit menu items'), [
                $q(__('Menu')).' → '.$q(__('Add item')).': name, description, price and a photo.',
                'Sizes or extras: add a group of choices to the item (for example Size, required, one choice) with the extra price of each choice.',
                'Drag the items or the categories to change their order.',
            ]),
            'sold-out' => $t('dash.menu', 'dash.menu', [], null, __('Mark an item sold out'), [
                'In '.$q(__('Menu')).' tap the item\'s '.$q(__('Available')).' switch: it shows as '.$q(__('Sold out')).' on the menu and cannot be ordered.',
                'Tap it again to make the item available.',
            ]),
            'import-menu' => $t('dash.menu', 'dash.import', [], null, __('Import a menu from Excel, a photo or a PDF'), [
                $q(__('Menu')).' → '.$q(__('More actions')).' → '.$q(__('Import menu')).'.',
                'Upload an Excel sheet (the template is on that page), or a photo / PDF of the printed menu, then review the items before saving.',
            ]),
            'printed-menu' => $t('dash.menu', 'dash.menu.print', [], 'printed_menu', __('Print the menu'), [
                $q(__('Menu')).' → '.$q(__('More actions')).' → '.$q(__('Printed menu')).'.',
                'Choose the '.$q(__('Template')).', the '.$q(__('Pages')).' and the '.$q(__('Language')).', then tap '.$q(__('Print / save as PDF')).'.',
                'It is made from the live menu, so print it again after changing prices.',
            ]),
            'tables-qr' => $t('dash.tables', 'dash.tables', [], null, __('Tables and their QR cards'), [
                $q(__('Tables & QR')).': add the tables by number range, then tap '.$q(__('QR')).' on a table to design and print its card.',
            ]),
            'branding' => $t('dash.settings', 'dash.settings', ['tab' => 'identity'], null, __('Logo, colours and font'), [
                $q(__('Settings')).' → '.$q(__('Identity')).': '.$q(__('Restaurant identity')).' (name, tagline, '.$q(__('Logo')).'), '.$q(__('Colors & font')).' and '.$q(__('Contact & social')).' (phone, WhatsApp, '.$q(__('Google review link')).').',
            ]),
            'menu-design' => $t('dash.settings', 'dash.settings', ['tab' => 'menu'], null, __('The look of the menu'), [
                $q(__('Settings')).' → '.$q(__('Menu')).' → '.$q(__('Menu design')).': '.$q(__('How the items are laid out')).', '.$q(__('When the guest taps an item')).' and '.$q(__('The categories row on the menu')).'.',
            ]),
            'languages' => $t('dash.settings', 'dash.settings', ['tab' => 'languages'], null, __('Menu language and currency'), [
                $q(__('Settings')).' → '.$q(__('Languages')).' → '.$q(__('Language & currency')).': '.$q(__('Menu opens in')).' (Arabic or English), the currency, and '.$q(__('Show the item name in Arabic and English together')).'.',
                $q(__('Extra menu languages')).' are on the same tab when the plan has them.',
            ]),
            'service-tax' => $t('dash.settings', 'dash.settings', ['tab' => 'orders', '#' => 'ordering'], null, __('Service charge and tax'), [
                $q(__('Settings')).' → '.$q(__('Orders')).': '.$q(__('Service %')).' and '.$q(__('Tax %')).' (0 = none).',
            ]),
            'delivery-zones' => $t('dash.zones', 'dash.zones', [], null, __('Delivery areas and fees'), [
                $q(__('Delivery zones')).' → '.$q(__('Add zone')).': the area\'s name, the delivery fee and the minimum order.',
            ]),
            'staff' => $t('dash.users', 'dash.users', [], null, __('Add a staff member'), [
                $q(__('Users & roles')).' → '.$q(__('Add user')).': their name, mobile or email, a '.$q(__('Password')).', the role (what they may open) and the branch.',
            ]),
            'branches' => $t('dash.branches', 'dash.branches', [], null, __('Branches and working hours'), [
                $q(__('Branches')).': each branch\'s address, phone and '.$q(__('Working hours')).'; '.$q(__('Add branch')).' for a new one (the plan sets how many).',
            ]),
            'offers' => $t('dash.marketing', 'dash.marketing', [], 'offers', __('Offers and discounts'), [
                $q(__('Offers & popups')).' → '.$q(__('New offer')).': the discount, the items or category and the days it runs.',
            ]),
            'coupons' => $t('dash.coupons', 'dash.coupons', [], null, __('Discount coupons'), [
                $q(__('Coupons & vouchers')).' → '.$q(__('New coupon')).': the code, the discount and when it expires.',
            ]),
            'reservations' => $t('dash.reservations', 'dash.reservations', [], 'reservations', __('Table reservations'), [
                $q(__('Reservations')).' → '.$q(__('Booking settings')).' to let guests book from the menu link; '.$q(__('New reservation')).' for a booking taken by phone.',
            ]),
            'whatsapp' => $t('dash.settings', 'dash.settings', ['tab' => 'messages', '#' => 'whatsapp'], null, __('WhatsApp messages to guests'), [
                $q(__('Settings')).' → '.$q(__('Messages')).' → '.$q(__('WhatsApp')).': which messages guests get, and '.$q(__('Send from your restaurant’s number')).'.',
            ]),
            'password' => $t(null, 'dash.security', [], null, __('Change your password or turn on two-factor sign-in'), [
                'Open the security page from the account menu: '.$q(__('Current password')).', '.$q(__('New password')).', then '.$q(__('Save the new password')).'.',
                'Two-factor sign-in for your own account is on the same page.',
            ]),
            'sample-data' => $t('dash.settings', 'dash.home', [], null, __('Remove the sample data'), [
                'While the account has sample data the dashboard shows '.$q(__('This is sample data — clear it when you start.')).' Tap '.$q(__('Clear the sample data')).': only the rows marked (تجربة) go, your own data stays.',
            ]),
            'subscription' => $t('dash.billing', 'dash.billing', [], null, __('The subscription and its invoices'), [
                $q(__('Subscription')).': the plan, the add-ons, the invoices and how to pay them.',
            ]),
        ];
    }

    /**
     * The topics this user may follow, with their link.
     *
     * @param  list<string>  $openApps  the app routes the user may open (DashboardApps::forUser)
     * @return array<string, array{title: string, steps: list<string>, url: string}>
     */
    public static function for(User $user, array $openApps): array
    {
        $rights = Entitlements::current();
        $out = [];
        foreach (self::all() as $key => $topic) {
            if (($topic['app'] !== null && ! in_array($topic['app'], $openApps, true)) || ($topic['feature'] && ! $rights->allows($topic['feature']))) {
                continue;
            }
            $query = $topic['query'];
            $anchor = isset($query['#']) ? '#'.$query['#'] : '';
            unset($query['#']);
            $out[$key] = ['title' => $topic['title'], 'steps' => $topic['steps'], 'url' => route($topic['route'], $query).$anchor];
        }

        return $out;
    }
}
