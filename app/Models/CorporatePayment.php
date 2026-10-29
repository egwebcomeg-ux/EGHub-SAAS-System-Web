<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment a company made on its corporate contract (a credit line of the company statement). A payment entered
 * by mistake is cancelled, never deleted: cancelled_at / cancelled_by / cancel_reason, and it is no longer counted.
 */
class CorporatePayment extends TenantModel
{
    /** method => [arabic, english] */
    public const METHODS = [
        'cash' => ['كاش', 'Cash'],
        'transfer' => ['تحويل بنكي', 'Bank transfer'],
        'cheque' => ['شيك', 'Cheque'],
        'instapay' => ['InstaPay', 'InstaPay'],
        'card' => ['كارت', 'Card'],
        'other' => ['أخرى', 'Other'],
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_on' => 'date', 'cancelled_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CorporateContract::class, 'corporate_contract_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function methodLabel(): string
    {
        return CorporateContract::pick(self::METHODS, $this->method);
    }
}
