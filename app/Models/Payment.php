<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment attempt against an invoice (manual or through a gateway). */
class Payment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'payload' => 'array',
            'installments' => 'boolean',
        ];
    }

    /** Gateways the restaurant pays through online (the rest are manual transfers EgWeb confirms by hand). */
    public const ONLINE_GATEWAYS = ['kashier'];

    /** Paid (or being paid) through an online gateway, not a transfer the restaurant claims. */
    public function isOnline(): bool
    {
        return in_array($this->gateway, self::ONLINE_GATEWAYS, true);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
