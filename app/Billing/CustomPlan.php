<?php

namespace App\Billing;

use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\ModuleChoice;
use App\Support\SiteModules;
use Illuminate\Support\Collection;

/**
 * "صمم باقتك": a restaurant builds its own plan from the modules it uses. It is one plan row (code `custom`) with the
 * free plan's base (the menu), and the subscription carries a feature pack (AddonPrice) for every module ticked, so
 * Entitlements, invoices, renewals and the move to the free plan when it is not paid (Lifecycle) work as for any
 * plan with add-ons. Nothing is created per restaurant.
 *  - each module costs its pack's monthly price; a pack that otherwise needs the Complete plan (the cashier, the
 *    kitchen screen, the call center...) costs OPERATIONS_MARKUP more;
 *  - the plan costs at least MINIMUM_MONTHLY a month;
 *  - a module that needs another brings it (NEEDS), and says why;
 *  - paying 6 or 12 months takes the Complete plan's discount off the whole plan (Plan::cycleDiscounts);
 *  - when the pick costs about as much as the Complete plan (COMPLETE_NUDGE_RATIO) and Complete covers it, the page
 *    says so and offers Complete instead.
 */
final class CustomPlan
{
    public const CODE = 'custom';

    /** The least a custom plan costs a month (EGP), whatever is ticked. */
    public const MINIMUM_MONTHLY = 299;

    /** Extra share on a pack that otherwise needs the Complete plan (min_plan) when it is bought on a custom plan. */
    public const OPERATIONS_MARKUP = 0.25;

    /** From this share of the Complete plan's price (with what it needs on top), Complete is suggested instead. */
    public const COMPLETE_NUDGE_RATIO = 0.9;

    /** The plan the custom plan is compared with, and whose cycle discounts it gives. */
    public const COMPLETE = 'full';

    /** Modules sold apart from the custom plan: the featured directory place (on any plan) and extra branches. */
    public const OUTSIDE = ['featured-listing', 'multi-branch'];

    /** module => [the modules any one of which it needs, the one added when none of them is ticked] */
    public const NEEDS = [
        'kitchen-display' => [['table-ordering', 'online-ordering', 'pos', 'kiosk', 'call-center'], 'table-ordering'],
        'call-center' => [['online-ordering'], 'online-ordering'],
        'delivery-drivers' => [['online-ordering', 'call-center'], 'online-ordering'],
        'kiosk' => [['pos'], 'pos'],
    ];

    /** The custom plan, while it is offered (archiving it in the super admin takes "صمم باقتك" off every page). */
    public static function plan(): ?Plan
    {
        return Plan::query()->offered()->with('features')->where('code', self::CODE)->first();
    }

    /** @return array<string, float|int> the Complete plan's cycle discounts, months => % */
    public static function cycleDiscounts(): array
    {
        return (array) (Plan::query()->where('code', self::COMPLETE)->first()?->cycle_discounts ?? []);
    }

    /** Whether this pack costs the markup on this plan: a module pack that otherwise needs a bigger plan. */
    public static function marksUp(AddonPrice $pack, Plan $plan): bool
    {
        return $plan->isCustom() && $pack->isFeaturePack() && (string) $pack->min_plan !== '';
    }

    public static function markedUp(float $price): float
    {
        return round($price * (1 + self::OPERATIONS_MARKUP));
    }

    /** A pack's monthly price on the custom plan: for this restaurant (its branches), or the list price. */
    public static function priceOf(AddonPrice $pack, Plan $plan, ?Tenant $tenant = null): float
    {
        if ($tenant) {
            return $pack->priceFor($tenant, $plan);
        }

        return self::marksUp($pack, $plan) ? self::markedUp((float) $pack->monthly_price) : (float) $pack->monthly_price;
    }

    /**
     * Every module the custom plan sells or includes, in catalogue order: included = in the base (the menu), packs =
     * what it costs alone (null = not sold on its own, only in the Complete plan).
     *
     * @return list<array{module: array, included: bool, packs: ?Collection<int, AddonPrice>, price: float, markup: bool}>
     */
    public static function catalog(Plan $plan, ?Tenant $tenant = null): array
    {
        $features = $plan->entitlements()['features'];
        $packs = self::packs($plan);
        $out = [];
        foreach (SiteModules::all() as $module) {
            if (in_array($module['slug'], self::OUTSIDE, true)) {
                continue;
            }
            $included = SiteModules::enabled($features, $module['feature']);
            $own = $included ? collect() : ModuleChoice::packsFor([$module], $packs, fn (AddonPrice $a) => self::priceOf($a, $plan, $tenant));
            $out[] = [
                'module' => $module, 'included' => $included, 'packs' => $own,
                'price' => $own ? (float) $own->sum(fn (AddonPrice $a) => self::priceOf($a, $plan, $tenant)) : 0.0,
                'markup' => $own ? $own->contains(fn (AddonPrice $a) => self::marksUp($a, $plan)) : false,
            ];
        }

        return $out;
    }

