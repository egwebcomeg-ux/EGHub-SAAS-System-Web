<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «رسالة عاجلة»: a line typed in the dashboard (from a phone) that the chosen screens show on top of their playlist,
 * as a band at the top or the whole screen, until ends_at or until someone clears it. The player asks for it every
 * App\Services\Screens\ScreenAlerts::POLL_SECONDS.
 */
class TvAlert extends TenantModel
{
    public const STYLES = ['banner', 'full'];

    /** minutes the owner can pick; 0 = until cleared */
    public const DURATIONS = [5, 15, 30, 60, 120, 0];

    protected function casts(): array
    {
        return ['screen_ids' => 'array', 'ends_at' => 'datetime', 'cleared_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<TvAlert> $query */
    public function scopeActive(Builder $query, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        $query->whereNull('cleared_at')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function showsOn(int $screenId): bool
    {
        return in_array($screenId, array_map('intval', $this->screen_ids ?? []), true);
    }
}
