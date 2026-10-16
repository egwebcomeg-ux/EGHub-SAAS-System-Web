<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Support\Facades\Log;

/**
 * Confirms an owner's mobile without codes to type: the dashboard shows a wa.me link that opens WhatsApp with
 * "EGMENU <code>" ready to send to the platform number; the inbound webhook matches sender + code and stamps
 * users.phone_verified_at. Nothing here ever blocks the dashboard: with no platform number configured the step
 * is simply not offered.
 */
final class PhoneVerification
{
    /** Letters and digits that cannot be confused with each other (no I/1, O/0). */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    public const PREFIX = 'EGMENU';

    /** The platform WhatsApp number as wa.me digits (2010xxxxxxxx), or null when none is configured. */
    public static function number(): ?string
    {
        $raw = (string) (config('whatsapp.number') ?: PlatformSetting::get('support_whatsapp', ''));
        if (trim($raw) === '') {
            return null;
        }
        if ($local = normalize_phone($raw)) {
            return whatsapp_number($local);
        }
        $digits = ltrim(preg_replace('/\D+/', '', $raw) ?? '', '0');

        return strlen($digits) >= 8 ? $digits : null;
    }

    /** Where the number comes from: env (WHATSAPP_NUMBER), setting (support_whatsapp) or none. */
    public static function numberSource(): string
    {
        if (trim((string) config('whatsapp.number')) !== '') {
            return 'env';
        }

        return trim((string) PlatformSetting::get('support_whatsapp', '')) !== '' ? 'setting' : 'none';
    }

    public static function available(): bool
    {
        return self::number() !== null;
    }

    /** Can this user be asked to confirm right now? Has a mobile, is not confirmed, and the platform has a number. */
    public static function pendingFor(?User $user): bool
    {
        return $user !== null && $user->phone !== null && ! $user->hasVerifiedPhone() && self::available();
    }

    public static function code(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while ($code === self::PREFIX);

        return $code;
    }

    /** Gives the user a fresh code (after signup or a number change) and returns it. */
    public static function issue(User $user): string
    {
        $code = self::code();
        $user->forceFill(['phone_verified_at' => null, 'phone_verification_code' => $code])->save();

        return $code;
    }

    /** The prepared text the owner sends: "EGMENU AB2CD3". */
    public static function message(string $code): string
    {
        return self::PREFIX.' '.strtoupper($code);
    }

    /** wa.me link that opens WhatsApp with the message ready, or null when the user cannot be asked. */
    public static function link(User $user): ?string
    {
        $number = self::number();
        if ($number === null || ! $user->phone_verification_code) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode(self::message($user->phone_verification_code));
    }

    /**
     * Every candidate code in an inbound text (case-insensitive, Arabic digits accepted, extra words ignored).
     *
     * @return list<string>
     */
    public static function extractCodes(string $text): array
    {
        $latin = strtoupper(strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $pattern = '/(?<![A-Z0-9])(['.self::ALPHABET.']{'.self::LENGTH.'})(?![A-Z0-9])/';
        preg_match_all($pattern, $latin, $m);

        return array_values(array_unique(array_filter($m[1], fn (string $c) => $c !== self::PREFIX)));
    }

    /**
     * Handles one inbound text: the sender must be the Egyptian mobile on the account AND the text must carry
     * that account's pending code; anything else is ignored. Returns the user that was just confirmed.
     */
    public function confirm(string $from, string $text): ?User
    {
        $phone = normalize_phone($from);
        $codes = self::extractCodes($text);
        if ($phone === null || $codes === []) {
            return null;
        }
        $user = User::query()->withoutGlobalScopes()->where('phone', $phone)->whereNull('phone_verified_at')
            ->whereNotNull('phone_verification_code')->first();
        if (! $user || ! in_array(strtoupper((string) $user->phone_verification_code), $codes, true)) {
            Log::info('whatsapp phone confirmation ignored', ['from' => $phone, 'known_user' => $user !== null]);

            return null;
        }

        $user->markPhoneVerified();
        Log::info('whatsapp phone confirmed', ['user' => $user->id, 'tenant' => $user->tenant_id]);
        $this->audit($user);

        return $user;
    }

    /** A line in the restaurant's audit log, written from the central (webhook) context. */
    private function audit(User $user): void
    {
        $tenant = Tenant::query()->find($user->tenant_id);
        if (! $tenant || tenancy()->initialized) {
            return;
        }
        tenancy()->initialize($tenant);
        try {
            Audit::record('phone.verified', (string) $user->phone, ['user_id' => $user->id, 'via' => 'whatsapp']);
        } finally {
            tenancy()->end();
            Settings::flush();
        }
    }
}
