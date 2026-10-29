<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A raw material or a packaging material (cups, lids, bags …) counted in one unit; its cost is the weighted average of
 * what was bought.
 */
class Ingredient extends TenantModel
{
    /** kind => [arabic, english] */
    public const KINDS = [
        'ingredient' => ['خامة', 'Ingredient'],
        'packaging' => ['تعبئة وتغليف', 'Packaging'],
    ];

    public const UNITS = [
        'g' => ['جرام', 'g'],
        'kg' => ['كيلو', 'kg'],
        'ml' => ['ملّي', 'ml'],
        'l' => ['لتر', 'L'],
        'pcs' => ['قطعة', 'pcs'],
    ];

    protected function casts(): array
    {
        return ['cost_per_unit' => 'float', 'min_stock' => 'float', 'is_active' => 'boolean'];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(IngredientStock::class);
    }

    public function kindLabel(): string
    {
        $k = self::KINDS[$this->kind] ?? [$this->kind, $this->kind];

        return app()->getLocale() === 'en' ? $k[1] : $k[0];
    }

    public function unitLabel(): string
    {
        $u = self::UNITS[$this->unit] ?? [$this->unit, $this->unit];

        return app()->getLocale() === 'en' ? $u[1] : $u[0];
    }
}
