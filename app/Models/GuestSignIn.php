<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One short-lived, single-use step of a guest's sign-in that crosses requests or hosts (platform side, never part of
 * a restaurant's backup): the Google hand-off ticket (kind google) and the WhatsApp code waiting for the guest's
 * message (kind whatsapp), the emailed sign-in link and code (email), the password reset link (reset) and the
 * account-claim link of «ادعي عملاءك» (claim, the only long-lived kind). Every lookup names the restaurant it belongs to; only hashes of browser secrets are kept.
 */
class GuestSignIn extends Model
{
    public const KIND_GOOGLE = 'google';

    public const KIND_WHATSAPP = 'whatsapp';

    /** a link + 6-digit code emailed to the guest (App\Services\Customers\SignIn\EmailSignIn) */
    public const KIND_EMAIL = 'email';

    /** «نسيت الباسورد؟»: the emailed reset link (App\Services\Customers\SignIn\PasswordSignIn) */
    public const KIND_RESET = 'reset';

    /** «ادعي عملاءك»: a personal link that lets an existing guest claim their account (App\Services\Customers\ClaimLinks) */
    public const KIND_CLAIM = 'claim';

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
