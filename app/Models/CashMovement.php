<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash put into or taken out of the drawer during a shift, with the reason. */
class CashMovement extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'float', 'created_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
