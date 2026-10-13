<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paid extra on top of the plan, e.g. 2 extra branches. */
class SubscriptionAddon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'float',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
