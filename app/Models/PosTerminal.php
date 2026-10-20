<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named till of a branch ("كاشير 1", code K1). One device claims it and keeps a token (only its hash is kept
 * here); the terminal shows on its shifts and orders, and its code numbers the sales made offline (OFF-K1-3).
 */
class PosTerminal extends TenantModel
{
    protected $hidden = ['device_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'claimed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'offline_seq' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'terminal_id');
    }

    public function openShift(): ?Shift
    {
        return $this->shifts()->whereNull('closed_at')->latest('id')->first();
    }

    public function isClaimed(): bool
    {
        return $this->device_token !== null;
    }

    /** Whether $token is the one this terminal's device holds. */
    public function holds(?string $token): bool
    {
        return $this->device_token !== null && is_string($token) && $token !== '' && hash_equals($this->device_token, hash('sha256', $token));
    }

    /** "كاشير 1 · K1" */
    public function label(): string
    {
        return $this->name.' · '.$this->code;
    }
}
