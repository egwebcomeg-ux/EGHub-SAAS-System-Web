<?php

namespace App\Services;

use App\Billing\LaunchOffer;
use App\Models\AddonPrice;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TvScreen;
use App\Models\User;

/**
 * The single answer to "may this restaurant do X / how many Y may it have".
 * Effective rights = the subscription's plan + its add-ons (extra limits, or feature packs such as the POS). Enforced server-side (middleware `feature:`,
 * controllers, services); the UI only mirrors it.
 */
final class Entitlements
{
    /** Plan used when a restaurant has no subscription row (should not happen after provisioning). */
    public const FALLBACK_PLAN = 'free';

    /** @var array<string,self> per-request memo */
    private static array $memo = [];

    /** @param array<string,bool> $features @param array<string,int|null> $limits */
    private function __construct(
        public readonly Tenant $tenant,
        public readonly Plan $plan,
        private array $features,
        private array $limits,
        private bool $paid = false,
    ) {}

    public static function for(Tenant $tenant): self
    {
        return self::$memo[$tenant->getTenantKey()] ??= self::build($tenant);
    }

    public static function current(): self
    {
        return self::for(tenant() ?? throw new \LogicException('No tenant'));
    }

    /** Forget memoised rights (after plan changes and between tests). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    private static function build(Tenant $tenant): self
    {
        $sub = $tenant->subscription()->with(['plan.features', 'addons'])->first();
        $plan = $sub?->plan ?? Plan::query()->with('features')->where('code', self::FALLBACK_PLAN)->first()
            ?? throw new \RuntimeException('Plans are missing — run the PlanSeeder.');
        $e = $plan->entitlements();
        // feature packs and limit packs alike may switch features on (an extra branch brings per-branch prices)
        $packs = AddonPrice::query()->whereIn('type', ['feature', 'limit'])->pluck('features', 'key');
        // a limit add-on adds `credits` units per pack bought (1 branch, 5 staff accounts, 50 items, 10 tables)
        $units = AddonPrice::query()->where('type', 'limit')->pluck('credits', 'key');
        foreach ($sub?->addons ?? [] as $addon) {
            if (array_key_exists($addon->key, $e['limits']) && $e['limits'][$addon->key] !== null) {
                $e['limits'][$addon->key] += $addon->quantity * max(1, (int) ($units[$addon->key] ?? 1));
            }
            // every extra branch comes with its own staff accounts
            if ($addon->key === 'branches' && $e['limits']['staff'] !== null) {
                $e['limits']['staff'] += $addon->quantity * AddonPrice::STAFF_PER_EXTRA_BRANCH;
            }
            // feature packs (POS, kitchen display, ...) switch their features on, whatever the plan
            foreach ((array) ($packs[$addon->key] ?? []) as $feature) {
                if (array_key_exists($feature, $e['features'])) {
                    $e['features'][$feature] = true;
                }
            }
        }

        // online orders are taken, made ready and paid on the order board: whoever may take them has the board
        if (($e['features']['online_ordering'] ?? false) && array_key_exists('order_board', $e['features'])) {
            $e['features']['order_board'] = true;
        }

        // the launch offer's free second branch counts at once inside its window (trial included); once it is claimed
        // as the first branch add-on, that add-on carries it (and is billed after the 12 months)
        $boughtBranches = (int) ($sub?->addons->firstWhere('key', LaunchOffer::ADDON)?->quantity ?? 0);
        if ($boughtBranches === 0 && LaunchOffer::coversBranchNow($sub) && $e['limits']['branches'] !== null) {
            $e['limits']['branches'] += 1;
            if ($e['limits']['staff'] !== null) {
                $e['limits']['staff'] += AddonPrice::STAFF_PER_EXTRA_BRANCH;
            }
        }

        // a paying restaurant: an active subscription on a plan other than Free (trial, past due and canceled are not)
        $paid = $sub !== null && $sub->status === 'active' && $plan->code !== self::FALLBACK_PLAN;

        return new self($tenant, $plan, $e['features'], $e['limits'], $paid);
    }

    /** "a|b" is allowed when either feature is. */
    public function allows(string $feature): bool
    {
        foreach (explode('|', $feature) as $one) {
            if ($this->features[$one] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the restaurant pays for its plan. Until it does (trial or Free) its guest pages carry the "by EgHub"
     * capsule whatever the plan includes; the "Powered by EgHub" link keeps following the hide_badge feature.
     */
    public function isPaid(): bool
    {
        return $this->paid;
    }

    /** The "by EgHub" capsule on the guest pages: every restaurant that does not pay yet. */
    public function showsBrandCapsule(): bool
    {
        return ! $this->paid;
    }

    /** null = unlimited */
    public function limit(string $key): ?int
    {
        return array_key_exists($key, $this->limits) ? $this->limits[$key] : 0;
    }

    /** Current usage of a limit, counted inside this restaurant only. */
    public function usage(string $key): int
    {
        return $this->inTenant(fn () => match ($key) {
            'branches' => Branch::query()->count(),
            'menu_items' => MenuItem::query()->count(),
            'staff' => User::query()->count(),
            'tables' => DiningTable::query()->count(),
            'screens' => TvScreen::query()->count(),
            'ai_imports_per_month' => MenuImport::query()->whereIn('source', ['image', 'pdf'])
                ->where('created_at', '>=', now()->startOfMonth())->count(),
            default => 0,
        });
    }

    public function canAdd(string $key, int $n = 1): bool
    {
        $limit = $this->limit($key);

        return $limit === null || $this->usage($key) + $n <= $limit;
    }

    /**
     * The contact-sales plan (Enterprise) when it is the only way to get $feature ("a|b": any of them): no plan a
     * restaurant can pick itself and no add-on on sale includes it. Null when an upgrade or an add-on unlocks it.
     */
    public static function salesOnlyPlanFor(string $feature): ?Plan
    {
        $keys = explode('|', $feature);
        $has = fn (Plan $plan) => collect($keys)->contains(fn (string $k) => (bool) ($plan->entitlements()['features'][$k] ?? false));
        $plans = Plan::query()->offered()->with('features')->orderBy('sort_order')->get();
        if ($plans->contains(fn (Plan $plan) => $plan->isSelfServe() && $has($plan))) {
            return null;
        }
        $inAddon = AddonPrice::query()->where('is_active', true)->whereIn('type', ['feature', 'limit'])->get()
            ->contains(fn (AddonPrice $a) => array_intersect($keys, (array) $a->features) !== []);

        return $inAddon ? null : $plans->first(fn (Plan $plan) => $plan->isContactSales() && $has($plan));
    }

    /** Message shown when a limit or feature blocks an action. */
    public function upgradeMessage(string $key): string
    {
        $key = explode('|', $key)[0];
        $label = Plan::label($key);
        if (isset(Plan::FEATURES[$key])) {
            return __('The ":feature" feature is not included in your plan (:plan). Upgrade to unlock it.',
                ['feature' => $label, 'plan' => $this->plan->displayName()]);
        }

        return __('You reached the maximum :limit for your plan (:plan: :max). Upgrade or add an add-on.',
            ['limit' => $label, 'plan' => $this->plan->displayName(), 'max' => (string) $this->limit($key)]);
    }

    /** @return array<string,bool> */
    public function features(): array
    {
        return $this->features;
    }

    /** @return array<string,int|null> */
    public function limits(): array
    {
        return $this->limits;
    }

    /** Runs $fn scoped to this restaurant, also when called from the central context (super admin). */
    private function inTenant(\Closure $fn): mixed
    {
        if (tenant()?->getTenantKey() === $this->tenant->getTenantKey()) {
            return $fn();
        }
        $previous = tenant();
        tenancy()->initialize($this->tenant);
        try {
            return $fn();
        } finally {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();
        }
    }
}
