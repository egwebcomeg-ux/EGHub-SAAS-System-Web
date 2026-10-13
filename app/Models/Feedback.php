<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A guest rating (1-5) with an optional comment, from the menu or after an order. */
class Feedback extends TenantModel
{
    public const UPDATED_AT = null;

    protected $table = 'feedback';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
