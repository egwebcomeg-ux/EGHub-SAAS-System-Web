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
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
