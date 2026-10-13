<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A table in a branch. Its QR opens egmenu.app/{tenant}/t/{qr_token}; the token is random so tables can't be guessed. */
class DiningTable extends TenantModel
{
    protected static function booted(): void
    {
        static::creating(function (self $table) {
            $table->qr_token ??= random_token(10);
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function menuUrl(): string
    {
        return tenant()->url('t/' . $this->qr_token);
    }
}
