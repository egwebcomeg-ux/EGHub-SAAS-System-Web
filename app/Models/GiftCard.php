<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prepaid gift card sold at the POS. The code (12 characters, no look-alike letters) is printed with a QR to
 * /gift/{code} on the restaurant's menu, where the recipient adds it to their wallet, once.
 */
class GiftCard extends TenantModel
{
    /** Letters and digits that can't be confused when read aloud or typed (no 0/O, 1/I/L). */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 12;

    public const VALID_MONTHS = 12;

    /** status => [arabic, english] */
    public const STATUSES = [
        'active' => ['صالح', 'Active'],
        'redeemed' => ['اتضاف للرصيد', 'Redeemed'],
        'expired' => ['منتهي', 'Expired'],
        'void' => ['ملغي', 'Void'],
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'balance' => 'float', 'expires_at' => 'datetime', 'redeemed_at' => 'datetime'];
    }

    public static function newCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** Typed codes: case, spaces and dashes don't matter. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** ABCD-EFGH-JKMN for print and reading aloud. */
    public function formattedCode(): string
    {
        return implode('-', str_split($this->code, 4));
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || ($this->status === 'active' && $this->expires_at !== null && $this->expires_at->isPast());
    }

    public function statusKey(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    public function statusLabel(): string
    {
        $l = self::STATUSES[$this->statusKey()] ?? [$this->status, $this->status];

        return app()->getLocale() === 'en' ? $l[1] : $l[0];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_customer_id');
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'redeemed_by_customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }
}
