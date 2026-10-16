<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Two-factor authentication columns shared by restaurant staff (users) and platform admins. The TOTP secret and the
 * hashed recovery codes are encrypted at rest; a secret without confirmed_at is a setup that was never finished.
 *
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 */
trait HasTwoFactor
{
    public function initializeHasTwoFactor(): void
    {
        $this->casts['two_factor_secret'] = 'encrypted';
        $this->casts['two_factor_recovery_codes'] = 'encrypted:array';
        $this->casts['two_factor_confirmed_at'] = 'datetime';
        $this->hidden[] = 'two_factor_secret';
        $this->hidden[] = 'two_factor_recovery_codes';
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** A new setup: fresh secret, nothing confirmed yet. An already enabled setup is left untouched. */
    public function startTwoFactorSetup(string $secret): void
    {
        if ($this->hasTwoFactorEnabled()) {
            return;
        }
        $this->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
    }

    /**
     * Marks the setup confirmed and issues the recovery codes; the plain codes are returned once and only their
     * hashes are kept.
     *
     * @return list<string>
     */
    public function confirmTwoFactor(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }
        $this->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn (string $c) => hash('sha256', $c), $codes),
        ])->save();

        return $codes;
    }

    /** Consumes a recovery code (each one works once). */
    public function useTwoFactorRecoveryCode(string $code): bool
    {
        $hash = hash('sha256', strtolower(trim($code)));
        $codes = $this->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function twoFactorRecoveryCodesLeft(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    /** Changes whenever 2FA is set up again or reset, so devices remembered before stop being trusted. */
    public function twoFactorFingerprint(): string
    {
        return hash('sha256', (string) $this->two_factor_secret.'|'.$this->two_factor_confirmed_at?->timestamp);
    }
}
