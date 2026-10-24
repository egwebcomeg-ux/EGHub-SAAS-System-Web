<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time "sign in to the restaurant's dashboard" ticket (App\Services\SupportAccess). Platform-side, outside
 * every restaurant; only the SHA-256 of the ticket is kept.
 */
class SupportAccessTicket extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
