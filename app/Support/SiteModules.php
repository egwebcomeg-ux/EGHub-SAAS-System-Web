<?php

namespace App\Support;

use App\Models\AddonPrice;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * The modules the marketing site describes, one page each at /features/{slug}. Copy only: what a module costs is
 * never written here. Each module names the plan feature that switches it on and the add-ons that sell it, and
 * availability() reads the plans and add-ons from the database, so a price edited in the super admin shows here.
 *
 * Workflow steps double as the captions of the module's silent animation (resources/views/landing/scenes/{slug}).
 */
final class SiteModules
{
    /** group key => [label, icon] in menu order */
    public static function groups(): array
    {
        return [
            'menu' => [__('Menu'), 'qr'],
            'orders' => [__('Orders'), 'bell'],
            'customers' => [__('Customers'), 'users'],
            'operations' => [__('Operations'), 'chef'],
            'management' => [__('Management'), 'chart'],
        ];
    }

    /**
     * @return array<string, array{slug:string, group:string, icon:string, name:string, tagline:string, summary:string,
     *     for:list<string>, feature:?string, addons:list<string>, shot:string, frame:string, url:string, phone:?string,
     *     benefits:list<array{0:string,1:string,2:string}>, steps:list<array{0:string,1:string}>,
     *     who:array{cafe:string,restaurant:string}, faq:list<array{0:string,1:string}>, related:list<string>,
     *     gallery?:list<array{0:string,1:string,2:string,3:string}>}> gallery = more real screens [shot, frame, url, caption]
     */
    public static function all(): array
    {
        static $cache = [];
        $locale = app()->getLocale();

        return $cache[$locale] ??= collect(self::definitions())->keyBy('slug')->all();
    }

    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** @return array<string, list<array>> modules grouped in menu order */
    public static function grouped(): array
    {
        $out = array_fill_keys(array_keys(self::groups()), []);
        foreach (self::all() as $m) {
            $out[$m['group']][] = $m;
        }

        return $out;
    }

    /** @return array{0:?array,1:?array} previous and next module in menu order (wrapping around) */
    public static function neighbours(string $slug): array
    {
        $list = array_values(array_merge(...array_values(self::grouped())));
        $i = array_search($slug, array_column($list, 'slug'), true);
        if ($i === false) {
            return [null, null];
        }
        $n = count($list);

        return [$list[($i - 1 + $n) % $n], $list[($i + 1) % $n]];
    }

    /**
     * Where a module is sold, from the database: the public plans that include its feature (all of them when it has
     * none) and the active add-ons listed for it.
     *
     * @param  Collection<int, Plan>  $plans  public plans in order
     * @param  array<int, array{features: array<string,bool>}>  $matrix  entitlements by plan id
     * @param  Collection<int, AddonPrice>  $addons  active add-ons
     *                                               Monthly add-ons and one-off credit packs (WhatsApp messages) come back apart.
     * @return array{plans: Collection<int, Plan>, from: ?Plan, everyPlan: bool, addons: Collection<int, AddonPrice>, credits: Collection<int, AddonPrice>}
     */
    public static function availability(array $module, Collection $plans, array $matrix, Collection $addons): array
    {
        $key = $module['feature'];
        $in = $key === null ? $plans->values() : $plans->filter(fn (Plan $p) => ! empty($matrix[$p->id]['features'][$key]))->values();
        $listed = $addons->filter(fn (AddonPrice $a) => in_array($a->key, $module['addons'], true))
            ->sortBy(fn (AddonPrice $a) => array_search($a->key, $module['addons'], true))->values();

        return [
            'plans' => $in,
            'from' => $in->first(),
            'everyPlan' => $in->count() === $plans->count() && $plans->isNotEmpty(),
            'addons' => $listed->reject(fn (AddonPrice $a) => $a->isCreditPack())->values(),
            'credits' => $listed->filter(fn (AddonPrice $a) => $a->isCreditPack())->values(),
        ];
    }

    /**
     * One short line on where a module is sold: "Free in every plan", "In Pro and Business", "Add-on from 149 EGP".
     *
     * @param  array{plans: Collection, addons: Collection, matrix: array}  $pricing  from pricing()
     */
    public static function where(array $module, array $pricing): string
    {
        $av = self::availability($module, $pricing['plans'], $pricing['matrix'], $pricing['addons']);
        if ($av['everyPlan']) {
            return __('Free, in every plan');
        }
        if ($av['plans']->isNotEmpty()) {
            return __('In :plans', ['plans' => $av['plans']->map->displayName()->join(' · ')]);
        }
        if ($av['addons']->isNotEmpty()) {
            return __('Add-on from :price EGP / month', ['price' => money($av['addons']->min('monthly_price'))]);
        }

        return '';
    }

    /** Public plans, active add-ons and the entitlement matrix, as the landing pages need them. */
    public static function pricing(): array
    {
        $plans = Plan::query()->where('is_public', true)->with('features')->orderBy('sort_order')->get();

        return [
            'plans' => $plans,
            'addons' => AddonPrice::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'matrix' => $plans->mapWithKeys(fn (Plan $p) => [$p->id => $p->entitlements()])->all(),
        ];
    }

