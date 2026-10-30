<?php

namespace App\Models;

use App\Billing\CustomPlan;
use App\Billing\PricingRules;
use Illuminate\Database\Eloquent\Model;

/**
 * Something sold on top of a plan:
 *   type=limit   — raises a plan limit by its quantity (extra branch, extra staff)
 *   type=feature — unlocks a set of features (POS pack, kitchen display, call center, inventory)
 *   type=credits — a one-off pack of WhatsApp message credits (credits = messages); paid once, not monthly
 */
class AddonPrice extends Model
{
    protected $guarded = ['id'];

    /** Each branch after the first pays this share of a per-branch pack (40% off) until the owner sets it (PricingRules). */
    public const EXTRA_BRANCH_SHARE = 0.6;

    /** Staff accounts every extra branch (limit add-on `branches`) adds on top of the plan's. */
    public const STAFF_PER_EXTRA_BRANCH = 8;

    /** Extra branches a restaurant may add by itself (so 5 branches in all); more is the Enterprise plan. */
    public const MAX_SELF_SERVE_EXTRA_BRANCHES = 4;

    protected function casts(): array
    {
        return ['monthly_price' => 'float', 'is_active' => 'boolean', 'features' => 'array', 'per_branch' => 'boolean', 'credits' => 'integer'];
    }

    /**
     * Monthly price for this restaurant: per-branch packs grow with its active branches. On the custom plan a pack
     * that otherwise needs a bigger plan costs its markup more (PricingRules::operationsMarkup()).
     */
    public function priceFor(Tenant $tenant, ?Plan $plan = null): float
    {
        $price = (float) $this->monthly_price;
        if ($this->per_branch) {
            $branches = max(1, Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->count());
            $price = round($this->monthly_price * (1 + ($branches - 1) * PricingRules::extraBranchShare()));
        }

        return $plan && CustomPlan::marksUp($this, $plan) ? CustomPlan::markedUp($price) : $price;
    }

    /**
     * Whether the restaurant's plan may buy it (min_plan compares the plans' order). The custom plan may take every
     * module pack, the ones that need a bigger plan at a markup; limit packs (an extra branch) keep their min_plan.
     */
    public function availableFor(?Plan $plan): bool
    {
        if ($plan?->isCustom() && $this->isFeaturePack()) {
            return true;
        }
        if (! $this->min_plan || ! $plan) {
            return true;
        }
        $min = Plan::query()->where('code', $this->min_plan)->value('sort_order');

        return $min === null || $plan->sort_order >= $min;
    }

    /** The name in the visitor's language, its digits Latin like every number in the app. */
    public function displayName(): string
    {
        return latin_digits(tr($this->name, $this->name_en));
    }

    public function displayDescription(): string
    {
        return latin_digits((string) tr($this->description, $this->description_en));
    }

    public function isFeaturePack(): bool
    {
        return $this->type === 'feature';
    }

    public function isCreditPack(): bool
    {
        return $this->type === 'credits';
    }
}
