<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email or a mobile number that may not open a new restaurant (a copycat blocked from the admin). Emails are
 * kept lowercase and numbers as 01xxxxxxxxx, so "+20 100 …" typed at sign-up still matches.
 */
class BlockedSignup extends Model
{
    protected $fillable = ['email', 'phone', 'tenant_id', 'reason', 'admin_id'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'admin_id');
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone !== '' ? (normalize_phone($phone) ?? $phone) : null;
    }

    /** Is either of them blocked? */
    public static function matches(?string $email, ?string $phone): bool
    {
        $email = self::normalizeEmail($email);
        $phone = self::normalizePhone($phone);
        if ($email === null && $phone === null) {
            return false;
        }

        return static::query()->where(function ($query) use ($email, $phone) {
            if ($email !== null) {
                $query->orWhere('email', $email);
            }
            if ($phone !== null) {
                $query->orWhere('phone', $phone);
            }
        })->exists();
    }
}