    private static function definitions(): array
    {
        return [
            // ---------------- menu ----------------
            [
                'slug' => 'qr-menu', 'group' => 'menu', 'icon' => 'qr', 'for' => ['cafe', 'restaurant'],
                'name' => __('QR menu'),
                'tagline' => __('Your menu on every phone in the room, from one QR code'),
                'summary' => __('A fast menu in Arabic and English that opens from one QR code.'),
                'feature' => null, 'addons' => [],
                'shot' => 'menu-editor', 'frame' => 'browser', 'url' => 'app.egmenu.app/menu', 'phone' => 'menu-cafe',
                'benefits' => [
                    ['zap', __('Opens in a second'), __('No app to download. The guest scans, and the menu opens in the browser of any phone.')],
                    ['globe', __('Arabic and English'), __('Each item has an Arabic and an English name. The guest switches language with one tap.')],
                    ['layers', __('Eight ready designs'), __('Cozy cafe, bistro, dark, oriental grill and more, with your logo and colours, on the paid plans.')],
                    ['pencil', __('Change it any time'), __('Prices, photos, sold-out items: edit from your phone and the menu updates at once.')],
                    ['globe', __('On Google and in the directory'), __('Your menu and a page about your place live at egmenu.app/your-name, ready for Google, and you can be listed in the EgMenu restaurant directory.')],
                ],
                'steps' => [
                    [__('Scan'), __('The guest scans the code on the table.')],
                    [__('Browse'), __('Rosto’s menu opens with its categories and prices.')],
                    [__('Search'), __('They jump to desserts or search for a drink.')],
                    [__('Change the look'), __('You switch the design from the dashboard and every phone follows.')],
                ],
                'who' => [
                    'cafe' => __('Drinks with sizes and extras, a happy-hour banner, and the cozy cafe design.'),
                    'restaurant' => __('Big photos, sections for grills and sandwiches, and descriptions that sell.'),
                ],
                'faq' => [
                    [__('Is the menu really free?'), __('Yes. The free plan keeps your menu online with a QR code for as long as you want, with a small egmenu badge.')],
                    [__('Do guests need an app?'), __('No. They scan the QR code and the menu opens in the browser.')],
                    [__('Can I use my own photos and colours?'), __('Item photos, yes, on every plan. Your logo, your colours and the extra designs come with the paid plans.')],
                ],
                'related' => ['printed-menu', 'table-ordering', 'menu-import'],
            ],
            [
                'slug' => 'languages', 'group' => 'menu', 'icon' => 'translate', 'for' => ['cafe', 'restaurant'],
                'name' => __('Menu languages'),
                'tagline' => __('Your menu in French, Russian, Italian and more, translated in one click'),
                'summary' => __('Seven more languages, filled in by AI with your own key.'),
                'feature' => 'extra_languages', 'addons' => ['languages_pack'],
                'shot' => 'languages', 'frame' => 'browser', 'url' => 'app.egmenu.app/settings', 'phone' => 'm-languages',
                'benefits' => [
                    ['globe', __('Seven more languages'), __('French, Italian, German, Russian, Spanish, Turkish and Chinese, next to Arabic and English.')],
                    ['sparkles', __('One-click translation'), __('AI fills in every missing name and description with your own AI key; you pay the provider only for what you use.')],
                    ['pencil', __('Edit any word'), __('Fix any translation by hand, item by item, like any other text in the menu.')],
                    ['zap', __('The guest picks'), __('A tourist switches the menu to their language from the menu header.')],
                ],
                'steps' => [
                    [__('Pick languages'), __('Rosto turns on French and Russian.')],
                    [__('Translate'), __('One click fills in the items with AI.')],
                    [__('Review'), __('Fix a name by hand where you want.')],
                    [__('The guest switches'), __('A tourist picks Русский from the menu.')],
                ],
                'who' => [
                    'cafe' => __('A cafe in Zamalek or Dahab where many guests read neither Arabic nor English.'),
                    'restaurant' => __('Restaurants on the tourist route in Hurghada, Sharm, Luxor and downtown Cairo.'),
                ],
                'faq' => [
                    [__('Who pays for the translation?'), __('You use your own AI key (Claude, Gemini or OpenAI) from Settings, as with the menu import; translating a whole menu costs very little.')],
                    [__('Are Arabic and English part of it?'), __('Arabic and English are in every plan. This module adds the other seven languages.')],
                    [__('What if I add items later?'), __('Run the translation again: it fills in only what is missing and keeps what you edited by hand.')],
                ],
                'related' => ['qr-menu', 'menu-import', 'printed-menu'],
            ],
            [
                'slug' => 'printed-menu', 'group' => 'menu', 'icon' => 'printer', 'for' => ['cafe', 'restaurant'],
                'name' => __('Printed menu'),
                'tagline' => __('A print-ready paper menu, without a designer'),
                'summary' => __('Your online menu as an A4 PDF, in your colours.'),
                'feature' => 'printed_menu', 'addons' => [],
                'shot' => 'print', 'frame' => 'browser', 'url' => 'app.egmenu.app/menu/print', 'phone' => null,
                'benefits' => [
                    ['file', __('From the same menu'), __('The paper menu is built from the items you already have. Nothing to type twice.')],
                    ['printer', __('Ready for the print shop'), __('Pick a template, the number of pages and the language, then print or save it as a PDF.')],
                    ['wallet', __('No designer fees'), __('Prices went up? Change them in the dashboard and print a new copy the same day.')],
                ],
                'steps' => [
                    [__('Change a price'), __('Latte goes from 65 to 70 EGP in the dashboard.')],
                    [__('Pick a template'), __('Choose the template, pages and language.')],
                    [__('Export'), __('The page is laid out and ready as a PDF.')],
                    [__('Print'), __('Send it to the print shop with the new price.')],
                ],
                'who' => [
                    'cafe' => __('A one-page A4 menu for the counter, in Arabic and English.'),
                    'restaurant' => __('A multi-page menu with descriptions for every table.'),
                ],
                'faq' => [
                    [__('Which paper sizes?'), __('The layout is made for A4 pages; you choose how many pages the menu fits on.')],
                    [__('Does it include a QR code?'), __('Yes, the printed menu can carry the QR code that opens your online menu.')],
                    [__('Can I print both languages?'), __('Yes: Arabic only, English only, or both side by side.')],
                ],
                'related' => ['qr-menu', 'tv-screens', 'offers'],
            ],
            [
                'slug' => 'tv-screens', 'group' => 'menu', 'icon' => 'tv', 'for' => ['cafe', 'restaurant'],
                'name' => __('TV menu screens'),
                'tagline' => __('Your menu on the shop TVs, always with today’s prices'),
                'summary' => __('Any smart TV becomes a menu board that updates itself.'),
                'feature' => 'tv_screens', 'addons' => [],
                'shot' => 'tv', 'frame' => 'tv', 'url' => '', 'phone' => null,
                'benefits' => [
                    ['tv', __('Any smart TV'), __('Open the screen link in the TV browser once. No box, no USB stick.')],
                    ['zap', __('Updates by itself'), __('Change a price or mark an item sold out and the TV follows within seconds.')],
                    ['tag', __('Offers on screen'), __('Happy-hour prices show with the old price crossed out while the offer runs.')],
                ],
                'steps' => [
                    [__('Pick the sections'), __('Choose what this screen shows: hot drinks above the cashier, desserts at the door.')],
                    [__('Open on the TV'), __('Open the screen link in the TV browser.')],
                    [__('Change a price'), __('Edit a price in the dashboard.')],
                    [__('The TV follows'), __('The screen refreshes itself with the new price.')],
                ],
                'who' => [
                    'cafe' => __('A drinks board above the counter so the line orders faster.'),
                    'restaurant' => __('Combos and offers on the screen at the entrance.'),
                ],
                'faq' => [
                    [__('How many screens can I have?'), __('As many as you need; each one has its own link and its own sections.')],
                    [__('Does the TV need internet?'), __('Yes, a normal Wi-Fi connection so it can pick up your changes.')],
                    [__('Can a screen show offers only?'), __('Yes. Pick the sections and the screen shows only those, with offer prices when they run.')],
                ],
                'related' => ['offers', 'qr-menu', 'printed-menu'],
            ],
            [
                'slug' => 'menu-import', 'group' => 'menu', 'icon' => 'upload', 'for' => ['cafe', 'restaurant'],
                'name' => __('Menu import'),
                'tagline' => __('Your whole menu in, from an Excel sheet or a photo'),
                'summary' => __('Upload Excel, or a photo that AI turns into items.'),
                'feature' => 'excel_import', 'addons' => [],
                'shot' => 'import', 'frame' => 'browser', 'url' => 'app.egmenu.app/menu/import', 'phone' => null,
                'benefits' => [
                    ['file', __('Excel template'), __('Download the template, fill in sections, items, sizes and prices, and upload it.')],
                    ['sparkles', __('From a photo with AI'), __('Upload a photo or PDF of your printed menu and AI reads the items for you.')],
                    ['check', __('You review first'), __('Nothing goes live until you check the items and press publish.')],
                ],
                'steps' => [
                    [__('Upload'), __('Drop in the Excel sheet or a photo of the paper menu.')],
                    [__('Read'), __('The items, sizes and prices are read line by line.')],
                    [__('Review'), __('Fix anything before it goes live.')],
                    [__('Publish'), __('The menu is online, ready for the QR codes.')],
                ],
                'who' => [
                    'cafe' => __('A drinks list with small and large sizes, in one sheet.'),
                    'restaurant' => __('A long menu with many sections, without typing it item by item.'),
                ],
                'faq' => [
                    [__('Who pays for the AI?'), __('You use your own AI key (Claude, Gemini or OpenAI) from Settings, so you pay the provider directly and only for what you use; the cheap models are enough.')],
                    [__('Is Excel import free?'), __('Yes, the Excel import is included in every plan, including the free one.')],
                    [__('Will it replace my current items?'), __('No. Imported items are added for review; you decide what to keep.')],
                ],
                'related' => ['qr-menu', 'printed-menu', 'multi-branch'],
            ],

            // ---------------- orders ----------------
            [
                'slug' => 'table-ordering', 'group' => 'orders', 'icon' => 'bell', 'for' => ['cafe', 'restaurant'],
                'name' => __('Table ordering'),
                'tagline' => __('Guests order from the table. You hear it ring.'),
                'summary' => __('Orders from the table QR straight to a live board.'),
                'feature' => 'qr_ordering', 'addons' => [],
                'shot' => 'orders', 'frame' => 'browser', 'url' => 'app.egmenu.app/orders', 'phone' => 'm-cartbar',
                'benefits' => [
                    ['bell', __('A ring for every order'), __('New orders appear on the board with a sound, with the table number on top.')],
                    ['pencil', __('Exactly as the guest wrote it'), __('Sizes, extras and notes like “no sugar” reach the bar or kitchen as typed.')],
                    ['clock', __('New, preparing, ready'), __('One tap moves an order along, so everyone sees what is waiting.')],
                    ['phone', __('On what you already have'), __('The board works on the phone, tablet or computer at the counter.')],
                ],
                'steps' => [
                    [__('Scan the table'), __('A guest at table 4 scans the code.')],
                    [__('Pick items'), __('A latte with a size, and a cheesecake.')],
                    [__('Send'), __('They review the order and send it.')],
                    [__('It rings'), __('The order lands on your board with a bell.')],
                    [__('Prepare and serve'), __('One tap to preparing, one tap to ready.')],
                ],
                'who' => [
                    'cafe' => __('No queue at the counter at rush hour: guests order while they sit.'),
                    'restaurant' => __('Fewer wrong orders: options and notes go to the kitchen as written.'),
                ],
                'faq' => [
                    [__('Can guests also call the waiter?'), __('Yes, there is a call-the-waiter button, and you see which table pressed it.')],
                    [__('What if I do not want ordering every day?'), __('Switch ordering off from Settings and the menu goes back to view only.')],
                    [__('Does each table have its own code?'), __('Yes. Print one code per table from the dashboard, and orders arrive with the table number.')],
                ],
                'related' => ['kitchen-display', 'pos', 'online-ordering'],
            ],
            [
                'slug' => 'online-ordering', 'group' => 'orders', 'icon' => 'bag', 'for' => ['cafe', 'restaurant'],
                'name' => __('Online ordering'),
                'tagline' => __('Takeaway and delivery from your own link, with no commission'),
                'summary' => __('Pickup, delivery and scheduled orders. Every pound is yours.'),
                'feature' => 'online_ordering', 'addons' => ['online_pack'],
                'shot' => 'zones', 'frame' => 'browser', 'url' => 'app.egmenu.app/delivery-zones', 'phone' => 'm-cart',
                'benefits' => [
                    ['wallet', __('0% commission'), __('You pay the monthly plan, nothing per order. The customer pays you directly.')],
                    ['scooter', __('Delivery zones'), __('Draw your areas on the map with a fee and a minimum order for each one.')],
                    ['clock', __('Scheduled orders'), __('The customer picks a time, so the order for 8:30 is ready at 8:30.')],
                    ['chat', __('WhatsApp follow-up'), __('Each order has a tracking page, and you can confirm it with the customer on WhatsApp.')],
                ],
                'steps' => [
                    [__('Open your link'), __('A customer taps egmenu.app/rosto in your Instagram bio.')],
                    [__('Choose delivery'), __('Delivery to Nasr City, or pickup at a set time.')],
                    [__('See the fee'), __('The zone adds its delivery fee to the total.')],
                    [__('Order arrives'), __('It lands on your board, with no commission taken.')],
                ],
                'who' => [
                    'cafe' => __('Pickup orders: the customer orders on the way and collects without waiting.'),
                    'restaurant' => __('Delivery with zones and fees, instead of paying an app a share of every order.'),
                ],
                'faq' => [
                    [__('How does the customer pay?'), __('Cash on delivery or at pickup; the order total, fee and notes are clear on your board.')],
                    [__('Is there really no commission?'), __('None. EgMenu charges the monthly subscription only.')],
                    [__('Do I need my own riders?'), __('Yes, you deliver with your own team; EgMenu organises the orders, zones and fees.')],
                ],
                'related' => ['call-center', 'table-ordering', 'custom-domain'],
            ],
            [
                'slug' => 'reservations', 'group' => 'orders', 'icon' => 'calendar', 'for' => ['cafe', 'restaurant'],
                'name' => __('Table reservations'),
                'tagline' => __('Guests book a table from your menu link'),
                'summary' => __('Bookings by time and party size, within the seats you have.'),
                'feature' => 'reservations', 'addons' => ['reservations_pack'],
                'shot' => 'reservations', 'frame' => 'browser', 'url' => 'app.egmenu.app/reservations', 'phone' => 'm-reserve',
                'benefits' => [
                    ['calendar', __('Book from the menu link'), __('A “Book a table” page next to your menu: the day, the time, the number of guests and a mobile number.')],
                    ['users', __('Never overbooked'), __('Set the seats per time slot, the table time and the largest group; guests only see the times that are free.')],
                    ['bell', __('On the order board'), __('Today’s bookings show in a strip on the order board, so the floor knows who is coming.')],
                    ['chat', __('Confirmed your way'), __('Confirm each booking with one tap, or automatically; with WhatsApp updates the guest gets it on WhatsApp.')],
                ],
                'steps' => [
                    [__('Pick a time'), __('Mona picks Friday and 4 guests, and sees the free times.')],
                    [__('Book'), __('She books 8:30 pm with a note: a birthday.')],
                    [__('Confirm'), __('The booking waits on the reservations page; one tap confirms it.')],
                    [__('Seat'), __('At 8:30 the board strip shows the booking; mark them seated.')],
                ],
                'who' => [
                    'cafe' => __('A corner for a study group or a birthday at the weekend, booked ahead.'),
                    'restaurant' => __('Friday dinners and big families booked by time, within the seats you really have.'),
                ],
                'faq' => [
                    [__('Can I add phone bookings by hand?'), __('Yes, add a booking from the reservations page with the name, mobile, time and number of guests.')],
                    [__('Can the guest cancel?'), __('Yes. Every booking has its own page where the guest follows it and can cancel it.')],
                    [__('Can I stop online booking on busy days?'), __('Turn online booking off at any time, or set booking hours per day; a day with no hours is closed.')],
                ],
                'related' => ['table-ordering', 'whatsapp', 'qr-menu'],
            ],

            // ---------------- customers ----------------
            [
                'slug' => 'loyalty', 'group' => 'customers', 'icon' => 'stamp', 'for' => ['cafe', 'restaurant'],
                'name' => __('Loyalty card'),
                'tagline' => __('A stamp card on the guest’s phone that brings them back'),
                'summary' => __('“Every 8 coffees, one free”, with no paper and no app.'),
                'feature' => 'loyalty_stamps', 'addons' => [],
                'shot' => 'loyalty-desk', 'frame' => 'browser', 'url' => 'app.egmenu.app/loyalty', 'phone' => 'm-card',
                'benefits' => [
                    ['stamp', __('Stamps or points'), __('A stamp per visit or per item, or points on every pound spent.')],
                    ['gift', __('Rewards on the way'), __('A discount at the 4th stamp and a free drink at the 8th keep guests coming.')],
                    ['users', __('Know your regulars'), __('See who comes back, how often, and what they like.')],
                ],
                'steps' => [
                    [__('Join with a number'), __('The guest gives a mobile number and gets the card on their phone.')],
                    [__('Stamp'), __('The cashier finds them and adds a stamp.')],
                    [__('Halfway reward'), __('At the 4th stamp, 20% off unlocks.')],
                    [__('Free coffee'), __('The 8th stamp is a free coffee, redeemed at the counter.')],
                ],
                'who' => [
                    'cafe' => __('The classic coffee card: every 8 coffees, the next is on you.'),
                    'restaurant' => __('Points on every order, with a reward at a set number of points.'),
                ],
                'faq' => [
                    [__('Does the guest need an app?'), __('No. The card opens from a link and can be saved to the phone’s home screen.')],
                    [__('Can I change the rules later?'), __('Yes, the number of stamps, the rewards and the card colours are all yours to edit.')],
                    [__('How do I stop staff abuse?'), __('Stamps are recorded with the staff member and time, and the cashier only sees what you allow.')],
                ],
                'related' => ['offers', 'pos', 'reports'],
            ],
            [
                'slug' => 'offers', 'group' => 'customers', 'icon' => 'tag', 'for' => ['cafe', 'restaurant'],
                'name' => __('Offers and popups'),
                'tagline' => __('Happy hour, popups and combos that run on their own'),
                'summary' => __('Timed discounts that switch on and off by themselves.'),
                'feature' => 'offers', 'addons' => [],
                'shot' => 'marketing', 'frame' => 'browser', 'url' => 'app.egmenu.app/marketing', 'phone' => 'menu-cafe',
                'benefits' => [
                    ['clock', __('Happy hour'), __('20% off cold drinks from 3 to 6, on the days you choose. It starts and stops by itself.')],
                    ['sparkles', __('A popup on open'), __('Announce a new dessert the moment the menu opens, and see how many saw it.')],
                    ['tag', __('Combos and pairings'), __('“Goes well with” suggestions that add a croissant to the latte.')],
                ],
                'steps' => [
                    [__('Set the offer'), __('20% off cold drinks, from 3 to 6 pm.')],
                    [__('It starts'), __('At 3:00 the prices drop on every phone.')],
                    [__('Popup'), __('Guests see the new cheesecake when the menu opens.')],
                    [__('Pairing'), __('A latte suggests a croissant next to it.')],
                ],
                'who' => [
                    'cafe' => __('Fill the quiet afternoon hours with a happy hour on cold drinks.'),
                    'restaurant' => __('Lunch combos and a popup for the new dish of the week.'),
                ],
                'faq' => [
                    [__('Do offers show on the TV and the cashier too?'), __('Yes, the offer price appears on the menu, the TV screens and the POS while it runs.')],
                    [__('Can an offer run on certain days only?'), __('Yes: pick the days, the hours and an optional start and end date.')],
                    [__('Can I stop an offer early?'), __('Yes, pause it with one tap and the old prices come back.')],
                ],
                'related' => ['loyalty', 'tv-screens', 'qr-menu'],
            ],
            [
                'slug' => 'whatsapp', 'group' => 'customers', 'icon' => 'chat', 'for' => ['cafe', 'restaurant'],
                'name' => __('WhatsApp updates'),
                'tagline' => __('Your guests get their order updates on WhatsApp'),
                'summary' => __('Order received, ready, on the way, booking confirmed, reward earned.'),
                'feature' => 'whatsapp_notifications', 'addons' => ['whatsapp_pack', 'wa_500', 'wa_2000'],
                'shot' => 'whatsapp', 'frame' => 'browser', 'url' => 'app.egmenu.app/settings', 'phone' => null,
                'benefits' => [
                    ['bell', __('Sent as the order moves'), __('Order received, ready for pickup, out with the rider: each message goes by itself when the order changes.')],
                    ['calendar', __('Bookings and rewards'), __('A message when a booking is confirmed, and when a guest earns a reward on the loyalty card.')],
                    ['pencil', __('You choose the messages'), __('Switch each message on or off, and set the name your guests see in it.')],
                    ['wallet', __('Pay per message'), __('Each message uses one credit. Credits come in packs you pay once, and they never expire.')],
                ],
                'steps' => [
                    [__('Order arrives'), __('Karim orders delivery from Rosto’s menu link.')],
                    [__('Order received'), __('WhatsApp: Rosto received your order and is on it.')],
                    [__('On the way'), __('When it leaves with the rider, a second message goes out.')],
                    [__('Credits'), __('Each message takes one credit from your balance.')],
                ],
                'who' => [
                    'cafe' => __('Pickup orders: “your order is ready”, so the guest comes at the right minute.'),
                    'restaurant' => __('Delivery customers who know their food is on the way, without calling you.'),
                ],
                'faq' => [
                    [__('Do I need my own WhatsApp Business account?'), __('No. Messages go out from the EgMenu WhatsApp channel, with your place’s name in every message.')],
                    [__('What does a message cost?'), __('One message is one credit. Credit packs are paid once, and their prices are on the pricing page.')],
                    [__('Can a guest stop the messages?'), __('Yes. Guests who opt out are skipped, and no credit is used for them.')],
                ],
                'related' => ['campaigns', 'online-ordering', 'reservations'],
            ],
            [
                'slug' => 'campaigns', 'group' => 'customers', 'icon' => 'megaphone', 'for' => ['cafe', 'restaurant'],
                'name' => __('WhatsApp campaigns'),
                'tagline' => __('Bring guests back with a WhatsApp message and a coupon'),
                'summary' => __('Pick the guests, write the message, add a coupon, send or schedule.'),
                'feature' => 'campaigns', 'addons' => ['marketing_pack', 'wa_500', 'wa_2000'],
                'shot' => 'campaigns', 'frame' => 'browser', 'url' => 'app.egmenu.app/campaigns', 'phone' => null,
                'benefits' => [
                    ['users', __('Guests by segment'), __('Guests who have not come back for 30 days, regulars with many visits, or birthdays this month.')],
                    ['tag', __('A coupon in the message'), __('Attach a discount code like BACK20 with an end date; the guest uses it at the counter or online.')],
                    ['clock', __('Now or later'), __('Schedule the campaign for Thursday evening and it starts by itself.')],
                    ['chart', __('Counted for you'), __('Sent, failed and skipped messages are counted for every campaign.')],
                ],
                'steps' => [
                    [__('Pick the guests'), __('Guests who have not visited for 30 days.')],
                    [__('Write'), __('“We miss you at Rosto: 20% off with BACK20.”')],
                    [__('Schedule'), __('Thursday at 6 pm, with the coupon valid for 14 days.')],
                    [__('Sent'), __('The messages go out and the coupon is used at the counter.')],
                ],
                'who' => [
                    'cafe' => __('A “we miss you” message with a discount for regulars who stopped coming.'),
                    'restaurant' => __('A weekend offer to the families who ordered delivery last month.'),
                ],
                'faq' => [
                    [__('Who receives a campaign?'), __('Only guests in your customer list who match the segment and have not opted out of WhatsApp messages.')],
                    [__('What does sending cost?'), __('Each message uses one WhatsApp credit, and you see how many guests a campaign reaches before you send it.')],
                    [__('Can I stop a campaign?'), __('Yes. Cancel it before it starts, or stop it while it runs and resume it later.')],
                ],
                'related' => ['whatsapp', 'loyalty', 'offers'],
            ],
            [
                'slug' => 'wallet', 'group' => 'customers', 'icon' => 'wallet', 'for' => ['cafe', 'restaurant'],
                'name' => __('Wallet and gift cards'),
                'tagline' => __('Prepaid balance and gift cards: the money comes in first'),
                'summary' => __('Guests top up and pay from their balance; gift cards with a QR.'),
                'feature' => 'wallet', 'addons' => ['wallet_pack'],
                'shot' => 'wallet', 'frame' => 'browser', 'url' => 'app.egmenu.app/wallet', 'phone' => 'm-gift',
                'benefits' => [
                    ['wallet', __('Top up at the counter'), __('The cashier adds a balance to the guest’s mobile number and prints a receipt.')],
                    ['cash', __('Pay from the balance'), __('At the POS or with an online order, the guest pays from the balance; a refund goes back to it.')],
                    ['gift', __('Gift cards'), __('Sell printed gift cards with a code and a QR; whoever receives one adds it to their own balance.')],
                    ['shield', __('Every pound on record'), __('Each top-up, payment, refund and adjustment is recorded with the staff member and the time.')],
                ],
                'steps' => [
                    [__('Top up'), __('Mona tops up 500 EGP at the counter.')],
                    [__('Pay'), __('Her latte and cheesecake are paid from the balance.')],
                    [__('Gift card'), __('Her friend scans a 300 EGP gift card.')],
                    [__('Added'), __('The card’s amount is added to the friend’s balance.')],
                ],
                'who' => [
                    'cafe' => __('Regulars who top up once a month and order every morning without cash.'),
                    'restaurant' => __('Gift cards for birthdays and companies, paid in advance.'),
                ],
                'faq' => [
                    [__('Can the guest see the balance?'), __('Yes, on their card page, from the same link as the loyalty card.')],
                    [__('Can a gift card be used twice?'), __('No. A gift card is added to one mobile number, once, and you can void a card that was not used.')],
                    [__('Who can top up?'), __('Staff with the wallet permission, from the wallet page or from the POS during their shift.')],
                ],
                'related' => ['loyalty', 'pos', 'campaigns'],
            ],

            // ---------------- operations (add-ons) ----------------
            [
                'slug' => 'pos', 'group' => 'operations', 'icon' => 'cash', 'for' => ['cafe', 'restaurant'],
                'name' => __('POS cashier'),
                'tagline' => __('A cashier screen for counter orders, shifts and the cash drawer'),
                'summary' => __('Counter orders, payments, shifts and a customer display.'),
                'feature' => 'pos', 'addons' => ['pos_pack', 'ops_bundle'],
                'shot' => 'pos', 'frame' => 'browser', 'url' => 'app.egmenu.app/pos', 'phone' => null,
                'benefits' => [
                    ['zap', __('Fast at the counter'), __('Tap items, adjust quantities, add a discount, take the money.')],
                    ['cash', __('Shifts and drawer'), __('Open a shift with the opening cash and close it with a count; differences are clear.')],
                    ['tv', __('Customer display'), __('A second screen shows the guest their order and the order numbers that are ready.')],
                    ['layers', __('One board for all orders'), __('Counter, table and online orders meet on the same board and the same reports.')],
                ],
                'steps' => [
                    [__('Tap items'), __('Cheesecake, a croissant and two cookies.')],
                    [__('Pay'), __('Cash: the change due is shown.')],
                    [__('Number on screen'), __('Order 61 shows on the customer display.')],
                    [__('Close the shift'), __('The drawer total matches the sales.')],
                ],
                'who' => [
                    'cafe' => __('Takeaway at the counter with an order number board for pickup.'),
                    'restaurant' => __('A cashier for walk-ins and dine-in bills next to the table orders.'),
                ],
                'faq' => [
                    [__('Do I need special hardware?'), __('No. Any tablet or computer with a browser; a receipt printer is optional.')],
                    [__('Is the POS in every plan?'), __('It is an add-on you switch on when you need it, on top of a paid plan.')],
                    [__('Does it work with several cashiers?'), __('Yes, each cashier has an account, and each shift is tied to the person who opened it.')],
                ],
                'related' => ['kitchen-display', 'reports', 'loyalty'],
            ],
            [
                'slug' => 'kiosk', 'group' => 'operations', 'icon' => 'tablet', 'for' => ['cafe', 'restaurant'],
                'name' => __('Self-order kiosk'),
                'tagline' => __('A tablet at the counter where guests order by themselves'),
                'summary' => __('Guests tap, order and get a number, then pay at the cashier.'),
                'feature' => 'kiosk', 'addons' => ['kiosk_pack'],
                'shot' => 'kiosk-screen', 'frame' => 'tablet', 'url' => '', 'phone' => null,
                'gallery' => [['kiosks', 'browser', 'app.egmenu.app/kiosks', __('The kiosks page: one secret link per tablet, eat-in or takeaway, stop it any time.')]],
                'benefits' => [
                    ['zap', __('A shorter line'), __('At rush hour guests order on the tablet while the cashier takes the money.')],
                    ['layers', __('Your menu, made big'), __('The kiosk shows your menu with sizes, extras and sold-out items, laid out for a tablet.')],
                    ['bell', __('Straight to the board'), __('The order lands on the order board and the kitchen screen with its number, like any other order.')],
                    ['shield', __('One link per tablet'), __('Each tablet opens its own secret link; stop it or change the link from the dashboard.')],
                ],
                'steps' => [
                    [__('Start'), __('The guest taps “Start your order” on the tablet.')],
                    [__('Pick items'), __('A latte with a size, and a cheesecake.')],
                    [__('Order number'), __('They write their name and get order number 74.')],
                    [__('Pay and collect'), __('They pay at the cashier, and the order is already at the bar.')],
                ],
                'who' => [
                    'cafe' => __('Takeaway coffee at rush hour: the line orders on the tablet while the barista keeps going.'),
                    'restaurant' => __('A fast-casual counter where guests choose eat-in or takeaway and wait for their number.'),
                ],
                'faq' => [
                    [__('Which tablet do I need?'), __('Any tablet with a browser; a 10-inch screen in landscape works best. Open the kiosk link once and leave it on.')],
                    [__('Does the guest pay on the tablet?'), __('No. The kiosk takes the order, and the guest pays at the cashier as usual.')],
                    [__('What if a guest walks away mid-order?'), __('The kiosk asks “Are you still here?” and starts over by itself after a short wait.')],
                    [__('How is it priced?'), __('It is an add-on per branch, with a discount on each extra branch, on top of a paid plan.')],
                ],
                'related' => ['pos', 'kitchen-display', 'table-ordering'],
            ],
            [
                'slug' => 'kitchen-display', 'group' => 'operations', 'icon' => 'chef', 'for' => ['cafe', 'restaurant'],
                'name' => __('Kitchen and bar display'),
                'tagline' => __('Orders on a screen in the kitchen or at the bar, not on paper'),
                'summary' => __('A screen per station, with timers and colours.'),
                'feature' => 'kitchen_display', 'addons' => ['kitchen_pack', 'ops_bundle'],
                'shot' => 'kitchen', 'frame' => 'browser', 'url' => 'app.egmenu.app/kitchen', 'phone' => null,
                'benefits' => [
                    ['clock', __('Timers and colours'), __('Every ticket counts the minutes and turns red when it waits too long.')],
                    ['layers', __('A screen per station'), __('The grill, the salads and the bar each see only their own items.')],
                    ['bell', __('Ready means ready'), __('One tap marks it ready and the waiter or cashier sees it at once.')],
                ],
                'steps' => [
                    [__('Ticket arrives'), __('Table 1’s order appears with a sound.')],
                    [__('Start'), __('The barista taps start.')],
                    [__('Read the notes'), __('“No sugar” stands out in yellow.')],
                    [__('Ready'), __('Ready: the board and the cashier see it.')],
                ],
                'who' => [
                    'cafe' => __('A bar screen: drink orders in the order they came, even at rush hour.'),
                    'restaurant' => __('Stations for grill, salads and desserts, each with its own screen.'),
                ],
                'faq' => [
                    [__('What screen do I need?'), __('Any tablet or TV with a browser, in full-screen mode.')],
                    [__('Can the kitchen see all orders?'), __('Yes, or only its station’s items once you set up stations.')],
                    [__('Is it an add-on?'), __('Yes, you add it when your kitchen needs it, on top of a paid plan.')],
                ],
                'related' => ['table-ordering', 'pos', 'call-center'],
            ],
            [
                'slug' => 'call-center', 'group' => 'operations', 'icon' => 'phone', 'for' => ['restaurant'],
                'name' => __('Call center and delivery'),
                'tagline' => __('Phone orders in seconds, with the customer’s address on file'),
                'summary' => __('Find the caller by number, pick a zone, send it.'),
                'feature' => 'call_center', 'addons' => ['callcenter_pack', 'ops_bundle'],
                'shot' => 'call-center', 'frame' => 'browser', 'url' => 'app.egmenu.app/call-center', 'phone' => null,
                'benefits' => [
                    ['users', __('Caller on file'), __('Type the number and the name, saved addresses and last orders appear.')],
                    ['scooter', __('Zones and fees'), __('Pick the area and the delivery fee and minimum order apply by themselves.')],
                    ['bell', __('Straight to the kitchen'), __('The order goes to the board and the kitchen screen like any other order.')],
                ],
                'steps' => [
                    [__('The phone rings'), __('Karim calls; his number finds his address.')],
                    [__('Add items'), __('Two cheesecakes for delivery.')],
                    [__('Zone and fee'), __('Nasr City adds its delivery fee.')],
                    [__('On the way'), __('The order goes to the kitchen, then out with the rider.')],
                ],
                'who' => [
                    'cafe' => __('Useful once a cafe takes many phone orders for delivery.'),
                    'restaurant' => __('The delivery desk: fast phone orders and a customer list that grows.'),
                ],
                'faq' => [
                    [__('Can I see a customer’s history?'), __('Yes, the customer page shows their orders and addresses.')],
                    [__('Are zones drawn on a map?'), __('Yes, with a fee and a minimum order per zone and sub-areas.')],
                    [__('Is it an add-on?'), __('Yes, it comes with delivery zones and the customer list, on top of a paid plan.')],
                ],
                'related' => ['online-ordering', 'kitchen-display', 'inventory'],
            ],
            [
                'slug' => 'inventory', 'group' => 'operations', 'icon' => 'box', 'for' => ['cafe', 'restaurant'],
                'name' => __('Inventory and recipe costing'),
                'tagline' => __('Know what every item costs, and what is running out'),
                'summary' => __('Stock that goes down with every order.'),
                'feature' => 'inventory', 'addons' => ['inventory_pack', 'ops_bundle'],
                'shot' => 'inventory', 'frame' => 'browser', 'url' => 'app.egmenu.app/inventory', 'phone' => null,
                'benefits' => [
                    ['file', __('Recipes'), __('A latte is 18 g of coffee, 200 ml of milk and a cup. Its real cost is calculated.')],
                    ['box', __('Automatic deduction'), __('Each order takes its ingredients out of stock; a cancelled order puts them back.')],
                    ['bell', __('Low stock alert'), __('Set a minimum for each ingredient and see what to buy before it runs out.')],
                ],
                'steps' => [
                    [__('Write the recipe'), __('A latte uses coffee, milk and a cup.')],
                    [__('Sell'), __('Each latte sold takes them out of stock.')],
                    [__('Alert'), __('Chocolate sauce drops under its minimum.')],
                    [__('Receive'), __('New stock comes in and the average cost updates.')],
                ],
                'who' => [
                    'cafe' => __('Coffee beans, milk and cups counted by the gram and the piece.'),
                    'restaurant' => __('Meat, bread and sauces with recipe costing per dish.'),
                ],
                'faq' => [
                    [__('Do I have to set up every recipe?'), __('No. Start with the items that matter most; items without a recipe simply do not deduct.')],
                    [__('Can I record waste?'), __('Yes, record waste and stock counts with a reason, and every movement is kept.')],
                    [__('Is stock per branch?'), __('Yes, each branch has its own stock.')],
                ],
                'related' => ['reports', 'pos', 'multi-branch'],
            ],

            // ---------------- management ----------------
            [
                'slug' => 'reports', 'group' => 'management', 'icon' => 'chart', 'for' => ['cafe', 'restaurant'],
                'name' => __('Reports'),
                'tagline' => __('Sales, busy hours and best sellers, in plain numbers'),
                'summary' => __('What sold, when, and from where.'),
                'feature' => 'daily_reports', 'addons' => [],
                'shot' => 'reports', 'frame' => 'browser', 'url' => 'app.egmenu.app/reports', 'phone' => null,
                'benefits' => [
                    ['chart', __('The day at a glance'), __('Sales, number of orders and the average order, for today, yesterday or the week.')],
                    ['clock', __('Busy hours'), __('See when you are busiest and plan staff and offers around it.')],
                    ['file', __('Export'), __('Download the numbers as a spreadsheet for your accountant, on plans with advanced reports.')],
                ],
                'steps' => [
                    [__('Sales add up'), __('Today’s sales grow with every order.')],
                    [__('Busy hours'), __('The hours chart shows the rush.')],
                    [__('Best sellers'), __('Latte and cheesecake lead the list.')],
                    [__('Export'), __('Download it for the accountant.')],
                ],
                'who' => [
                    'cafe' => __('Which drinks sell in the morning and which in the evening.'),
                    'restaurant' => __('Dine-in against delivery, and the dishes that carry the month.'),
                ],
                'faq' => [
                    [__('Do reports include POS and phone orders?'), __('Yes, every order source is counted and shown separately.')],
                    [__('Can I see each branch?'), __('Yes, with advanced reports you can filter by branch or see them all together.')],
                    [__('What do advanced reports add?'), __('Item performance, a busy-hours heat map, branch and period comparison, CSV exports and a weekly email.')],
                ],
                'related' => ['advanced-reports', 'inventory', 'multi-branch'],
            ],
            [
                'slug' => 'advanced-reports', 'group' => 'management', 'icon' => 'trend', 'for' => ['cafe', 'restaurant'],
                'name' => __('Advanced reports'),
                'tagline' => __('Which items sell, when you are busy, and how branches compare'),
                'summary' => __('Item performance, a heat map, comparisons, CSV and a weekly email.'),
                'feature' => 'advanced_reports', 'addons' => ['reports_pack'],
                'shot' => 'reports-advanced', 'frame' => 'browser', 'url' => 'app.egmenu.app/reports', 'phone' => null,
                'gallery' => [['reports-heatmap', 'browser', 'app.egmenu.app/reports', __('Orders by day and hour, branch against branch, and where orders come from; every table downloads as CSV.')]],
                'benefits' => [
                    ['chart', __('Item performance'), __('How many guests opened each item against how many ordered it, so you see which photo or price needs work.')],
                    ['clock', __('Busy-hours heat map'), __('Days against hours on one map: the darker the cell, the busier you were.')],
                    ['layers', __('Compare'), __('This week against last week, and one branch against another.')],
                    ['file', __('CSV and a weekly email'), __('Download any table as CSV, and get last week’s numbers by email every week.')],
                ],
                'steps' => [
                    [__('Item performance'), __('Cheesecake: opened often, ordered less. Time for a better photo.')],
                    [__('Heat map'), __('Friday evening is the darkest cell.')],
                    [__('Compare'), __('This week against last week, side by side.')],
                    [__('Weekly email'), __('Monday morning, last week’s numbers in your inbox.')],
                ],
                'who' => [
                    'cafe' => __('See which drinks are looked at but not ordered, and fix the photo or the price.'),
                    'restaurant' => __('Compare branches and periods before you change the menu or the staff.'),
                ],
                'faq' => [
                    [__('How is this different from the daily reports?'), __('Daily reports show sales, orders, busy hours and best sellers. Advanced reports add item performance, the heat map, comparisons, exports and the weekly email.')],
                    [__('Who gets the weekly email?'), __('The owner, once the weekly report is switched on in Settings.')],
                    [__('Can I give the numbers to my accountant?'), __('Yes, each table downloads as a CSV file that opens in Excel.')],
                ],
                'related' => ['reports', 'multi-branch', 'inventory'],
            ],
            [
                'slug' => 'multi-branch', 'group' => 'management', 'icon' => 'store', 'for' => ['restaurant'],
                'name' => __('Branches'),
                'tagline' => __('One menu for all branches, with each branch’s own prices'),
                'summary' => __('Per-branch prices, availability, staff and stock.'),
                'feature' => 'branch_pricing', 'addons' => ['branches'],
                'shot' => 'branches', 'frame' => 'browser', 'url' => 'app.egmenu.app/branches', 'phone' => null,
                'benefits' => [
                    ['layers', __('One menu'), __('Edit an item once and every branch gets it.')],
                    ['tag', __('Prices per branch'), __('The mall branch can charge more than the street branch.')],
                    ['users', __('Staff per branch'), __('Each cashier and kitchen sees only their branch’s orders.')],
                ],
                'steps' => [
                    [__('One menu'), __('Sofra’s menu is shared by Maadi and Sheikh Zayed.')],
                    [__('Branch price'), __('The grill costs more in Zayed.')],
                    [__('Sold out here only'), __('Kunafa is out in Maadi, still on in Zayed.')],
                    [__('Each branch’s orders'), __('Orders and reports per branch.')],
                ],
                'who' => [
                    'cafe' => __('A second cafe in another area with the same menu.'),
                    'restaurant' => __('A chain with different prices in malls and on the street.'),
                ],
                'faq' => [
                    [__('How many branches can I have?'), __('Each plan includes a number of branches; add more branches as an add-on.')],
                    [__('Does each branch get its own QR codes?'), __('Yes, tables belong to a branch, so orders arrive at the right place.')],
                    [__('Are add-on prices per branch?'), __('Operations add-ons are priced per branch, with a discount on each extra branch.')],
                ],
                'related' => ['reports', 'inventory', 'custom-domain'],
            ],
            [
                'slug' => 'custom-domain', 'group' => 'management', 'icon' => 'globe', 'for' => ['cafe', 'restaurant'],
                'name' => __('Your own domain'),
                'tagline' => __('Your menu on your own name, like rosto.com'),
                'summary' => __('A free egmenu link from day one, or your own domain.'),
                'feature' => 'custom_domain', 'addons' => ['domain_pack'],
                'shot' => 'domain', 'frame' => 'browser', 'url' => 'app.egmenu.app/domain', 'phone' => null,
                'benefits' => [
                    ['qr', __('A link from day one'), __('Every restaurant gets egmenu.app/name the moment it signs up, with name.egmenu.app as a short alias.')],
                    ['globe', __('Your domain'), __('Book a new domain through EgWeb, or connect one you already own.')],
                    ['shield', __('Secure by default'), __('The menu opens over https on your domain, and your QR codes keep working.')],
                ],
                'steps' => [
                    [__('Your egmenu link'), __('egmenu.app/rosto works from the first day.')],
                    [__('Choose a domain'), __('Search for rosto.com and book it.')],
                    [__('Connect'), __('It is checked and secured with https.')],
                    [__('Live'), __('The menu opens on rosto.com.')],
                ],
                'who' => [
                    'cafe' => __('A short link that looks good on the cups and on Instagram.'),
                    'restaurant' => __('Your brand in the address bar for online orders.'),
                ],
                'faq' => [
                    [__('Do my printed QR codes change?'), __('No. Codes printed with the egmenu link keep working and open your domain.')],
                    [__('Can I use a domain I already have?'), __('Yes, point it to EgMenu from your DNS settings; the page tells you exactly what to add.')],
                    [__('Is the domain itself included?'), __('Connecting is in the plan or the add-on; the yearly domain fee is separate if you book a new one.')],
                ],
                'related' => ['online-ordering', 'qr-menu', 'multi-branch'],
            ],
            [
                'slug' => 'featured-listing', 'group' => 'management', 'icon' => 'star', 'for' => ['cafe', 'restaurant'],
                'name' => __('Featured in the directory'),
                'tagline' => __('Show first in the EgMenu restaurant directory'),
                'summary' => __('Paid placement with a “Featured” badge on your city and cuisine pages.'),
                'feature' => 'featured_listing', 'addons' => ['featured_listing'],
                'shot' => 'directory', 'frame' => 'browser', 'url' => 'egmenu.app/restaurants', 'phone' => null,
                'benefits' => [
                    ['star', __('At the top of the list'), __('Your place shows first on egmenu.app/restaurants and on your city and cuisine pages.')],
                    ['users', __('Shared fairly'), __('Up to 5 featured places take turns on each page, so no single place buys the whole list.')],
                    ['shield', __('Clearly marked'), __('Featured places carry a visible “Featured” badge. Visitors always know it is paid placement.')],
                ],
                'steps' => [
                    [__('Listed'), __('Rosto is in the directory for free, among the cafes of its city.')],
                    [__('Featured'), __('With the add-on it moves to the top, with a badge.')],
                    [__('A visit'), __('A visitor opens Rosto’s page, then its menu.')],
                ],
                'who' => [
                    'cafe' => __('A new cafe that wants to be found by people looking around its area.'),
                    'restaurant' => __('A new branch in a city where people do not know the name yet.'),
                ],
                'faq' => [
                    [__('Is this advertising?'), __('Yes. It is paid placement inside the EgMenu directory, marked with a “Featured” badge. It does not change your ranking on Google.')],
                    [__('Do I have to be in the directory?'), __('Yes. Your place must be listed in the directory; listing is on by default and free, and you can switch it off in Settings.')],
                    [__('How many visitors will I get?'), __('We cannot promise a number. The directory is new and grows with the places in it, and you can stop the add-on in any month.')],
                ],
                'related' => ['qr-menu', 'custom-domain', 'campaigns'],
            ],
        ];
    }
}
