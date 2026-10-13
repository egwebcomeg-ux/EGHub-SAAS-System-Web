<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Delivery area of a branch: a map polygon, or a sub-zone that inherits the empty fee/min order/time of its parent. */
class DeliveryZone extends TenantModel
{
    protected function casts(): array
    {
        return [
            'polygon'   => 'array',
            'fee'       => 'float',
            'min_order' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
