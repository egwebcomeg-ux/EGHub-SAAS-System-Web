<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A dish/drink on the menu. Hidden when inactive; visible but not orderable when sold out. */
class MenuItem extends TenantModel
{
    protected function casts(): array
    {
        return [
            'price'       => 'float',
            'cost'        => 'float',
            'badges'      => 'array',
            'allergens'   => 'array',
            'is_active'   => 'boolean',
            'is_sold_out' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ItemOptionGroup::class)->orderBy('sort_order');
    }

    public function branchOverrides(): HasMany
    {
        return $this->hasMany(BranchItem::class);
    }

    public function displayName(): string
    {
        return app()->getLocale() === 'en' && $this->name_en !== '' ? $this->name_en : $this->name;
    }
}
