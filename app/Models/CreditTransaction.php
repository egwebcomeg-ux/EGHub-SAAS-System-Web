<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One movement of a credit wallet: purchase (+), message (−1), refund (+1 after a provider failure), adjustment (admin). */
class CreditTransaction extends Model
{
    public const REASONS = ['purchase', 'message', 'refund', 'adjustment'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delta' => 'integer', 'balance_after' => 'integer'];
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'purchase' => __('Credit pack bought'),
            'message' => __('Message sent'),
            'refund' => __('Returned (message not delivered)'),
            default => __('Adjusted by EgMenu'),
        };
    }
}
