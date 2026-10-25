<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A 6-digit code a device shows on eghub.app/screen until the owner types it in the dashboard (platform row: the
 * device belongs to no restaurant yet). Only an HMAC of the code is stored; the device that asked for it holds a
 * random secret (only its hash here) and is the only one that can collect the device token once the code is claimed.
 * A code lives 10 minutes and is claimed once (App\Services\Screens\ScreenPairing).
 */
class TvPairing extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['device_token'];

    protected function casts(): array
    {
        return ['device_token' => 'encrypted', 'expires_at' => 'datetime', 'claimed_at' => 'datetime', 'collected_at' => 'datetime'];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(TvScreen::class, 'tv_screen_id')->withoutGlobalScopes();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
