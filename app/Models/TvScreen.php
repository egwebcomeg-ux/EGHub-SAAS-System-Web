<?php

namespace App\Models;

use App\Models\Concerns\HasScreenHours;
use App\Models\Concerns\TenantModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A TV screen of the restaurant: its playlist (TvSlide), how it looks (orientation, colours, language, ticker), its
 * own hours, and the device paired to it. A device opens eghub.app/screen, shows a code, and once the owner types the
 * code it keeps a long random token whose SHA-256 is device_token_hash (one device per screen; pairing again or
 * unpairing replaces it). The older secret link /tv/{token} still opens the same playlist.
 * A screen without slides shows its menu as before (category_ids, layout, columns, rotate_seconds, show_images).
 */
class TvScreen extends TenantModel
{
    use HasScreenHours;

    public const LAYOUTS = ['all', 'rotate'];

    public const THEMES = ['dark', 'light'];

    public const ORIENTATIONS = ['landscape', 'portrait'];

    public const LANGUAGES = ['', 'ar', 'en', 'both'];

    /** A device that checked in within this many minutes counts as on. */
    public const ONLINE_MINUTES = 3;

    protected $hidden = ['device_token_hash'];

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'show_images' => 'boolean', 'is_active' => 'boolean', 'days' => 'array',
            'device_info' => 'array', 'paired_at' => 'datetime', 'last_seen_at' => 'datetime', 'reload_requested_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function slides(): HasMany
    {
        return $this->hasMany(TvSlide::class)->orderBy('sort_order')->orderBy('id');
    }

    public function url(): string
    {
        return tenant()->url('tv/'.$this->token);
    }

    public static function hashDeviceToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isPaired(): bool
    {
        return $this->device_token_hash !== null;
    }

    public function isOnline(?CarbonInterface $now = null): bool
    {
        return $this->isPaired() && $this->last_seen_at && $this->last_seen_at->greaterThan(($now ?? now())->copy()->subMinutes(self::ONLINE_MINUTES));
    }

    /** paired_online | paired_offline | waiting (paired, never seen) | unpaired */
    public function status(): string
    {
        if (! $this->isPaired()) {
            return 'unpaired';
        }
        if (! $this->last_seen_at) {
            return 'waiting';
        }

        return $this->isOnline() ? 'online' : 'offline';
    }

    /** A new device token for this screen (the previous device, if any, is unpaired). Returns the plain token once. */
    public function issueDeviceToken(): string
    {
        $token = random_token(32);
        $this->forceFill(['device_token_hash' => self::hashDeviceToken($token), 'paired_at' => now(), 'last_seen_at' => null, 'device_info' => null])->save();

        return $token;
    }

    public function unpair(): void
    {
        $this->forceFill(['device_token_hash' => null, 'paired_at' => null, 'last_seen_at' => null, 'device_info' => null])->save();
    }

    /**
     * Writes device columns without touching updated_at: the screen's content fingerprint reads updated_at, and a
     * heartbeat every minute must not make the screen reload its playlist.
     *
     * @param  array<string, mixed>  $values
     */
    public function writeQuietly(array $values): void
    {
        static::query()->whereKey($this->id)->toBase()->update(array_map(
            fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $values
        ));
        $this->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }
}
