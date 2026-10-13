<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quantity of one ingredient in one branch. Changed only through StockService so every change is logged. */
class IngredientStock extends TenantModel
{
    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
