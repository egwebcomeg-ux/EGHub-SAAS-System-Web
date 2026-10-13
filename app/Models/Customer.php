<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A guest of the restaurant, identified by phone. Owns the digital loyalty card (card_token). */
class Customer extends TenantModel
{
    protected static function booted(): void
    {
        static::creating(function (self $customer) {
            $customer->card_token ??= random_token(12);
        });
    }

    protected function casts(): array
    {
        return [
            'birthday'          => 'date',
            'addresses'         => 'array',
            'total_spent'       => 'float',
            'credit_balance'    => 'float',
            'marketing_opt_in'  => 'boolean',
            'last_visit_at'     => 'datetime',
            'last_contacted_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyCards(): HasMany
    {
        return $this->hasMany(LoyaltyCard::class);
    }
}
