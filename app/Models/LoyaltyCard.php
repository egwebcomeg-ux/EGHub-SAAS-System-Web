<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer's card in a loyalty program: current stamps and rewards waiting to be used. */
class LoyaltyCard extends TenantModel
{
    protected function casts(): array
    {
        return [
            'last_stamp_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class)->latest('id');
    }

    public function availableRewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class)->where('status', 'available')->orderBy('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class)->latest('id');
    }
}
