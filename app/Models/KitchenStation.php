<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kitchen station (grill, drinks, desserts). A kitchen screen can follow one station; items follow their category. */
class KitchenStation extends TenantModel
{
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
