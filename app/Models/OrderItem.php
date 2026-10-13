<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Order line. Name and prices are snapshots taken when the order was placed. */
class OrderItem extends TenantModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'unit_price' => 'float',
            'line_total' => 'float',
            'options' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
