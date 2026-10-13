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

    /** Fee / minimum / minutes, taken from the parent zone when this one leaves them empty. */
    public function effective(string $field): mixed
    {
        return $this->{$field} ?? $this->parent?->{$field};
    }

    public function label(): string
    {
        return ($this->parent ? tr($this->parent->name, $this->parent->name_en) . ' › ' : '') . tr($this->name, $this->name_en);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
