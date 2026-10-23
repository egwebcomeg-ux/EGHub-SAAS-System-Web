<?php

namespace App\Services;

use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessType;
use App\Support\SiteModules;
use Illuminate\Support\Collection;

/**
 * "What will you use?": the modules (App\Support\SiteModules) a restaurant told us it works with, picked right after
 * signup and changeable from Settings. Focus only: it orders the sidebar and the home page and trims the onboarding
 * checklist, it never grants or removes anything. What a restaurant may use is still App\Services\Entitlements, and
 * every URL keeps working whatever is picked. Stored in the `modules` setting as a JSON list of module slugs;
 * an empty setting means nothing was picked yet, so everything shows as before.
 */
final class ModuleChoice
{
    /** A plan with fewer add-ons is suggested over the cheapest mix when it costs at most this much more a month (EGP or share). */
    private const SIMPLER_MARGIN_EGP = 50;

    private const SIMPLER_MARGIN_RATIO = 0.1;

    /** Always on: the menu is the product. */
    public const LOCKED = ['qr-menu'];

    /**
     * Dashboard sidebar entries that belong to modules: route => the modules any of which keeps it in focus.
     * Entries not listed here (home, menu, tables, users, subscription, settings...) are always shown.
     */
    public const NAV = [
        'dash.orders' => ['table-ordering', 'online-ordering', 'pos', 'kiosk', 'call-center', 'kitchen-display'],
        'dash.pos' => ['pos'],
        'dash.shifts' => ['pos'],
        'dash.callcenter' => ['call-center'],
        'dash.kitchen' => ['kitchen-display'],
        'dash.stations' => ['kitchen-display'],
        'dash.reservations' => ['reservations'],
        'dash.tv' => ['tv-screens'],
        'dash.customers' => ['loyalty', 'campaigns', 'wallet', 'whatsapp', 'call-center', 'online-ordering', 'reservations'],
        'dash.loyalty.desk' => ['loyalty', 'wallet'],
        'dash.cards' => ['wallet', 'loyalty'],
        'dash.marketing' => ['offers'],
        'dash.feedback' => ['reports', 'loyalty'],
        'dash.campaigns' => ['campaigns'],
        'dash.coupons' => ['loyalty', 'campaigns'],
        'dash.wallet' => ['wallet'],
        'dash.corporate' => ['corporate-vouchers'],
        'dash.redeem' => ['corporate-vouchers'],
        'dash.kb' => ['knowledge-base'],
        'dash.inventory' => ['inventory'],
        'dash.zones' => ['online-ordering', 'call-center'],
        'dash.drivers' => ['delivery-drivers'],
        'dash.kiosks' => ['kiosk'],
        'dash.devices' => ['tv-screens', 'kiosk', 'kitchen-display', 'pos'],
        'dash.reports' => ['reports'],
        'dash.branches' => ['multi-branch'],
        'dash.domain' => ['custom-domain'],
    ];

    /** Where a module lives in the dashboard: slug => [route, permission, feature the route needs (null = the module's)]. */
    public const PAGES = [
        'qr-menu' => ['dash.menu', 'menu.manage', null],
        'languages' => ['dash.settings', 'settings.manage', null],
        'printed-menu' => ['dash.menu.print', 'menu.manage', null],
        'tv-screens' => ['dash.tv', 'menu.manage', null],
        'menu-import' => ['dash.import', 'menu.manage', null],
        'table-ordering' => ['dash.orders', 'orders.view', 'order_board'],
        'online-ordering' => ['dash.orders', 'orders.view', 'order_board'],
        'reservations' => ['dash.reservations', 'reservations.manage', null],
        'loyalty' => ['dash.loyalty.desk', 'loyalty.stamp', null],
        'offers' => ['dash.marketing', 'marketing.manage', null],
        'whatsapp' => ['dash.settings', 'settings.manage', null],
        'campaigns' => ['dash.campaigns', 'marketing.manage', null],
        'wallet' => ['dash.wallet', 'wallet.manage', null],
        'corporate-vouchers' => ['dash.corporate', 'contracts.manage', null],
        'knowledge-base' => ['dash.kb', 'kb.manage', null],
        'pos' => ['dash.pos', 'pos.use', null],
        'kiosk' => ['dash.kiosks', 'kiosk.manage', null],
        'kitchen-display' => ['dash.kitchen', 'kitchen.view', null],
        'call-center' => ['dash.callcenter', 'callcenter.use', null],
        'delivery-drivers' => ['dash.drivers', 'delivery.assign', null],
        'inventory' => ['dash.inventory', 'inventory.manage', null],
        'reports' => ['dash.reports', 'reports.view', null],
        'multi-branch' => ['dash.branches', 'branches.manage', null],
        'custom-domain' => ['dash.domain', 'settings.manage', null],
        'featured-listing' => ['dash.billing', 'billing.manage', null],
    ];

    /** Only the owner, or whoever may change the settings, picks the modules. */
    public static function canChange(User $user): bool
    {
        return $user->can('settings.manage');
    }

