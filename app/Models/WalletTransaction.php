<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a customer's prepaid wallet ledger: the deltas of a customer always add up to credit_balance. */
class WalletTransaction extends TenantModel
{
    public const UPDATED_AT = null;

    /** kind => [arabic, english] */
    public const KINDS = [
        'topup' => ['شحن رصيد', 'Top-up'],
        'payment' => ['دفع طلب', 'Order payment'],
        'refund' => ['استرداد', 'Refund'],
        'gift_redeem' => ['كارت هدية', 'Gift card'],
        'adjustment' => ['تعديل يدوي', 'Adjustment'],
        'cashback' => ['كاش باك', 'Cashback'],
        'cashback_rev' => ['إلغاء كاش باك', 'Cashback reversed'],
    ];

    protected function casts(): array
    {
        return ['delta' => 'float', 'balance_after' => 'float', 'created_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        $l = self::KINDS[$this->kind] ?? [$this->kind, $this->kind];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
