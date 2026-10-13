<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Branch override of an item. Null price = main menu price. */
class BranchItem extends TenantModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_available' => 'boolean',
            'is_sold_out' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
