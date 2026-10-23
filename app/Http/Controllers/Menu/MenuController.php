<?php

namespace App\Http\Controllers\Menu;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\MenuVisit;
use App\Models\Order;
use App\Models\Season;
use App\Services\Entitlements;
use App\Services\ItemVideo;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Menu\BestSellers;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\BusyMode;
use App\Services\Orders\CartGoal;
use App\Services\Seo\RestaurantProfile;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\DineIn;
use App\Support\ItemBadges;
use App\Support\ItemPlaceholder;
use App\Support\Leaflet;
use App\Support\MenuIntro;
use App\Support\MenuLayouts;
use App\Support\MenuThemes;
use App\Support\MenuTicker;
use App\Support\OpeningHours;
use App\Support\OrderTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/** Public menu of the current restaurant (tenancy initialised from the URL). */
class MenuController extends Controller
{
    /**
     * The shareable link of one item (/item/{id}): the menu with that item opened (the full item page in the
     * professional display, the sheet in the simple one), with the item's name, description and photo in the link
     * preview. An item that is not on the menu now goes to the menu.
     */
    public function item(Request $request, int $item): Response|RedirectResponse
    {
        return $this->show($request, $item);
    }

    public function show(Request $request, ?int $openItem = null): Response|RedirectResponse
    {
        $table = self::currentTable($request);
        // one visit per browser session and day
        $visitKey = 'visit.'.tenant()->getTenantKey();
        if ($request->session()->get($visitKey) !== today()->toDateString()) {
            $request->session()->put($visitKey, today()->toDateString());
            MenuVisit::bump('views');
        }
        // the branch: a scanned table fixes it; else ?branch=slug (remembered in a cookie), else the remembered one
        $chosenBranch = $table ? null : $this->branch($request);
        $catalog = MenuCatalog::forBranch($table?->branch ?? $chosenBranch);
        $branch = $catalog->branch;
        $categories = $catalog->categories();
        $settings = Settings::all();
        $theme = MenuThemes::current($settings);
        // a running season swaps the cover and the logo (its accent goes through the theme tokens)
        $season = Season::live();
        if ($season?->banner) {
            $settings['cover'] = $season->banner;
        }
        if ($season?->logo) {
            $settings['logo'] = $season->logo;
        }
        $activeBranches = Branch::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $branchPicker = $table === null && $activeBranches->count() > 1;
        $currency = tr($settings['currency'], $settings['currency_en']);
        $goal = CartGoal::current($settings)?->forMenu($catalog, $currency);
        $videos = Entitlements::current()->allows(ItemVideo::FEATURE);

        // the hall: a scanned table always orders "in the place"; the general link may also offer takeaway / delivery
        $dineMode = DineIn::mode($settings);
        $tableOrder = $table !== null && $dineMode === 'order';
        $online = $table === null ? self::onlineOptions($branch, $settings) : null;
        $canOrder = $tableOrder || $online !== null;
        $hall = self::hall($settings, $dineMode, $table);
        $ways = array_values(array_filter([
            $hall['mode'] !== 'off' ? 'dine' : null,
            $online && $online['takeaway'] ? 'takeaway' : null,
            $online && $online['delivery'] ? 'delivery' : null,
        ]));
        // the ways of every branch (2+ branches, no table): the start sheet offers a way when some branch has it and
        // greys it out, with a switch to a branch that has it, while the chosen branch does not (resources/js/menu.js)
        $branchWays = $branchPicker ? self::branchWays($activeBranches, $settings, $hall) : [];
        $sheetWays = $branchPicker
            ? array_values(array_filter(['dine', 'takeaway', 'delivery'], fn (string $w) => in_array($w, $ways, true)
                || collect($branchWays)->contains(fn (array $bw) => in_array($w, $bw, true))))
            : $ways;
        $choice = $table === null && $settings['order_type_prompt'] === '1' && ($ways[0] ?? null) === 'dine' && $online !== null;
        // one first-open sheet: how the guest orders (2+ ways) and, with 2+ branches and no table, from which branch
        $firstSheet = ($table === null && count($sheetWays) > 1) || $branchPicker;
        $calls = $table !== null && Entitlements::current()->allows('qr_ordering');
        $upsell = Entitlements::current()->allows('upsell');
        $available = $categories->flatMap(fn (Category $c) => $c->items)->keyBy('id');
        $opened = $openItem !== null ? $available->get($openItem) : null;
        if ($openItem !== null && $opened === null) {
            return redirect()->route('menu.show');
        }
        $shownCategories = $categories->keyBy('id');
        // announcements: the banner strip, and the one banner shown as a popup when the menu opens
        $announcements = Entitlements::current()->allows('offers')
            ? Banner::query()->where('is_active', true)->with('item')->orderBy('sort_order')->orderBy('id')->get()->filter(fn (Banner $b) => $b->isLive())
            : collect();
        $banners = $announcements->reject(fn (Banner $b) => $b->is_popup)->take(Banner::MAX_ACTIVE)->values();
        $popup = $announcements->filter(fn (Banner $b) => $b->is_popup)->sortByDesc('id')->first();
        $popupLink = $popup ? self::bannerLink($popup, $available, $shownCategories) : null;

        $loyalty = app(LoyaltyService::class)->program();
        $profile = new RestaurantProfile;
        // prepaid balance of the guest whose card this phone remembers (cookie set by their card link)
        $cardCookie = $request->cookie('card_'.tenant()->getTenantKey());
        $walletGuest = $canOrder && WalletService::enabled() && is_string($cardCookie)
            ? Customer::query()->where('card_token', $cardCookie)->first() : null;

        $zonesDrawn = $online && collect($online['zones'])->contains(fn ($z) => $z['polygon'] !== null);
        $display = MenuLayouts::display($settings);
        $cartMode = $tableOrder ? 'table' : ($online ? 'online' : self::hallCart($hall['mode']));
        // the top of the menu: banner slideshow and ticker, only one of them moves (App\Support\MenuTicker)
        $bannerAutoplay = MenuTicker::bannerAutoplay($banners->count(), $settings);
        // "الأكثر طلباً" from the branch's real sales (empty: the featured row stays), the round or chip category row
        $bestSellers = $settings['menu_best_sellers'] !== '0' ? BestSellers::forMenu($branch, $available) : collect();
        $categoryIcons = $categories->mapWithKeys(fn (Category $c) => [$c->id => ItemPlaceholder::forCategory($c, $settings['business_type'])]);
        $ticker = MenuTicker::lines($catalog->runningOffers(), $goal && ($canOrder || $hall['mode'] === 'list') ? $goal : null,
            $season, $settings, $available, $shownCategories, $currency);
        $response = response()->view('menu.show', [
            'theme' => $theme,
            'display' => $display,
            'opened' => $opened,
            'badgeChips' => ItemBadges::chips($settings, $available),
            'bestSellers' => $bestSellers,
            'categoryStyle' => $settings['menu_category_style'] === 'circles' ? 'circles' : 'chips',
            'categoryIcons' => $categoryIcons,
            'greeting' => self::greeting(),
            'layouts' => $categories->mapWithKeys(fn (Category $c) => [$c->id => MenuLayouts::forCategory($c, $theme, $settings)]),
            'bannerAutoplay' => $bannerAutoplay,
            'ticker' => $ticker,
            'tickerMoves' => MenuTicker::moves(count($ticker), $bannerAutoplay),
            // the quick "+" of the items: whenever the guest can build a cart with prices (an order, or the hall list)
            'quickAdd' => $canOrder || ($hall['mode'] === 'list' && $hall['prices']),
            'quickOn' => in_array($cartMode, ['table', 'online'], true) || ($cartMode === 'list' && $hall['prices']),
            'season' => $season,
            'goal' => $goal,
            'branchPicker' => $branchPicker,
            'branchChosen' => $chosenBranch !== null,
            'logoTile' => MenuThemes::logoTile($theme, $settings),
            'placeholders' => $settings['item_placeholder'] !== '0',
            'profile' => $profile,
            'schemas' => [$profile->menuSchema($categories)],
            'loyalty' => $loyalty,
            'cardToken' => $loyalty ? $request->cookie('card_'.tenant()->getTenantKey()) : null,
            'feedback' => Entitlements::current()->allows('feedback'),
            'settings' => $settings,
            'categories' => $categories,
            'table' => $table,
            'branch' => $branch,
            'branches' => $activeBranches,
            'canOrder' => $canOrder,
            'online' => $online,
            'offers' => $catalog->runningOffers(),
            'popup' => $popup,
            'popupLink' => $popupLink,
            'banners' => $banners,
            'shownCategories' => $shownCategories,
            'available' => $available,
            'busy' => $table === null ? BusyMode::state() : null,
            'hall' => $hall,
            'ways' => $ways,
            'sheetWays' => $sheetWays,
            'choice' => $choice,
            'calls' => $calls,
            'intro' => MenuIntro::style($settings, $request),
            'introBackground' => MenuIntro::background($settings, MenuThemes::logoTile($theme, $settings),
                MenuThemes::tokens($theme, $settings)['vars']['--menu-bg']),
            // the same rule for the header: a bare logo in the brand colour would vanish on a header of that colour
            'headerBackground' => MenuIntro::background($settings, MenuThemes::logoTile($theme, $settings),
                MenuThemes::tokens($theme, $settings)['vars']['--menu-bg'], 'header_background'),
            'openNow' => OpeningHours::isOpenAt($branch->working_hours, now()),
            'firstSheet' => $firstSheet,
            'branchFirst' => $branchPicker && $chosenBranch === null,
            'data' => [
                // the banner shown as a popup: never over the intro or the first-open sheet (resources/js/menu.js)
                'popup' => $popup ? [
                    'id' => $popup->id, 'frequency' => $popup->popup_frequency,
                    'title' => tr($popup->title, $popup->title_en),
                    'image' => ($picture = $popup->picture()) ? '/storage/'.$picture : null,
                    'item' => $popupLink['item'] ?? null, 'category' => $popupLink['category'] ?? null, 'link' => $popupLink['url'] ?? null,
                    'afterFirstSheet' => $firstSheet,
                    'eventUrl' => route('menu.popup.event', ['popup' => $popup->id, 'event' => '__E__']),
                ] : null,
                'tenant' => tenant()->getTenantKey(),
                'goal' => $goal,
                // the item page's "الأكثر طلباً" badge, the placeholder icons (cart lines, favourites, item page)
                'best' => $bestSellers->pluck('id')->values(),
                'placeholders' => $settings['item_placeholder'] !== '0',
                'phIcons' => collect(ItemPlaceholder::ICONS)->mapWithKeys(fn (string $icon) => [$icon => ItemPlaceholder::svg($icon, 'size-[50%]')]),
                // ?reorder={tracking token} from the order page: that order's lines that can still be ordered
                'reorder' => $canOrder || $hall['mode'] === 'list' ? self::reorder($request, $available) : null,
                'phone' => (string) ($branch->phone ?: $settings['phone']),
                // professional display: an item opens as a full page with its own address; /item/{id} opens it first
                'display' => $display,
                'openItem' => $opened?->id,
                'itemUrl' => route('menu.item', ['item' => 0]),
                'menuUrl' => route('menu.show'),
                // branch switcher (2+ branches, no table): coordinates for "nearest branch", open now, map link
                'branch' => $branch->id,
                'branchChosen' => $chosenBranch !== null,
                'branches' => $branchPicker ? $activeBranches->map(fn (Branch $b) => [
                    'id' => $b->id, 'slug' => $b->slug, 'name' => tr($b->name, $b->name_en), 'address' => $b->address,
                    'lat' => $b->lat, 'lng' => $b->lng, 'map' => $b->maps_url ?: ($b->lat !== null ? "https://www.google.com/maps?q={$b->lat},{$b->lng}" : null),
                    'open' => OpeningHours::isOpenAt($b->working_hours, now()), 'hours' => $b->working_hours,
                    'ways' => $branchWays[$b->id] ?? [],
                ])->values() : [],
                // "outside every zone": the guest can still ask the branch on WhatsApp
                'whatsapp' => ($wa = (string) ($branch->whatsapp ?: $settings['whatsapp'] ?: $branch->phone)) !== '' ? whatsapp_number($wa) : null,
                // "pin my location" at the delivery step: the map library loads on demand (App\Support\Leaflet), the
                // map opens on the branch; with drawn zones the pin picks the zone and must fall inside one
                'leaflet' => $online && $online['delivery'] ? Leaflet::assets() : null,
                'zoneMode' => $settings['delivery_zone_mode'] === 'map' ? 'map' : 'manual',
                'branchPoint' => $branch->lat !== null && $branch->lng !== null ? [(float) $branch->lat, (float) $branch->lng] : null,
                'canOrder' => $canOrder,
                // first mode of the cart; on the general link the guest's choice (sessionStorage) switches hall <-> online
                'mode' => $cartMode,
                'hall' => $hall,
                'ways' => $ways,
                'choice' => $choice,
                'callUrl' => $calls ? route('menu.call') : null,
                'wallet' => $walletGuest && $walletGuest->credit_balance > 0
                    ? ['balance' => round((float) $walletGuest->credit_balance, 2), 'phone' => $walletGuest->phone] : null,
                'online' => $online,
                'currency' => tr($settings['currency'], $settings['currency_en']),
                'orderUrl' => route('menu.order'),
                'couponUrl' => route('menu.coupon'),
                'viewUrl' => route('menu.item.view', ['item' => 0]),
                'service' => (float) $settings['service_percent'],
                'tax' => (float) $settings['tax_percent'],
                'items' => $categories->flatMap(fn (Category $c) => $c->items)->mapWithKeys(fn (MenuItem $i) => [$i->id => [
                    'id' => $i->id, 'name' => $i->displayName(), 'price' => (float) $i->price, 'soldOut' => $i->is_sold_out,
                    'icon' => $categoryIcons[$i->category_id] ?? 'plate',
                    'offerFrom' => $i->getAttribute('offer_from'), 'offerPercent' => $i->getAttribute('offer_percent'),
                    'pairings' => $upsell ? $i->pairings->pluck('id')->filter(fn ($id) => $available->has($id))->values() : [],
                    'combo' => $upsell && $i->comboParts->isNotEmpty() ? [
                        'parts' => $i->comboParts->map(fn ($p) => ['name' => $p->displayName(), 'qty' => (int) $p->pivot->quantity])->values(),
                        'saving' => max(0, round($i->comboParts->sum(fn ($p) => $p->price * $p->pivot->quantity) - $i->price, 2)),
                    ] : null,
                    'description' => tx($i, 'description'), 'image' => $i->image ? '/storage/'.$i->image : null,
                    'video' => $videos ? self::video($i) : null,
                    'calories' => $i->calories,
                    'allergens' => $i->allergenList(), 'spicy' => $i->spicyIcons(),
                    'badges' => array_values(array_filter($i->tags(), fn ($t) => ! ($i->spicyIcons() && $t['key'] === 'spicy'))),
                    'groups' => $i->optionGroups->map(fn ($g) => [
                        'id' => $g->id, 'name' => tx($g, 'name'), 'type' => $g->type, 'required' => $g->is_required,
                        'max' => $g->max_choices,
                        'options' => $g->options->map(fn ($o) => [
                            'id' => $o->id, 'name' => tx($o, 'name'), 'price' => (float) $o->price,
                            'soldOut' => $o->is_sold_out, 'default' => $o->is_default,
                        ])->values(),
                    ])->values(),
                ]]),
            ],
        ]);
        // geolocation only on this page and only for "nearest branch", "detect my area" and "use my current location"
        // on the delivery pin (each asked on a tap)
        if ($branchPicker || $zonesDrawn || ($online && $online['delivery'])) {
            $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        }
        if ($chosenBranch) {
            $response->withCookie(cookie()->forever($this->branchKey(), $chosenBranch->slug));
        }

        return $response;
    }

