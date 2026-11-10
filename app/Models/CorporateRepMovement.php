<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of a rep's balance: put for him (topup, charged to the company), taken back to the company (withdraw),
 * turned into vouchers (issue) or come back from a voided voucher (return). The amount is always positive.
 */
class CorporateRepMovement extends TenantModel
{
    public const UPDATED_AT = null;

    /** kind => [arabic, english] */
    public const KINDS = [
        'topup' => ['شحن رصيد', 'Balance added'],
        'withdraw' => ['رجوع للشركة', 'Back to the company'],
        'issue' => ['فاوتشرات طلعت', 'Vouchers issued'],
        'return' => ['فاوتشر اتلغى', 'Voucher voided'],
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'balance_after' => 'float', 'created_at' => 'datetime'];
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(CorporateRep::class, 'corporate_rep_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return CorporateContract::pick(self::KINDS, $this->kind);
    }
}
