<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier's purchase invoice for one branch. A draft can be edited or deleted; posting receives its lines into the
 * stock (App\Services\Inventory\StockService::postPurchase). A posted purchase is only ever cancelled by a manager.
 */
class Purchase extends TenantModel
{
    /** status => [arabic, english] */
    public const STATUSES = [
        'draft' => ['مسودة', 'Draft'],
        'posted' => ['اتسجّلت في المخزن', 'Posted'],
        'cancelled' => ['ملغية', 'Cancelled'],
    ];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'total' => 'float', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
