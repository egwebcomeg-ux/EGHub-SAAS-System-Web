<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sales person's Android phone, linked from «المكالمات»: only the hash of its token is kept. */
class CallDevice extends Model
{
    protected $table = 'crm_call_devices';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }
}
