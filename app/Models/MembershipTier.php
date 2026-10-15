<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;

/**
 * A membership level (Silver / Gold / VIP): reached by visits or spend, gives a discount on every order.
 * A guest's tier is the highest one whose thresholds they pass (App\Services\Loyalty\Membership).
 */
class MembershipTier extends TenantModel
{
    protected function casts(): array
    {
        return ['min_visits' => 'integer', 'min_spent' => 'float', 'discount_percent' => 'float'];
    }

    public function displayName(): string
    {
        return tr($this->name, $this->name_en);
    }

    public function qualifies(Customer $customer): bool
    {
        return $customer->visits_count >= $this->min_visits && $customer->total_spent >= $this->min_spent;
    }
}