    /**
     * The video of an item for the sheet: an upload (muted loop, poster = photo) or a link embed loaded on tap.
     *
     * @return array{type:string, src:string, provider?:string}|null
     */
    private static function video(MenuItem $item): ?array
    {
        if ($item->video) {
            return ['type' => 'file', 'src' => '/storage/'.$item->video];
        }
        $embed = ItemVideo::embed($item->video_url);

        return $embed ? ['type' => 'embed', 'src' => $embed['embed'], 'provider' => $embed['provider']] : null;
    }

    /** The line over the search bar, by the hour of the restaurant: morning, day or evening. */
    private static function greeting(): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour >= 5 && $hour < 12 => __('Good morning ☀️'),
            $hour >= 12 && $hour < 17 => __('Welcome 👋'),
            default => __('Good evening 🌙'),
        };
    }

    /**
     * "اطلب تاني" from the order page (?reorder={tracking token}): the lines of that order of THIS restaurant (the
     * tenant scope of Order) that can be ordered again now, with today's prices (the cart reads them from the menu).
     * A line is skipped when its item left the menu or is sold out, or a chosen option is gone or a required choice
     * would be missing; gift lines of a cart goal are left out.
     *
     * @param  Collection<int, MenuItem>  $available
     * @return array{lines: list<array{item_id:int, option_ids:list<int>, qty:int, notes:string}>, skipped:int}|null
     */
    public static function reorder(Request $request, Collection $available): ?array
    {
        $token = $request->query('reorder');
        if (! is_string($token) || $token === '' || strlen($token) > 80) {
            return null;
        }
        $order = Order::query()->where('tracking_token', $token)->with('items')->first();
        if ($order === null) {
            return null;
        }
        $lines = [];
        $skipped = 0;
        foreach ($order->items as $line) {
            if ((float) $line->line_total <= 0 && (float) $line->unit_price <= 0) {
                continue;
            }
            $item = $line->menu_item_id ? $available->get($line->menu_item_id) : null;
            $optionIds = $item ? self::reorderOptions($item, (array) ($line->options ?? [])) : null;
            if ($item === null || $item->is_sold_out || $optionIds === null) {
                $skipped++;

                continue;
            }
            $lines[] = ['item_id' => $item->id, 'option_ids' => $optionIds, 'qty' => min(30, max(1, (int) $line->quantity)), 'notes' => (string) $line->notes];
        }

        return ['lines' => $lines, 'skipped' => $skipped];
    }

    /**
     * The option ids of an old order line that still exist on the item (null when one is gone or sold out, or a
     * required choice would be missing).
     *
     * @param  list<array<string, mixed>>  $snapshot
     * @return list<int>|null
     */
    private static function reorderOptions(MenuItem $item, array $snapshot): ?array
    {
        $options = $item->optionGroups->flatMap(fn ($group) => $group->options)->reject(fn ($option) => $option->is_sold_out)->keyBy('id');
        $ids = [];
        foreach ($snapshot as $chosen) {
            if (! isset($chosen['id'])) {
                continue; // the parts of a combo, written for the kitchen
            }
            if (! $options->has((int) $chosen['id'])) {
                return null;
            }
            $ids[] = (int) $chosen['id'];
        }
        foreach ($item->optionGroups as $group) {
            if ($group->is_required && ! $group->options->contains(fn ($option) => in_array($option->id, $ids, true))) {
                return null;
            }
        }

        return $ids;
    }

    /** QR of a table: remember the table for this restaurant, then show the menu on a clean URL. */
    public function table(Request $request, string $token): RedirectResponse
    {
        $table = DiningTable::query()->where('qr_token', $token)->where('is_active', true)->firstOrFail();
        $request->session()->put($this->tableKey(), ['id' => $table->id, 'at' => now()->timestamp]);
        $table->increment('scans_count', 1, ['last_scanned_at' => now()]);
        MenuVisit::bump('scans');

        // the owner chooses whether a scanned QR opens the menu or the place's page with its links (setting
        // qr_target "links": the about page, which carries the links on top)
        return redirect()->route(Settings::get('qr_target') === 'links' ? 'menu.about' : 'menu.show');
    }

    /**
     * What a guest without a table can order from the menu link (plan feature online_ordering), or null.
     *
     * @return array{branch:int, takeaway:bool, delivery:bool, zones:list<array<string,mixed>>, scheduled:bool, prep:int}|null
     */
    public static function onlineOptions(Branch $branch, array $settings): ?array
    {
        if (! Entitlements::current()->allows('online_ordering') || $settings['ordering_enabled'] !== '1' || BusyMode::isBusy()) {
            return null;
        }
        $zones = $settings['online_delivery'] === '1'
            ? DeliveryZone::query()->where('branch_id', $branch->id)->where('is_active', true)->with('parent')->get()
                ->sortBy(fn ($z) => $z->label())->map(fn ($z) => [
                    'id' => $z->id, 'name' => $z->label(), 'fee' => (float) ($z->effective('fee') ?? 0),
                    'min' => (float) ($z->effective('min_order') ?? 0), 'minutes' => $z->effective('estimated_minutes'),
                    // to the door from sending: preparation + the zone's time, as the tracking page counts it
                    'eta' => OrderTracking::deliveryMinutes((int) $z->effective('estimated_minutes'), $settings),
                    'parent' => $z->parent_id, 'polygon' => is_array($z->polygon) && count($z->polygon) >= 3 ? $z->polygon : null,
                ])->values()->all()
            : [];
        $takeaway = $settings['online_takeaway'] === '1';
        if (! $takeaway && $zones === []) {
            return null;
        }

        return [
            'branch' => $branch->id, 'takeaway' => $takeaway, 'delivery' => $zones !== [], 'zones' => $zones,
            'scheduled' => Entitlements::current()->allows('scheduled_orders'), 'prep' => OrderTracking::prepMinutes($settings),
        ];
    }

    /**
     * The ways to order of each active branch, for the start sheet: the hall (one setting for all), takeaway (on for
     * all) and delivery only where the branch has an active zone, nothing while online ordering is off or busy.
     *
     * @param  Collection<int, Branch>  $branches
     * @param  array{mode:string}  $hall
     * @return array<int, list<string>>
     */
    public static function branchWays(Collection $branches, array $settings, array $hall): array
    {
        $online = Entitlements::current()->allows('online_ordering') && $settings['ordering_enabled'] === '1' && ! BusyMode::isBusy();
        $delivering = $online && $settings['online_delivery'] === '1'
            ? DeliveryZone::query()->where('is_active', true)->whereIn('branch_id', $branches->pluck('id'))->distinct()->pluck('branch_id')->all()
            : [];
        $takeaway = $online && $settings['online_takeaway'] === '1';

        return $branches->mapWithKeys(fn (Branch $b) => [$b->id => array_values(array_filter([
            $hall['mode'] !== 'off' ? 'dine' : null,
            $takeaway ? 'takeaway' : null,
            in_array($b->id, $delivering) ? 'delivery' : null,
        ]))])->all();
    }

    /**
     * What the hall cart does for this visit. Sending needs a scanned table (the QR session proves the guest sits
     * there), so on the general link an "order" hall works as a list for the waiter with a hint to scan the table QR.
     *
     * @return array{mode:string, prices:bool, notice:string, table:?string, scanHint:bool}
     */
    public static function hall(array $settings, string $dineMode, ?DiningTable $table): array
    {
        return [
            'mode' => $dineMode === 'order' && $table === null ? 'list' : $dineMode,
            'prices' => DineIn::showPrices($settings, $dineMode),
            'notice' => DineIn::notice($settings),
            'table' => $table?->name,
            'scanHint' => $dineMode === 'order' && $table === null,
        ];
    }

    /** Cart mode of the hall: table (sends the order), list (shows the waiter) or none (menu only). */
    private static function hallCart(string $hallMode): string
    {
        return match ($hallMode) {
            'order' => 'table',
            'list' => 'list',
            default => 'none',
        };
    }

    /** The table scanned in this session within the last 4 hours (after that the guest has surely left). */
    public static function currentTable(Request $request): ?DiningTable
    {
        $saved = $request->session()->get('table.'.tenant()->getTenantKey());
        if (! is_array($saved) || ($saved['at'] ?? 0) < now()->subHours(4)->timestamp) {
            return null;
        }

        return DiningTable::query()->where('is_active', true)->with('branch')->find($saved['id'] ?? 0);
    }

    /** Views and taps of the popup banner (counters only, no personal data). */
    public function popupEvent(int $popup, string $event): JsonResponse
    {
        Banner::query()->whereKey($popup)->where('is_popup', true)->increment($event === 'click' ? 'clicks' : 'views');

        return response()->json(['ok' => true]);
    }

    /**
     * Where a banner leads, only to what is on the menu right now: an item, a category, or an address.
     *
     * @param  Collection<int, MenuItem>  $available
     * @param  Collection<int, Category>  $shownCategories
     * @return array{item?: int, category?: int, url?: string}
     */
    private static function bannerLink(Banner $banner, Collection $available, Collection $shownCategories): array
    {
        return match ($banner->linkType()) {
            'item' => $available->has($banner->menu_item_id) ? ['item' => (int) $banner->menu_item_id] : [],
            'category' => $shownCategories->has($banner->category_id) ? ['category' => (int) $banner->category_id] : [],
            'url' => ['url' => (string) $banner->link_url],
            default => [],
        };
    }

    /** The branch the guest chose: ?branch=slug, else the one remembered from an earlier visit (cookie). */
    private function branch(Request $request): ?Branch
    {
        $slug = $request->query('branch') ?: $request->cookie($this->branchKey());

        return is_string($slug) && $slug !== '' ? Branch::query()->where('is_active', true)->where('slug', $slug)->first() : null;
    }

    private function branchKey(): string
    {
        return 'branch_'.tenant()->getTenantKey();
    }

    private function tableKey(): string
    {
        return 'table.'.tenant()->getTenantKey();
    }
}