    /** @return list<string>|null the picked slugs, null while nothing was picked */
    public static function chosen(): ?array
    {
        $raw = Settings::get('modules');
        if ($raw === '') {
            return null;
        }
        $list = json_decode($raw, true);

        return is_array($list) ? self::clean($list) : null;
    }

    public static function hasChoice(): bool
    {
        return self::chosen() !== null;
    }

    /** True while nothing was picked, so a restaurant that never saw the step keeps its full dashboard. */
    public static function isChosen(string $slug): bool
    {
        $chosen = self::chosen();

        return $chosen === null || in_array($slug, $chosen, true);
    }

    /** @param  list<string>  $slugs  @return list<string> the saved list (locked modules always in) */
    public static function save(array $slugs): array
    {
        $clean = self::clean($slugs);
        Settings::put(['modules' => json_encode($clean)]);

        return $clean;
    }

    /** What the step ticks before the owner touches anything: the saved choice, else a start that fits the place. */
    public static function preselected(): array
    {
        return self::chosen() ?? self::defaults(BusinessType::current());
    }

    /** @return list<string> */
    public static function defaults(string $businessType): array
    {
        return self::clean(match ($businessType) {
            // a restaurant that delivers has riders: the drivers module comes with its online ordering
            'restaurant' => ['table-ordering', 'online-ordering', 'kitchen-display', 'delivery-drivers', 'reservations', 'reports'],
            'both' => ['table-ordering', 'online-ordering', 'kitchen-display', 'delivery-drivers', 'loyalty', 'reports'],
            'shop' => ['online-ordering', 'pos', 'loyalty', 'offers'],
            default => ['table-ordering', 'loyalty', 'offers', 'reports'],
        });
    }

    /** Whether a sidebar entry stays in the main list (true) or moves under "more modules" (false). */
    public static function focuses(string $route): bool
    {
        $chosen = self::chosen();
        if ($chosen === null || ! isset(self::NAV[$route])) {
            return true;
        }

        return array_intersect(self::NAV[$route], $chosen) !== [];
    }

    /**
     * The picked modules for the home page: the page of each one this user may open now (plan and permission), and,
     * for whoever handles the subscription, the picked ones the plan does not include yet, pointing to billing.
     *
     * @return list<array{module: array, url: string, locked: bool}>
     */
    public static function shortcuts(User $user): array
    {
        $rights = Entitlements::current();
        $out = [];
        $seen = [];
        foreach (self::chosen() ?? [] as $slug) {
            $module = SiteModules::find($slug);
            [$route, $permission, $feature] = self::PAGES[$slug] ?? [null, null, null];
            if (! $module || ! $route) {
                continue;
            }
            if ($module['feature'] !== null && ! $rights->allows($module['feature'])) {
                if ($user->can('billing.manage')) {
                    $out[] = ['module' => $module, 'url' => route('dash.billing'), 'locked' => true];
                }

                continue;
            }
            $feature ??= $module['feature'];
            if (isset($seen[$route]) || ! $user->can($permission) || ($feature && ! $rights->allows($feature))) {
                continue;
            }
            $seen[$route] = true;
            $out[] = ['module' => $module, 'url' => route($route), 'locked' => false];
        }

        return $out;
    }

    /**
     * The plan check shown on the step, read from the database plans and add-ons: which picked modules the
     * restaurant has now (plan, trial or add-on), which need more, and the cheapest public plan (with the fewest
     * add-ons on top) that covers all of them. Informs only; billing is untouched.
     *
     * @param  list<string>  $slugs
     * @return array{included: list<array>, missing: list<array{module: array, where: string}>, trialing: bool,
     *     plan: Plan, best: ?array{plan: Plan, addons: Collection<int, AddonPrice>, total: float, current: bool}, contact: ?Plan}
     */
    public static function coverage(array $slugs): array
    {
        $tenant = tenant();
        $rights = Entitlements::current();
        $pricing = SiteModules::pricing();
        $modules = array_values(array_filter(array_map(fn (string $s) => SiteModules::find($s), self::clean($slugs))));

        $included = [];
        $missing = [];
        foreach ($modules as $m) {
            if ($m['feature'] === null || $rights->allows($m['feature'])) {
                $included[] = $m;
            } else {
                $missing[] = ['module' => $m, 'where' => SiteModules::where($m, $pricing)];
            }
        }

        $subscription = $tenant->subscription;
        $trialing = $subscription?->status === 'trialing';
        // a paying restaurant that already has everything needs no advice; during the trial, say what keeps it after
        $best = ($missing === [] && ! $trialing) ? null : self::cheapest($modules, $pricing);
        if ($best) {
            $best['current'] = ! $trialing && $best['addons']->isEmpty() && $best['plan']->is($rights->plan);
        }
        // nothing on sale covers the pick (an operations module): the plan agreed with sales that does
        $contact = null;
        if (! $best && $missing !== []) {
            $contact = $pricing['plans']->first(fn (Plan $p) => $p->isContactSales() && collect($modules)
                ->every(fn (array $m) => SiteModules::enabled($pricing['matrix'][$p->id]['features'] ?? [], $m['feature'])));
        }

        return ['included' => $included, 'missing' => $missing, 'trialing' => $trialing, 'plan' => $rights->plan, 'best' => $best, 'contact' => $contact];
    }

