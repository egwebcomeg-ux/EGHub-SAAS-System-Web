<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock count (جرد) of one branch: a draft staff fill in and resume, then posted by a manager through
 * App\Services\Inventory\StockService::postCount, after which it is read-only.
 */
class StockCount extends TenantModel
{
    /** status => [arabic, english] */
    public const STATUSES = [
        'draft' => ['مسودة', 'Draft'],
        'posted' => ['اترحّل', 'Posted'],
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->status] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }
}
