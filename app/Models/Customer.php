<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest of the restaurant, identified by phone. Owns the digital loyalty card (card_token). A guest who came in
 * with Google (google_sub, email) may have no mobile yet: the column is null and reads as "".
 * The guest's own sign-in at THIS restaurant: email (proven once email_verified_at is set) and an optional password
 * (App\Services\Customers\SignIn\PasswordSignIn); pending_email / pending_phone are typed but not proven yet.
 */
class Customer extends TenantModel
{
    protected $hidden = ['password'];

    /** A missing mobile reads as "" so every caller keeps handling a string. */
    protected function phone(): Attribute
    {
        return Attribute::get(fn (?string $value) => (string) $value);
    }

    protected static function booted(): void
    {
        static::creating(function (self $customer) {
            $customer->card_token ??= random_token(12);
        });
    }

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'addresses' => 'array',
            'total_spent' => 'float',
            'credit_balance' => 'float',
            'marketing_opt_in' => 'boolean',
            'whatsapp_opt_out' => 'boolean',
            'last_visit_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
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

    /** Prepaid wallet ledger (WalletService); its deltas add up to credit_balance. */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
