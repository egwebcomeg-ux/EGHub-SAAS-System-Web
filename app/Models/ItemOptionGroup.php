<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modifier group of an item: single (e.g. size) or multiple (extras). */
class ItemOptionGroup extends TenantModel
{
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ItemOption::class)->orderBy('sort_order');
    }
}