    /**
     * The public plan whose monthly price plus the add-ons still needed on top is the lowest.
     *
     * @param  list<array>  $modules
     * @param  array{plans: Collection<int, Plan>, addons: Collection<int, AddonPrice>, matrix: array}  $pricing
     * @return array{plan: Plan, addons: Collection<int, AddonPrice>, total: float}|null
     */
    private static function cheapest(array $modules, array $pricing): ?array
    {
        $tenant = tenant();
        // feature packs, and limit packs that switch a feature on (an extra branch brings per-branch prices)
        $packs = $pricing['addons']->filter(fn (AddonPrice $a) => $a->isFeaturePack() || ($a->type === 'limit' && $a->features))->values();
        $options = [];

        foreach ($pricing['plans'] as $plan) {
            // a plan priced with sales has no price to compare; coverage() names it on its own
            if ($plan->isContactSales()) {
                continue;
            }
            $features = $pricing['matrix'][$plan->id]['features'] ?? [];
            $needed = array_values(array_filter($modules, fn (array $m) => ! SiteModules::enabled($features, $m['feature'])));
            $addons = self::packsFor($needed, $packs->filter(fn (AddonPrice $a) => $a->availableFor($plan))->values(), $tenant);
            if ($addons === null) {
                continue;
            }
            $options[] = ['plan' => $plan, 'addons' => $addons, 'total' => (float) $plan->monthly_price + $addons->sum(fn (AddonPrice $a) => $a->priceFor($tenant))];
        }
        if ($options === []) {
            return null;
        }

        // simpler wins when it costs about the same: one plan with fewer add-ons beats a pile of small packs
        $lowest = min(array_column($options, 'total'));
        $ceiling = $lowest + max(self::SIMPLER_MARGIN_EGP, $lowest * self::SIMPLER_MARGIN_RATIO);
        $close = array_filter($options, fn (array $o) => $o['total'] <= $ceiling);
        usort($close, fn (array $a, array $b) => [$a['addons']->count(), $a['total']] <=> [$b['addons']->count(), $b['total']]);
        $best = $close[0];

        return $best;
    }

    /**
     * The cheapest set of feature packs that switches on every needed module: each module's cheapest pack, or one
     * bundle that covers several of them and the cheapest packs for the rest. Null when one of them is not sold.
     *
     * @param  list<array>  $needed
     * @param  Collection<int, AddonPrice>  $packs
     * @return Collection<int, AddonPrice>|null
     */
    private static function packsFor(array $needed, Collection $packs, Tenant $tenant): ?Collection
    {
        if ($needed === []) {
            return collect();
        }
        $sells = fn (AddonPrice $a, array $m) => in_array($a->key, $m['addons'], true) && SiteModules::sells($a->features, $m['feature']);
        $cheapestFor = fn (array $m) => $packs->filter(fn (AddonPrice $a) => $sells($a, $m))->sortBy(fn (AddonPrice $a) => $a->priceFor($tenant))->first();

        $fill = function (array $rest, Collection $start) use ($cheapestFor, $sells): ?Collection {
            $set = $start;
            foreach ($rest as $m) {
                if ($set->contains(fn (AddonPrice $a) => $sells($a, $m))) {
                    continue;
                }
                $pack = $cheapestFor($m);
                if ($pack === null) {
                    return null;
                }
                $set = $set->push($pack);
            }

            return $set->unique('key')->values();
        };

        $options = array_filter([$fill($needed, collect())]);
        foreach ($packs as $bundle) {
            $covers = array_filter($needed, fn (array $m) => $sells($bundle, $m));
            if (count($covers) >= 2 && ($set = $fill($needed, collect([$bundle])))) {
                $options[] = $set;
            }
        }
        if ($options === []) {
            return null;
        }
        usort($options, fn (Collection $a, Collection $b) => $a->sum(fn ($x) => $x->priceFor($tenant)) <=> $b->sum(fn ($x) => $x->priceFor($tenant)) ?: $a->count() <=> $b->count());

        return $options[0];
    }

    /** @return list<string> known slugs only, in catalogue order, locked modules always in; merged modules follow their new slug */
    private static function clean(array $slugs): array
    {
        $slugs = array_map(fn ($s) => is_string($s) ? (SiteModules::MERGED[$s] ?? $s) : $s, $slugs);
        $wanted = array_merge(self::LOCKED, array_filter($slugs, 'is_string'));

        return array_values(array_filter(array_keys(SiteModules::all()), fn (string $s) => in_array($s, $wanted, true)));
    }
}
