<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Platform invoice to a restaurant (subscription, upgrade, add-on). */
class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'meta' => 'array',
            'subtotal' => 'float',
            'discount' => 'float',
            'total' => 'float',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
