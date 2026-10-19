<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Kiosk;
use App\Models\KitchenStation;
use App\Models\TvScreen;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\Qr;
use App\Services\Settings;
use App\Support\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * "Screens & devices": every screen the restaurant opens on a device, in one place: TV menu boards (TvScreen),
 * self-order kiosks (Kiosk), the customer display and order-number board, and the kitchen / bar display links.
 * Each type shows only to whoever manages it today (same permission as its own page) and works only when the plan
 * has its feature; otherwise a locked card points to the plan. Saving a screen or a kiosk still posts to
 * TvScreensController / KiosksController, which come back here.
 */
class DevicesController extends Controller
{
    /** type => [permissions (any), plan feature] */
    public const TYPES = [
        'tv' => [['menu.manage'], 'tv_screens'],
        'kiosk' => [['kiosk.manage'], 'kiosk'],
        'display' => [['pos.use', 'tables.manage'], 'customer_display'],
        'kitchen' => [['kitchen.view'], 'kitchen_display'],
    ];

    /**
     * The device types this user manages, each with whether the plan includes it. A locked type is listed only for
     * whoever handles the subscription, so a card can point to the plan.
     *
     * @return array<string, bool> type => unlocked
     */
    public static function typesFor(User $user): array
    {
        $rights = Entitlements::current();
        $out = [];
        foreach (self::TYPES as $type => [$permissions, $feature]) {
            if (! $user->canAny($permissions)) {
                continue;
            }
            $unlocked = $rights->allows($feature);
            if ($unlocked || $user->can('billing.manage')) {
                $out[$type] = $unlocked;
            }
        }

        return $out;
    }

    /** The sidebar entry: at least one device type this user works with is in the plan (the page also opens without). */
    public static function visibleFor(User $user): bool
    {
        return in_array(true, self::typesFor($user), true);
    }

    public static function label(string $type): string
    {
        return [
            'tv' => __('TV screens'), 'kiosk' => __('Kiosk'), 'display' => __('Customer display & order board'),
            'kitchen' => BusinessType::prepScreen(),
        ][$type] ?? $type;
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $types = self::typesFor($user);
        abort_if($types === [], 403);
        $tab = array_key_exists((string) $request->query('tab'), $types) ? (string) $request->query('tab') : 'all';
        $color = Settings::get('color_secondary');
        $qr = fn (string $url) => Qr::svg($url, 160, is_hex_color($color) ? $color : '#0A2540');
        $shows = fn (string $type) => ($types[$type] ?? false) && ($tab === 'all' || $tab === $type);

        $screens = $shows('tv') ? TvScreen::query()->with('branch')->orderBy('id')->get() : collect();
        $kiosks = $shows('kiosk') ? Kiosk::query()->with('branch')->orderBy('id')->get() : collect();
        $branches = Branch::query()->orderBy('sort_order')->get();

        return view('dashboard.devices', [
            'tab' => $tab,
            'types' => $types,
            'counts' => [
                'tv' => ($types['tv'] ?? false) ? TvScreen::query()->count() : 0,
                'kiosk' => ($types['kiosk'] ?? false) ? Kiosk::query()->count() : 0,
            ],
            'screens' => $screens,
            'kiosks' => $kiosks,
            'qrs' => [
                'tv' => $screens->mapWithKeys(fn (TvScreen $s) => [$s->id => $qr($s->url())]),
                'kiosk' => $kiosks->mapWithKeys(fn (Kiosk $k) => [$k->id => $qr($k->url())]),
            ],
            'boards' => $shows('display') ? $this->boards($branches, $qr) : collect(),
            'posDisplay' => $shows('display') && $user->can('pos.use') && Entitlements::current()->allows('pos') ? route('dash.pos.display') : null,
            'kitchen' => $shows('kitchen') ? $this->kitchenLinks($qr) : collect(),
            'stationsUrl' => $shows('kitchen') && $user->can('menu.manage') && Entitlements::current()->allows('kitchen_stations') ? route('dash.stations') : null,
            'branches' => $branches,
            'activeBranches' => $branches->where('is_active', true)->values(),
            'categories' => $shows('tv') ? Category::query()->orderBy('sort_order')->get(['id', 'name', 'name_en']) : collect(),
        ]);
    }

    /**
     * The order-number board of each open branch (a public page, numbers only).
     *
     * @return Collection<int, array{name: string, url: string, qr: string}>
     */
    private function boards(Collection $branches, \Closure $qr): Collection
    {
        $open = $branches->where('is_active', true)->values();

        return $open->map(function (Branch $b) use ($open, $qr) {
            $url = tenant()->url('board'.($open->count() > 1 ? '?branch='.rawurlencode((string) $b->slug) : ''));

            return ['name' => tr($b->name, $b->name_en), 'url' => $url, 'qr' => $qr($url)];
        });
    }

    /**
     * The kitchen display, and one screen per station when the plan has stations (staff sign in on the device).
     *
     * @return Collection<int, array{name: string, url: string, qr: string}>
     */
    private function kitchenLinks(\Closure $qr): Collection
    {
        $links = collect([['name' => __('All orders'), 'url' => route('dash.kitchen')]]);
        if (Entitlements::current()->allows('kitchen_stations')) {
            foreach (KitchenStation::query()->orderBy('sort_order')->get() as $station) {
                $links->push(['name' => tr($station->name, $station->name_en), 'url' => route('dash.kitchen', ['station' => $station->id])]);
            }
        }

        return $links->map(fn (array $l) => $l + ['qr' => $qr($l['url'])]);
    }
}
