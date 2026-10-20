<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;

/** One point of the short trail of an order while it is out for delivery (capped per order). */
class DeliveryTrackPoint extends TenantModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
