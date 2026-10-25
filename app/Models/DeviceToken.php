<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The push token (FCM) of a staff member's phone in an EgHub app (restaurant, waiter). Unique by token_hash across
 * every restaurant: a phone that signs in with another account takes its token along (App\Services\Push\DeviceTokens).
 */
class DeviceToken extends TenantModel
{
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
