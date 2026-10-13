<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One choice inside an option group; its price is added to the item price. */
class ItemOption extends TenantModel
{
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_default' => 'boolean',
            'is_sold_out' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemOptionGroup::class, 'item_option_group_id');
    }
}
