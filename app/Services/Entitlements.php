<?php

namespace App\Services;

use App\Models\AddonPrice;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\MenuImport;
use App\Models\MenuItem;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;

/**
 * The single answer to "may this restaurant do X / how many Y may it have".
 * Effective rights = the subscription's plan + its add-ons (extra limits, or feature packs such as the POS). Enforced server-side (middleware `feature:`,
 * controllers, services); the UI only mirrors it.
 */
final class Entitlements
{
    /** Plan used when a restaurant has no subscription row (should not happen after provisioning). */
    public const FALLBACK_PLAN = 'starter';

    /** @var array<string,self> per-request memo */
    private static array $memo = [];

    /** @param array<string,bool> $features @param array<string,int|null> $limits */
    private function __construct(
        public readonly Tenant $tenant,
        public readonly Plan $plan,
        private array $features,
        private array $limits,
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
        $packs = AddonPrice::query()->where('type', 'feature')->pluck('features', 'key');
        foreach ($sub?->addons ?? [] as $addon) {
            if (array_key_exists($addon->key, $e['limits']) && $e['limits'][$addon->key] !== null) {
                $e['limits'][$addon->key] += $addon->quantity;
            }
            // feature packs (POS, kitchen display, ...) switch their features on, whatever the plan
            foreach ((array) ($packs[$addon->key] ?? []) as $feature) {
                if (array_key_exists($feature, $e['features'])) {
                    $e['features'][$feature] = true;
                }
            }
        }

        return new self($tenant, $plan, $e['features'], $e['limits']);
    }

    public function allows(string $feature): bool
    {
        return $this->features[$feature] ?? false;
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

    /** Message shown when a limit or feature blocks an action. */
    public function upgradeMessage(string $key): string
    {
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
