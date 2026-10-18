<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A table from its first dine-in order until the cashier closes it: every order of the table (QR or POS) joins its
 * open session, the cashier takes one bill for all of them and closing frees the table. `total` is cached
 * (orders that are not cancelled) for the tables grid.
 */
class TableSession extends TenantModel
{
    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'total' => 'float'];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** @param  Builder<TableSession>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /** Re-reads the cached total from the session's orders that are not cancelled. */
    public function refreshTotal(): void
    {
        $this->update(['total' => round((float) $this->orders()->where('status', '!=', 'cancelled')->sum('total'), 2)]);
    }
}
