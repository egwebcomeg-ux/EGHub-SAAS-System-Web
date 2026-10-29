<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient on a purchase invoice: quantity in the ingredient's unit and the price paid per unit. */
class PurchaseLine extends TenantModel
{
    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cost' => 'float', 'total' => 'float'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
