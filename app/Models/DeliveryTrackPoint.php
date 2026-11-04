<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;

/** One point of the short trail of an order while it is out for delivery (capped per order). */
class DeliveryTrackPoint extends TenantModel
{
    use MassPrunable;

    public $timestamps = false;

    /** The trail is only read while the order is on its way: a month of points is kept, then `model:prune` drops them. */
    public function prunable(): Builder
    {
        return static::query()->where('recorded_at', '<', now()->subDays(30));
    }

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
