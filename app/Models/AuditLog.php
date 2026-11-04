<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who did what in the dashboard. */
class AuditLog extends TenantModel
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Two years of trail are kept (a backup holds the rest); `model:prune` drops what is older. */
    public const KEEP_MONTHS = 24;

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(self::KEEP_MONTHS));
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
