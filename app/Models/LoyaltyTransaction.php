<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only loyalty ledger row (stamp, points, reward, redeem, reverse, expire). */
class LoyaltyTransaction extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class, 'loyalty_card_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
