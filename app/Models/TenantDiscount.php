<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A % off a restaurant's next subscription invoice(s), given by EgWeb on a sales call (admin › متابعة العملاء) within
 * the caller's limit (PlatformAdmin::discountLimit()). Billing::quote() adds it as a line of every subscription
 * invoice issued while it is usable (not revoked, not expired, invoices left); each paid invoice that carried it uses
 * one. One usable discount per restaurant: a new one replaces the previous. Audited in the platform audit log.
 */
class TenantDiscount extends Model
{
    /** The most any discount may be, the platform owner's included. */
    public const CAP = 50;

    /** The most paid invoices one discount may cover. */
    public const MAX_INVOICES = 12;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'percent' => 'float',
            'invoices' => 'integer',
            'invoices_used' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'granted_by');
    }

    /** @param Builder<TenantDiscount> $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now())->whereColumn('invoices_used', '<', 'invoices');
    }

    /** The discount the restaurant's next subscription invoice gets, if any. */
    public static function usableFor(Tenant|string $tenant): ?self
    {
        return static::query()->usable()->where('tenant_id', $tenant instanceof Tenant ? $tenant->id : $tenant)->latest('id')->first();
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture() && $this->invoices_used < $this->invoices;
    }

    public function invoicesLeft(): int
    {
        return max(0, $this->invoices - $this->invoices_used);
    }

    /** "Expired", "Used", "Cancelled" or "Active" for the admin pages. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->revoked_at !== null => __('Cancelled'),
            $this->invoices_used >= $this->invoices => __('Used'),
            $this->expires_at->isPast() => __('Expired'),
            default => __('Active'),
        };
    }
}