    /**
     * The modules a pick comes to: known, sold ones only, plus what they need (NEEDS).
     *
     * @param  list<string>  $slugs
     * @return array{slugs: list<string>, added: array<string, string>} added = module brought in => the module that needs it
     */
    public static function resolve(array $slugs, Plan $plan): array
    {
        $sellable = collect(self::catalog($plan))->filter(fn (array $row) => $row['included'] || $row['packs'] !== null)
            ->map(fn (array $row) => $row['module']['slug'])->values()->all();
        $slugs = array_map(fn ($s) => is_string($s) ? (SiteModules::MERGED[$s] ?? $s) : '', $slugs);
        $picked = array_values(array_intersect($sellable, $slugs));
        $added = [];
        do {
            $before = count($picked);
            foreach (self::NEEDS as $slug => [$anyOf, $fallback]) {
                if (in_array($slug, $picked, true) && array_intersect($anyOf, $picked) === [] && in_array($fallback, $sellable, true)) {
                    $picked[] = $fallback;
                    $added[$fallback] = $slug;
                }
            }
        } while (count($picked) > $before);

        return ['slugs' => array_values(array_intersect($sellable, $picked)), 'added' => $added];
    }

    /**
     * What a pick costs a month and per cycle, with the packs it takes and how it compares with the Complete plan.
     *
     * @param  list<string>  $slugs
     * @return array{slugs: list<string>, added: array<string, string>, packs: Collection<int, AddonPrice>, modules: float,
     *     monthly: float, topUp: float, cycles: array<int, float>, discounts: array<string, float|int>,
     *     complete: ?array{plan: Plan, addons: Collection<int, AddonPrice>, total: float}, nudge: bool}
     */
    public static function quote(array $slugs, Plan $plan, ?Tenant $tenant = null): array
    {
        ['slugs' => $picked, 'added' => $added] = self::resolve($slugs, $plan);
        $modules = array_map(fn (string $s) => SiteModules::find($s), $picked);
        $features = $plan->entitlements()['features'];
        $needed = array_values(array_filter($modules, fn (array $m) => ! SiteModules::enabled($features, $m['feature'])));
        $priceOf = fn (AddonPrice $a) => self::priceOf($a, $plan, $tenant);
        $packs = ModuleChoice::packsFor($needed, self::packs($plan), $priceOf) ?? collect();

        $sum = (float) $packs->sum($priceOf);
        $monthly = max((float) self::MINIMUM_MONTHLY, $sum);
        $discounts = $plan->cycle_discounts;
        $cycles = [];
        foreach (Plan::CYCLES as $months) {
            $cycles[$months] = round($monthly * $months * (1 - (float) ($discounts[(string) $months] ?? 0) / 100));
        }
        $complete = self::complete($modules, $tenant);

        return [
            'slugs' => $picked, 'added' => $added, 'packs' => $packs, 'modules' => $sum, 'monthly' => $monthly,
            'topUp' => round($monthly - $sum, 2), 'cycles' => $cycles, 'discounts' => $discounts, 'complete' => $complete,
            'nudge' => $complete !== null && $monthly >= self::COMPLETE_NUDGE_RATIO * $complete['total'],
        ];
    }

    /**
     * The Complete plan with the add-ons on sale that it needs for this pick, or null when Complete can not cover it
     * (a module that is in the Enterprise plan only).
     *
     * @param  list<array>  $modules
     * @return array{plan: Plan, addons: Collection<int, AddonPrice>, total: float}|null
     */
    public static function complete(array $modules, ?Tenant $tenant = null): ?array
    {
        $plan = Plan::query()->offered()->with('features')->where('code', self::COMPLETE)->first();
        if (! $plan || $plan->isContactSales()) {
            return null;
        }
        $features = $plan->entitlements()['features'];
        $needed = array_values(array_filter($modules, fn (array $m) => ! SiteModules::enabled($features, $m['feature'])));
        $priceOf = fn (AddonPrice $a) => $tenant ? $a->priceFor($tenant, $plan) : (float) $a->monthly_price;
        $onSale = AddonPrice::query()->where('is_active', true)->whereIn('type', ['feature', 'limit'])->orderBy('sort_order')->get()
            ->filter(fn (AddonPrice $a) => $a->availableFor($plan))->values();
        $addons = ModuleChoice::packsFor($needed, $onSale, $priceOf);

        return $addons === null ? null : ['plan' => $plan, 'addons' => $addons, 'total' => (float) $plan->monthly_price + $addons->sum($priceOf)];
    }

    /**
     * The packs a custom plan is built from: every module pack (sold today on its own or not) and the per-screen
     * pack, never message credits.
     *
     * @return Collection<int, AddonPrice>
     */
    public static function packs(Plan $plan): Collection
    {
        return AddonPrice::query()->whereIn('type', ['feature', 'limit'])->orderBy('sort_order')->get()
            ->filter(fn (AddonPrice $a) => $a->availableFor($plan))->values();
    }

    /**
     * The modules this subscription's packs switch on, for ticking the builder of a restaurant already on it.
     *
     * @param  list<string>  $packKeys
     * @return list<string>
     */
    public static function modulesOf(array $packKeys, Plan $plan): array
    {
        $packs = AddonPrice::query()->whereIn('key', $packKeys)->get();
        $out = [];
        foreach (self::catalog($plan) as $row) {
            $module = $row['module'];
            if (! $row['included'] && $packs->contains(fn (AddonPrice $a) => in_array($a->key, $module['addons'], true) && SiteModules::sells($a->features, $module['feature']))) {
                $out[] = $module['slug'];
            }
        }

        return $out;
    }
}
