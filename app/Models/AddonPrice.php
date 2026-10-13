<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Price list of add-ons (extra branch, extra staff). */
class AddonPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
