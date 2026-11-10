<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A company's rep ("مندوب"): the person the restaurant deals with for part of the company's people. He has a balance
 * of his own (what was put for him and is not on vouchers yet), the vouchers issued out of it, and, when allowed, an
 * allowance to order on the company's account outside the vouchers (his one `direct` voucher, linked to his mobile).
 */
class CorporateRep extends TenantModel
{
    protected function casts(): array
    {
        return ['balance' => 'float', 'is_active' => 'boolean'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CorporateContract::class, 'corporate_contract_id');
    }

    /** The printed vouchers issued out of his balance. */
    public function vouchers(): HasMany
    {
        return $this->hasMany(CorporateVoucher::class)->where('direct', false);
    }

    /** His allowance on the company's account, or null until a manager gives one. */
    public function directVoucher(): HasOne
    {
        return $this->hasOne(CorporateVoucher::class)->where('direct', true);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CorporateRepMovement::class);
    }
}
