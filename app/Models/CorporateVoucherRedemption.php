<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One use of a corporate voucher on an order: a line of the company statement. refunded_at = the order was cancelled. */
class CorporateVoucherRedemption extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'float', 'forfeited' => 'float', 'refunded_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(CorporateVoucher::class, 'corporate_voucher_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CorporateContract::class, 'corporate_contract_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
