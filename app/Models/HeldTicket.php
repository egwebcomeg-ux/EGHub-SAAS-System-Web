<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A POS ticket parked for later ("تعليق"): any terminal of the branch can resume it. */
class HeldTicket extends TenantModel
{
    protected function casts(): array
    {
        return ['cart' => 'array', 'total' => 'float', 'items_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}
