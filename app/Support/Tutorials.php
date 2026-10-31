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
            'menu-import' => $t('dash.import', 'menu.manage', null, __('Importing the menu'), [
                __('Menu → More → Import the menu, and download the template.'),
                __('One row per item: section, name, price, sizes and extras; upload the file.'),
                __('Check every row and fix a price before it goes in; add as new items or update prices.'),
                __('From a menu photo or PDF, AI reads the items, logo and colours with your key from Settings.'),
            ]),
            'menu-languages' => $t('dash.settings', 'settings.manage', 'extra_languages', __('Menu languages'), [
                __('Settings → Languages: pick extra languages, e.g. French.'),
                __('Each item gets a name and description box per language.'),
                __('Or translate the whole menu at once with AI from the menu page.'),
                __('Guests pick their language on the menu; an item without a translation shows in English.'),
            ]),
            'printed-menu' => $t('dash.menu', 'menu.manage', 'printed_menu', __('The printed menu'), [
                __('Menu → More → Printed menu: made from your items and prices.'),
                __('Pick the template (text or photos) and the number of pages.'),
                __('Pick the language: Arabic, English or both.'),
                __('Print or save a PDF; print it again after a price change.'),
            ]),
            'pager' => $t('dash.pager', 'pager.use', 'pager', __('The pager screen'), [
                __('Type the order number and tap "Show the QR".'),
                __('The guest scans it and keeps the page open: their number joins the queue.'),
                __('Order ready? Tap "Call": the guest\'s phone rings.'),
                __('Tap "Picked up" when they collect it.'),
            ]),
            'kiosk' => $t('dash.devices', 'kiosk.manage', 'kiosk', __('The self-order kiosk'), [
                __('Screens & devices → Ordering kiosk: name it and choose eat-in, takeaway or both.'),
                __('Open the kiosk link on a tablet at the counter (or scan its QR).'),
                __('The guest picks items and choices, types their name and places the order.'),
                __('They get their order number; the order lands on the board and in the kitchen.'),
            ]),
            'tv-screens' => $t('dash.devices', 'menu.manage', 'tv_screens', __('Menu TV screens'), [
                __('Screens & devices → TV screens: name the screen, pick the look and the sections.'),
                __('Each screen has a playlist: the menu, photos and videos.'),
                __('On the TV open eghub.app/tv and pair it with the code it shows.'),
                __('An urgent message shows on every screen at once for the minutes you choose.'),
            ]),
            'knowledge-base' => $t('dash.kb', 'kb.manage', 'knowledge_base', __('Questions & answers'), [
                __('Start fast with "Add suggested questions", or tap "New answer".'),
                __('The question as guests ask it, the answer and other search words.'),
                __('Show it on the menu for guests, or to the team only (cashier and call center).'),
                __('Guests find the answer on the menu instead of calling.'),
            ]),
            'delivery-zones' => $t('dash.zones', 'delivery.manage', 'delivery_zones|online_ordering', __('Delivery zones & fees'), [
                __('Price delivery by your zones or by the distance from the restaurant.'),
                __('Add a zone with its fee, minimum order and delivery minutes.'),
                __('Add the streets under a zone, with their own fee if it differs.'),
                __('Guests and the call center pick the zone; the fee and minimum follow.'),
            ]),
            'custom-domain' => $t('dash.domain', 'settings.manage', 'custom_domain', __('Your own domain'), [
                __('No domain yet? Type the name and book it from EgWeb; they connect it.'),
                __('Have one? Type it and tap "Connect my domain".'),
                __('Add the DNS records shown at your domain company.'),
                __('Check the DNS; once live every QR uses it and printed ones keep working.'),
            ]),
            'whatsapp' => $t('dash.settings', 'settings.manage', 'whatsapp_notifications', __('WhatsApp messages to guests'), [
                __('Settings → Messages: turn on the automatic messages you need.'),
                __('Order received, ready and on the way, a confirmed booking and a loyalty reward.'),
                __('New orders to the branch WhatsApp and delivery orders to the driver\'s number.'),
                __('Send from your restaurant\'s own number with the step-by-step guide; each message uses a credit.'),
            ]),
            'shifts-cash' => $t('dash.shifts', 'shifts.manage', 'shifts', __('Shifts & the cash drawer'), [
                __('Type the cash in the drawer and tap "Open shift".'),
                __('Record any cash taken out of the drawer with its reason.'),
                __('At the end count the cash, type it and tap "Close shift".'),
                __('The difference shows: short or over, and who was on the shift.'),
            ]),
            'gift-cards' => $t('dash.wallet', 'wallet.manage', 'wallet', __('Gift cards'), [
                __('Guest desk: find the buyer by mobile, then open "Sell a gift card".'),
                __('The amount, the receiver\'s mobile and a message printed on the card.'),
                __('Tap "Sell" once the money is in, and print the card with its QR.'),
                __('The receiver scans it and adds it to their balance; Wallet → Gift cards lists them all.'),
            ]),
            'reviews' => $t('dash.feedback', 'reports.view|customers.view', 'feedback', __('Guest ratings'), [
                __('Guests rate with stars at the end of the menu and after their order.'),
                __('Happy guests (4-5 stars) are invited to rate you on Google too.'),
                __('Reports → Ratings: the average and how many of each star.'),
                __('Tap the low stars to read only the complaints; set the Google link in Settings → Identity.'),
            ]),
            'menu-design' => $t('dash.settings', 'settings.manage', 'menu_themes', __('Menu design'), [
                __('Settings → Menu: pick a ready design ("Suits you" marks the ones for your place).'),
                __('How the items are laid out, a quick sheet or a full page per item, chips or circles for categories.'),
                __('Settings → Identity: your colours and font.'),
                __('Save: guests see the new design at once, with the same QR.'),
            ]),
            'driver-tracking' => $t('dash.drivers', 'delivery.assign|delivery.drivers', 'delivery_zones|online_ordering', __('Drivers & live tracking'), [
                __('Pick the driver on the delivery order\'s card on the order board.'),
                __('The driver sees only their orders and taps "Picked up"; their position is sent on the way.'),
                __('The guest follows the driver on the map from their order page.'),
                __('The Drivers page shows each driver on the map, their orders and the cash they hold.'),
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
        'qr-menu' => ['menu-items', 'menu-design', 'tables-qr'],
        'languages' => ['menu-languages'],
        'printed-menu' => ['printed-menu'],
        'tv-screens' => ['tv-screens'],
        'menu-import' => ['menu-import', 'menu-items'],
        'table-ordering' => ['guest-qr-order', 'order-board'],
        'online-ordering' => ['order-board', 'delivery-zones', 'order-flow-settings'],
        'reservations' => ['reservations'],
        'pager' => ['pager'],
        'loyalty' => ['rewards', 'guest-desk'],
        'offers' => ['offers', 'coupons'],
        'whatsapp' => ['whatsapp'],
        'campaigns' => ['campaigns'],
        'wallet' => ['gift-cards', 'guest-desk'],
        'corporate-vouchers' => ['corporate-contract', 'company-visits', 'redeem-voucher'],
        'knowledge-base' => ['knowledge-base'],
        'pos' => ['pos-sale', 'shifts-cash'],
        'kiosk' => ['kiosk'],
        'kitchen-display' => ['kitchen-display'],
        'call-center' => ['callcenter-order'],
        'delivery-drivers' => ['driver-tracking', 'delivery-zones'],
        'inventory' => ['inventory-recipes', 'inventory-count', 'purchases'],
        'reports' => ['reports', 'reviews', 'daily-digest'],
        'multi-branch' => ['users-roles'],
        'custom-domain' => ['custom-domain'],
    ];

    /** The recordings a public feature page shows. @return list<array<string,mixed>> */
    public static function forModule(string $slug): array
    {
        return array_values(array_filter(array_map(fn (string $key) => self::find($key), self::FOR_MODULE[$slug] ?? [])));
    }
}
