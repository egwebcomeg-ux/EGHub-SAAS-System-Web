<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sales person's Android phone, linked from «المكالمات»: only the hash of its token is kept. */
class CallDevice extends Model
{
    protected $table = 'crm_call_devices';

    /** A phone silent for this long must be linked again (App\Http\Middleware\AuthenticateCallDevice). */
    public const IDLE_DAYS = 90;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'push_token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** The phones of this admin that can place a call sent from the computer (they gave a push token lately). */
    public function scopeDialable(Builder $query, int $adminId): Builder
    {
        return $query->where('admin_id', $adminId)->whereNotNull('push_token')
            ->where('last_seen_at', '>=', now()->subDays(self::IDLE_DAYS));
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }
}
