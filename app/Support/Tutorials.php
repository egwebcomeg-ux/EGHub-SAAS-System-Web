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
            'menu-items' => $t('dash.menu', 'menu.manage', null, __('Adding menu items'), [
                __('Add a category in Arabic and English.'),
                __('Tap "+ Item" in the category: name, description and price.'),
                __('Add a group of choices (e.g. Filling: required) with a price for each choice.'),
                __('Save: the item shows on the menu; tap "Available" to hide it when it runs out.'),
            ]),
            'tables-qr' => $t('dash.tables', 'tables.manage', null, __('Tables & QR cards'), [
                __('Add tables by number range (from … to).'),
                __('Edit a table\'s name or seats.'),
                __('Tap "QR" to design the stand card: material, colour, Wi-Fi.'),
                __('Save the design, then print or download the SVG.'),
            ]),
            'guest-qr-order' => $t('dash.tables', 'tables.manage', 'qr_ordering', __('Ordering from the table'), [
                __('The guest scans the table QR: the menu knows the table.'),
                __('They pick items and choices and send the order from their phone.'),
                __('The order lands on the order board with a bell.'),
                __('The Tables tab gathers each table\'s orders in one bill.'),
            ]),
            'order-flow-settings' => $t('dash.settings', 'settings.manage', null, __('How orders move'), [
                __('Settings → Orders: how guests order in the hall (send, list for the waiter, menu only).'),
                __('A paid cashier sale goes to the kitchen or is done once paid (automatic follows the screens you use).'),
                __('A paid order left "ready" closes by itself; an online order nobody accepts is cancelled after the minutes you set.'),
                __('Ask for the guest\'s mobile confirmed by a WhatsApp code before delivery or every online order.'),
            ]),
            'pos-sale' => $t('dash.pos', 'pos.use', 'pos', __('Selling at the cashier'), [
                __('Open the shift with the cash in the drawer.'),
                __('Tap the items; choose the size or extras when asked.'),
                __('Tap "Pay", choose how the guest pays and type what they handed over: the change shows by itself.'),
                __('Print the receipt or send it on WhatsApp.'),
            ]),
            'order-board' => $t('dash.orders', 'orders.view', 'order_board', __('The order board'), [
                __('Every order lands here: tables, online, cashier and call center.'),
                __('Filter by kitchen, ready or order type; search by order number; pause online orders when busy.'),
                __('Move it: preparing, ready (the guest gets a message), served.'),
                __('Cancelling needs a manager; a paid order is refunded from the cashier.'),
            ]),
            'kitchen-display' => $t('dash.kitchen', 'kitchen.view', 'kitchen_display', __('The kitchen display'), [
                __('Every new order shows as a ticket with its items, choices and notes.'),
                __('The timer turns amber after 10 minutes and red after 20.'),
                __('Tap "Start", then "Ready" (or food / drinks ready separately).'),
                __('Filter food or drinks for a bar screen; use full screen.'),
            ]),
            'callcenter-order' => $t('dash.callcenter', 'callcenter.use', 'call_center', __('Taking a call-center order'), [
                __('Type the caller\'s mobile and search: a known guest shows with their history.'),
                __('Choose delivery, the area and street: the fee and minimum show; type the address.'),
                __('Add the items and a note.'),
                __('Place the order: it goes to the board and the kitchen.'),
            ]),
            'reservations' => $t('dash.reservations', 'reservations.manage', 'reservations', __('Table reservations'), [
                __('Turn on online booking from the booking settings.'),
                __('The guest picks the day, guests and a free time from the menu link.'),
                __('Confirm the booking; on the day mark it seated or no-show.'),
                __('Add phone bookings by hand from "New reservation".'),
            ]),
            'guest-desk' => $t('dash.loyalty.desk', 'loyalty.stamp', 'loyalty_stamps|wallet', __('The guest desk'), [
                __('Search the guest by mobile (or scan their card).'),
                __('Add a stamp to their loyalty card.'),
                __('Send them their card link on WhatsApp.'),
                __('Top up their wallet: amount, how they paid, then confirm.'),
            ]),
            'rewards' => $t('dash.rewards', 'loyalty.manage|wallet.manage|marketing.manage', 'loyalty_stamps|cashback|prepaid_packages|membership|wallet|campaigns', __('Rewards'), [
                __('Everything a guest can earn on one page; each section saves on its own.'),
                __('Loyalty program: stamps by visit or item, or points.'),
                __('Welcome new guests: a gift on the first online order, or a thank-you message with a coupon.'),
                __('Cashback, membership tiers and prepaid packages.'),
            ]),
            'campaigns' => $t('dash.campaigns', 'marketing.manage', 'customers|campaigns', __('A campaign to your guests'), [
                __('Name the campaign and choose "From my phone" (free).'),
                __('Pick a guest group and write the message with {name}.'),
                __('Add a personal one-time coupon for each guest.'),
                __('Create it, then tap "Send" by each guest: WhatsApp opens with the message ready.'),
            ]),
            'coupons' => $t('dash.coupons', 'loyalty.manage|marketing.manage', 'loyalty_stamps|campaigns', __('A discount code'), [
                __('Open "New public coupon" and type the code and total uses.'),
                __('Percent or amount, a maximum discount, a minimum order and an end date.'),
                __('Once per guest asks for the mobile number.'),
                __('Follow each code\'s uses and sales; pause it any time.'),
            ]),
            'offers' => $t('dash.marketing', 'marketing.manage', 'offers', __('A timed offer'), [
                __('Name the offer and set the discount %.'),
                __('Whole menu or one category, the hours and the days.'),
                __('Save: it runs by itself at its times on the menu and the cashier.'),
                __('Guests see the old price struck through and the offer in the ticker.'),
            ]),
            'corporate-contract' => $t('dash.corporate', 'contracts.manage', 'corporate_vouchers', __('A company contract'), [
                __('New contract: the company, its contact and how it pays.'),
                __('Voucher value, how many, expiry, serial prefix and how they are used.'),
                __('Print the new vouchers (A4 or 80 mm receipts).'),
                __('Record what the company paid on its statement.'),
            ]),
            'company-visits' => $t('dash.corporate.bookings', 'contracts.manage|reservations.manage', 'corporate_vouchers', __('Company visits'), [
                __('Record what the company paid on its statement.'),
                __('Make the company\'s private link and send it: it asks for visits and sees its account.'),
                __('Confirm each visit with the meal price per guest: the meals go on a code for the visit day, the rest on a voucher for the company.'),
                __('Send the code to the rep on WhatsApp; after the visit what the meals did not use moves to the company\'s voucher.'),
            ]),
            'redeem-voucher' => $t('dash.redeem', 'vouchers.redeem', 'corporate_vouchers', __('Redeeming a company voucher'), [
                __('Type or scan the voucher code (or the employee\'s mobile) and check it.'),
                __('Pick the open order it pays (or a paper bill).'),
                __('Choose how the rest is paid, then redeem.'),
                __('Print a receipt; the redemption goes on the company statement.'),
            ]),
            'reports' => $t('dash.reports', 'reports.view', 'daily_reports', __('Reports'), [
                __('Tabs: sales, the day\'s Z report, company vouchers and reviews.'),
                __('Pick a ready period or a date range and tap "Show".'),
                __('Sales, orders, busy hours, payment methods and best sellers.'),
                __('Advanced: comparison, day × hour, new and returning guests; export orders to CSV.'),
            ]),
            'inventory-recipes' => $t('dash.inventory', 'inventory.manage', 'inventory', __('Stock & recipe costing'), [
                __('Add an ingredient with its unit, cost and low-stock alert.'),
                __('Add packaging (e.g. the takeaway cup) the same way.'),
                __('Write the item\'s recipe; mark the cup "takeaway / delivery only".'),
                __('See the ingredient cost, food cost % and profit per portion; set a target to flag items above it.'),
            ]),
            'purchases' => $t('dash.inventory.purchases', 'inventory.manage', 'inventory', __('Suppliers & purchases'), [
                __('Add a supplier with phone and notes.'),
                __('New purchase from the supplier: invoice number, items, quantities and unit costs.'),
                __('Save as draft, or save and post: the goods enter the stock and costs update.'),
                __('Open the supplier\'s statement from their name.'),
            ]),
            'inventory-count' => $t('dash.inventory.counts', 'inventory.manage', 'inventory', __('Stock count'), [
                __('Start a count: everything, ingredients, packaging or chosen items.'),
                __('Type what is on the shelf next to what is expected.'),
                __('Save as draft to see the difference in quantity and money.'),
                __('A manager posts the count: the stock becomes what was counted.'),
            ]),
            'users-roles' => $t('dash.users', 'users.manage', null, __('Adding a staff member'), [
                __('Type the name, email and mobile.'),
                __('Pick the role (its permissions show under it) and the branch.'),
                __('Set a first password; add extra permissions if needed.'),
                __('From "Edit" change the role or stop the account.'),
            ]),
            'daily-digest' => $t('dash.settings', 'settings.manage', 'daily_reports', __('The manager\'s daily report'), [
                __('Settings → Messages → Reports to the managers: the daily report is on by default.'),
                __('Every morning: sales by payment method, cancellations and refunds with reasons, discounts, shift cash, open orders, company visits waiting and low stock.'),
                __('By email and a phone notification; optionally last week\'s numbers every Monday.'),
                __('Each manager chooses it in Phone notifications.'),
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

    /**
     * Which recordings each public feature page (eghub.app/features/{slug}) plays, so a visitor sees the real screens
     * before signing up. Slugs are App\Support\SiteModules keys; a recording without its file is left out.
     */
    public const FOR_MODULE = [
        'qr-menu' => ['menu-items', 'tables-qr'],
        'menu-import' => ['menu-items'],
        'table-ordering' => ['guest-qr-order', 'order-board'],
        'online-ordering' => ['order-board', 'order-flow-settings'],
        'reservations' => ['reservations'],
        'loyalty' => ['rewards', 'guest-desk'],
        'offers' => ['offers', 'coupons'],
        'campaigns' => ['campaigns'],
        'wallet' => ['guest-desk'],
        'corporate-vouchers' => ['corporate-contract', 'company-visits', 'redeem-voucher'],
        'pos' => ['pos-sale'],
        'kitchen-display' => ['kitchen-display'],
        'call-center' => ['callcenter-order'],
        'inventory' => ['inventory-recipes', 'inventory-count', 'purchases'],
        'reports' => ['reports', 'daily-digest'],
        'multi-branch' => ['users-roles'],
    ];

    /** The recordings a public feature page shows. @return list<array<string,mixed>> */
    public static function forModule(string $slug): array
    {
        return array_values(array_filter(array_map(fn (string $key) => self::find($key), self::FOR_MODULE[$slug] ?? [])));
    }
}
