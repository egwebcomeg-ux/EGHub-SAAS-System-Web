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
use App\Models\Popup;
use App\Models\Season;
use App\Services\Entitlements;
use App\Services\ItemVideo;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Menu\MenuCatalog;
use App\Services\Orders\BusyMode;
use App\Services\Orders\CartGoal;
use App\Services\Seo\RestaurantProfile;
use App\Services\Settings;
use App\Services\Wallet\WalletService;
use App\Support\DineIn;
use App\Support\MenuIntro;
use App\Support\MenuThemes;
use App\Support\OpeningHours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public menu of the current restaurant (tenancy initialised from the URL). */
class MenuController extends Controller
{
    public function show(Request $request): Response
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
        $choice = $table === null && $settings['order_type_prompt'] === '1' && $ways[0] === 'dine' && $online !== null;
        $calls = $table !== null && Entitlements::current()->allows('qr_ordering');
        $upsell = Entitlements::current()->allows('upsell');
        $available = $categories->flatMap(fn (Category $c) => $c->items)->keyBy('id');
        $popup = Entitlements::current()->allows('offers')
            ? Popup::query()->where('is_active', true)->with('item')->latest('id')->get()->first(fn ($p) => $p->isLive($branch->id) && (! $p->menu_item_id || $available->has($p->menu_item_id)))
            : null;
        $banners = Entitlements::current()->allows('offers')
            ? Banner::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->filter(fn (Banner $b) => $b->isLive())->take(Banner::MAX_ACTIVE)->values()
            : collect();
        $shownCategories = $categories->keyBy('id');

        $loyalty = app(LoyaltyService::class)->program();
        $profile = new RestaurantProfile;
        // prepaid balance of the guest whose card this phone remembers (cookie set by their card link)
        $cardCookie = $request->cookie('card_'.tenant()->getTenantKey());
        $walletGuest = $canOrder && WalletService::enabled() && is_string($cardCookie)
            ? Customer::query()->where('card_token', $cardCookie)->first() : null;

        $zonesDrawn = $online && collect($online['zones'])->contains(fn ($z) => $z['polygon'] !== null);
        $response = response()->view('menu.show', [
            'theme' => $theme,
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
            'banners' => $banners,
            'shownCategories' => $shownCategories,
            'available' => $available,
            'busy' => $table === null ? BusyMode::state() : null,
            'hall' => $hall,
            'ways' => $ways,
            'choice' => $choice,
            'calls' => $calls,
            'intro' => MenuIntro::style($settings, $request),
            'openNow' => OpeningHours::isOpenAt($branch->working_hours, now()),
            'data' => [
                'popup' => $popup ? [
                    'id' => $popup->id, 'type' => $popup->type, 'frequency' => $popup->frequency, 'delay' => $popup->delay_seconds,
                    'title' => tr($popup->title, $popup->title_en), 'body' => tr((string) $popup->body, (string) $popup->body_en),
                    'image' => $popup->image ? '/storage/'.$popup->image : ($popup->item?->image ? '/storage/'.$popup->item->image : null),
                    'item' => $popup->menu_item_id, 'button' => $popup->button_text, 'link' => $popup->link_url,
                    'eventUrl' => route('menu.popup.event', ['popup' => $popup->id, 'event' => '__E__']),
                ] : null,
                'tenant' => tenant()->getTenantKey(),
                'goal' => $goal,
                // branch switcher (2+ branches, no table): coordinates for "nearest branch", open now, map link
                'branch' => $branch->id,
                'branchChosen' => $chosenBranch !== null,
                'branches' => $branchPicker ? $activeBranches->map(fn (Branch $b) => [
                    'id' => $b->id, 'slug' => $b->slug, 'name' => tr($b->name, $b->name_en), 'address' => $b->address,
                    'lat' => $b->lat, 'lng' => $b->lng, 'map' => $b->maps_url ?: ($b->lat !== null ? "https://www.google.com/maps?q={$b->lat},{$b->lng}" : null),
                    'open' => OpeningHours::isOpenAt($b->working_hours, now()), 'hours' => $b->working_hours,
                ])->values() : [],
                // "outside every zone": the guest can still ask the branch on WhatsApp
                'whatsapp' => ($wa = (string) ($branch->whatsapp ?: $settings['whatsapp'] ?: $branch->phone)) !== '' ? whatsapp_number($wa) : null,
                'canOrder' => $canOrder,
                // first mode of the cart; on the general link the guest's choice (sessionStorage) switches hall <-> online
                'mode' => $tableOrder ? 'table' : ($online ? 'online' : self::hallCart($hall['mode'])),
                'hall' => $hall,
                'ways' => $ways,
                'choice' => $choice,
                'callUrl' => $calls ? route('menu.call') : null,
                'wallet' => $walletGuest && $walletGuest->credit_balance > 0
                    ? ['balance' => round((float) $walletGuest->credit_balance, 2), 'phone' => $walletGuest->phone] : null,
                'online' => $online,
                'currency' => tr($settings['currency'], $settings['currency_en']),
                'orderUrl' => route('menu.order'),
                'viewUrl' => route('menu.item.view', ['item' => 0]),
                'service' => (float) $settings['service_percent'],
                'tax' => (float) $settings['tax_percent'],
                'items' => $categories->flatMap(fn (Category $c) => $c->items)->mapWithKeys(fn (MenuItem $i) => [$i->id => [
                    'id' => $i->id, 'name' => $i->displayName(), 'price' => (float) $i->price, 'soldOut' => $i->is_sold_out,
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
        // geolocation only on this page and only for "nearest branch" / "detect my area" (asked on a tap)
        if ($branchPicker || $zonesDrawn) {
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

    /** QR of a table: remember the table for this restaurant, then show the menu on a clean URL. */
    public function table(Request $request, string $token): RedirectResponse
    {
        $table = DiningTable::query()->where('qr_token', $token)->where('is_active', true)->firstOrFail();
        $request->session()->put($this->tableKey(), ['id' => $table->id, 'at' => now()->timestamp]);
        $table->increment('scans_count', 1, ['last_scanned_at' => now()]);
        MenuVisit::bump('scans');

        // the owner chooses whether a scanned QR opens the menu or the links page (setting qr_target)
        return redirect()->route(Settings::get('qr_target') === 'links' ? 'menu.links' : 'menu.show');
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
                    'parent' => $z->parent_id, 'polygon' => is_array($z->polygon) && count($z->polygon) >= 3 ? $z->polygon : null,
                ])->values()->all()
            : [];
        $takeaway = $settings['online_takeaway'] === '1';
        if (! $takeaway && $zones === []) {
            return null;
        }

        return [
            'branch' => $branch->id, 'takeaway' => $takeaway, 'delivery' => $zones !== [], 'zones' => $zones,
            'scheduled' => Entitlements::current()->allows('scheduled_orders'), 'prep' => (int) $settings['online_prep_minutes'],
        ];
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

    public function popupEvent(int $popup, string $event): JsonResponse
    {
        Popup::query()->whereKey($popup)->increment($event === 'click' ? 'clicks' : 'views');

        return response()->json(['ok' => true]);
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
