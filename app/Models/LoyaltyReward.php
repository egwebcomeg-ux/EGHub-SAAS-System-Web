<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reward reached on a loyalty card, waiting to be redeemed at the counter. */
class LoyaltyReward extends TenantModel
{
    protected function casts(): array
    {
        return ['value' => 'float', 'redeemed_at' => 'datetime'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class, 'loyalty_card_id');
    }
}
