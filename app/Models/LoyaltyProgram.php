<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The restaurant's loyalty rules.
 *   visits: a stamp per qualifying visit (order >= min_order, at most one per day)
 *   items:  a stamp per qualifying item (selected categories/items), e.g. "every 5 coffees, the 6th is free"
 *   points: points per EGP spent
 * Rewards can sit along the card (milestones: 30% at 5, free at 10); the last one completes the card and a new one
 * starts. While cards exist, stamps_required and milestones are locked so nobody's progress changes under them.
 */
class LoyaltyProgram extends TenantModel
{
    public const TYPES = [
        'visits' => ['بعدد الزيارات', 'Per visit'],
        'items' => ['بعدد أصناف معينة', 'Per qualifying item'],
        'points' => ['نقاط على المبلغ', 'Points per EGP'],
    ];

    public const REWARD_TYPES = [
        'free_item' => ['صنف مجاني', 'Free item'],
        'discount_percent' => ['خصم نسبة', 'Percent discount'],
        'discount_fixed' => ['خصم مبلغ', 'Fixed discount'],
    ];

    protected function casts(): array
    {
        return [
            'qualifying_items' => 'array',
            'milestones' => 'array',
            'reward_items' => 'array',
            'card_design' => 'array',
            'min_order' => 'float',
            'points_per_egp' => 'float',
            'reward_value' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** Card size and milestones can't change once guests hold cards. */
    public function isLocked(): bool
    {
        return $this->exists && $this->cards()->exists();
    }

    public function cards(): HasMany
    {
        return $this->hasMany(LoyaltyCard::class);
    }
}
