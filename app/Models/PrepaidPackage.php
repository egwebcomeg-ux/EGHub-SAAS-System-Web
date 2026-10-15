<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A prepaid package on sale: "10 coffees for 150 EGP, valid 30 days" (feature prepaid_packages). */
class PrepaidPackage extends TenantModel
{
    protected function casts(): array
    {
        return ['price' => 'float', 'uses' => 'integer', 'valid_days' => 'integer', 'qualifying' => 'array', 'is_active' => 'boolean'];
    }

    public function displayName(): string
    {
        return tr($this->name, $this->name_en);
    }

    public function sold(): HasMany
    {
        return $this->hasMany(CustomerPackage::class);
    }
}
