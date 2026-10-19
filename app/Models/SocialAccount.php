<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google or Facebook account linked to a restaurant user. Central (no tenant scope), like the user lookup at
 * login: it is read before any restaurant is known. `email` is what the provider reported when it was linked,
 * kept for support only; the user's own email never changes from it.
 */
class SocialAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
