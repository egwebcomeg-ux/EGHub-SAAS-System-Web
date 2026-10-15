<?php

namespace App\Models;

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

    /** Each branch after the first pays this share of a per-branch pack (40% off). */
    public const EXTRA_BRANCH_SHARE = 0.6;

    protected function casts(): array
    {
        return ['monthly_price' => 'float', 'is_active' => 'boolean', 'features' => 'array', 'per_branch' => 'boolean', 'credits' => 'integer'];
    }

    /** Monthly price for this restaurant: per-branch packs grow with its active branches. */
    public function priceFor(Tenant $tenant): float
    {
        if (! $this->per_branch) {
            return (float) $this->monthly_price;
        }
        $branches = max(1, Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->count());

        return round($this->monthly_price * (1 + ($branches - 1) * self::EXTRA_BRANCH_SHARE));
    }

    /** Whether the restaurant's plan may buy it (min_plan compares the plans' order). */
    public function availableFor(?Plan $plan): bool
    {
        if (! $this->min_plan || ! $plan) {
            return true;
        }
        $min = Plan::query()->where('code', $this->min_plan)->value('sort_order');

        return $min === null || $plan->sort_order >= $min;
    }

    public function displayName(): string
    {
        return tr($this->name, $this->name_en);
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
