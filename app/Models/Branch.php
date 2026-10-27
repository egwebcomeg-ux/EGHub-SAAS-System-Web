<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A restaurant branch. Branch prices/availability live in branch_items (null = inherit). */
class Branch extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'delivery_rings' => 'array',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function itemOverrides(): HasMany
    {
        return $this->hasMany(BranchItem::class);
    }
}
