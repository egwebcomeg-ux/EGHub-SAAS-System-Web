<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One printed corporate voucher. The code is 9 random characters from an alphabet without look-alikes plus a check
 * character (weighted sum mod 31, a prime: any single wrong character and any swap of two neighbours is caught), so a
 * typo is refused before any lookup. Codes are unique per restaurant and only ever looked up in the current one.
 * While customer_id is empty the paper works for whoever holds it; once linked it works only with that guest's mobile.
 */
class CorporateVoucher extends TenantModel
{
    /** 31 letters and digits that can't be confused (no 0/O, 1/I/L). 31 is prime, which makes the check character strong. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 10;

    /** status => [arabic, english] */
    public const STATUSES = [
        'active' => ['صالح', 'Active'],
        'used' => ['اتصرف', 'Used'],
        'void' => ['ملغي', 'Void'],
        'expired' => ['منتهي', 'Expired'],
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float', 'balance' => 'float', 'expires_on' => 'date', 'linked_at' => 'datetime', 'pending_at' => 'datetime',
            'printed_at' => 'datetime', 'print_count' => 'integer',
        ];
    }

    public static function newCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $body = '';
        for ($i = 0; $i < self::CODE_LENGTH - 1; $i++) {
            $body .= self::ALPHABET[random_int(0, $max)];
        }

        return $body.self::checkCharacter($body);
    }

    public static function checkCharacter(string $body): string
    {
        $sum = 0;
        foreach (str_split($body) as $i => $c) {
            $sum += ($i + 1) * strpos(self::ALPHABET, $c);
        }

        return self::ALPHABET[$sum % strlen(self::ALPHABET)];
    }

    /** Typed or scanned: case, spaces and dashes don't matter, and a scanned QR link gives its last part. */
    public static function normalizeCode(string $code): string
    {
        $code = trim($code);
        if (str_contains($code, '/')) {
            $code = (string) preg_replace('/[?#].*$/', '', $code);
            $code = basename(rtrim($code, '/'));
        }

        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** Well formed: the right length, only alphabet characters, and the check character matches. */
    public static function isValidCode(string $code): bool
    {
        if (strlen($code) !== self::CODE_LENGTH || strspn($code, self::ALPHABET) !== self::CODE_LENGTH) {
            return false;
        }

        return self::checkCharacter(substr($code, 0, -1)) === substr($code, -1);
    }

    /** ABCDE-FGHJK for print and reading aloud. */
    public function formattedCode(): string
    {
        return implode('-', str_split($this->code, 5));
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(CorporateContract::class, 'corporate_contract_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CorporateVoucherRedemption::class);
    }

    /** Past its last day (the expiry date itself is still good). */
    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->copy()->endOfDay()->isPast();
    }

    public function statusKey(): string
    {
        return $this->status === 'active' && $this->isExpired() ? 'expired' : $this->status;
    }

    public function statusLabel(): string
    {
        return CorporateContract::pick(self::STATUSES, $this->statusKey());
    }

    /** 010*****678: shown on the public page and to the cashier, never the full number of someone else. */
    public static function maskPhone(?string $phone): string
    {
        return $phone ? substr($phone, 0, 3).'*****'.substr($phone, -3) : '';
    }

    public function publicUrl(): string
    {
        return tenant()->url('voucher/'.$this->code);
    }
}
