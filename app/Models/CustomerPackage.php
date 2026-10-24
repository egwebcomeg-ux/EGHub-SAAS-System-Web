<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A package a customer bought: the uses left and the day it expires. Changed only through App\Services\Loyalty\Packages. */
class CustomerPackage extends TenantModel
{
    public const STATUSES = [
        'active' => ['سارية', 'Active'],
        'used' => ['اتستهلكت', 'Used up'],
        'expired' => ['انتهت', 'Expired'],
        'void' => ['ملغية', 'Void'],
    ];

    protected function casts(): array
    {
        return ['price_paid' => 'float', 'uses_total' => 'integer', 'uses_left' => 'integer', 'expires_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PrepaidPackage::class, 'prepaid_package_id');
    }

    public function uses(): HasMany
    {
        return $this->hasMany(PackageUse::class)->latest('id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->uses_left > 0 && $this->expires_at->isFuture();
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
