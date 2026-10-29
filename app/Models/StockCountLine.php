<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient on a count sheet: what the system expected when it was counted, what was on the shelf, and once
 * posted the adjustment written and the unit cost it was valued at.
 */
class StockCountLine extends TenantModel
{
    protected function casts(): array
    {
        return ['expected' => 'float', 'counted' => 'float', 'adjustment' => 'float', 'unit_cost' => 'float', 'counted_at' => 'datetime'];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
