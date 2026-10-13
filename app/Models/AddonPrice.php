<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something sold on top of a plan:
 *   type=limit   — raises a plan limit by its quantity (extra branch, extra staff)
 *   type=feature — unlocks a set of features (POS pack, kitchen display, call center, inventory)
 */
class AddonPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monthly_price' => 'float', 'is_active' => 'boolean', 'features' => 'array'];
    }

    public function displayName(): string
    {
        return tr($this->name, $this->name_en);
    }

    public function isFeaturePack(): bool
    {
        return $this->type === 'feature';
    }
}
