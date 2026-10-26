<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One short-lived, single-use step of a guest's sign-in that crosses requests or hosts (platform side, never part of
 * a restaurant's backup): the Google hand-off ticket (kind google) and the WhatsApp code waiting for the guest's
 * message (kind whatsapp). Every lookup names the restaurant it belongs to; only hashes of browser secrets are kept.
 */
class GuestSignIn extends Model
{
    public const KIND_GOOGLE = 'google';

    public const KIND_WHATSAPP = 'whatsapp';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** sha256 of a browser secret, the only form it is stored in. */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
