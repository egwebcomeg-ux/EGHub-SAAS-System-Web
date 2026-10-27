<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\GuestSignIn;
use App\Notifications\GuestAccountEmail;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * «ادخل بالإيميل»: a one-time link and a 6-digit code (the fallback when the email opens on another phone) sent to the
 * address the guest typed, valid MINUTES, used once. The link lands on the restaurant's own host the guest is using
 * and opens THIS restaurant's guest with that email (GuestIdentity::forProvenEmail), or a new guest. Starting answers
 * the same whether the email is known or not; limits per IP, per email and per restaurant keep it from being abused
 * (hashes only in the limiter keys). Only hashes of the link and the code are stored.
 */
final class EmailSignIn
{
    public const MINUTES = 15;

    public const IP_PER_HOUR = 10;

    public const EMAIL_PER_HOUR = 4;

    public const TENANT_PER_HOUR = 200;

    public const CODE_TRIES = 5;

    /**
     * Sends a link + code to $email, unless a limit says no. 'blocked' only when this IP asked too often (nothing
     * about the email); a limit on the email itself is silent so the answer never tells anything about it.
     *
     * $code: the code to put in the email (the phone sign-in sends its own code this way when the place has no
     * WhatsApp number or SMS yet); a new one otherwise.
     *
     * @return 'sent'|'blocked'
     */
    public function start(string $email, string $ip, ?string $code = null): string
    {
        $ipKey = self::key('ip', $ip);
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_PER_HOUR)) {
            return 'blocked';
        }
        RateLimiter::hit($ipKey, 3600);
        $emailKey = self::key('email', $email);
        $tenantKey = self::key('tenant', 'all');
        if (RateLimiter::tooManyAttempts($emailKey, self::EMAIL_PER_HOUR) || RateLimiter::tooManyAttempts($tenantKey, self::TENANT_PER_HOUR)) {
            return 'sent';
        }
        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($tenantKey, 3600);

        $token = random_token(32);
        $code ??= str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tenant = (string) tenant()->getTenantKey();
        // a new link replaces the ones before it
        GuestSignIn::query()->where('tenant_id', $tenant)->where('kind', GuestSignIn::KIND_EMAIL)->where('email', $email)
            ->whereNull('used_at')->update(['used_at' => now()]);
        GuestSignIn::query()->create([
            'tenant_id' => $tenant, 'kind' => GuestSignIn::KIND_EMAIL, 'secret_hash' => GuestSignIn::hash($token),
            'email' => $email, 'code_hash' => self::codeHash($email, $code), 'expires_at' => now()->addMinutes(self::MINUTES),
        ]);
        self::mail($email, new GuestAccountEmail(GuestAccountEmail::SIGN_IN, route('menu.account.email.link', $token), $code, self::place(), self::MINUTES));

        return 'sent';
    }

    /** The waiting sign-in of this link at the current restaurant (for the confirm page), or null. */
    public function pending(string $token): ?GuestSignIn
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return GuestSignIn::query()->where('secret_hash', GuestSignIn::hash($token))->where('kind', GuestSignIn::KIND_EMAIL)
            ->where('tenant_id', (string) tenant()->getTenantKey())->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    /** The guest of this link, once (the first use wins); null when used, expired or another restaurant's. */
    public function redeemLink(string $token): ?Customer
    {
        $row = $this->pending($token);

        return $row && $this->claim($row) ? $this->resolve((string) $row->email) : null;
    }

    /** The code typed on the page: CODE_TRIES wrong codes and the link must be asked again. */
    public function redeemCode(string $email, string $code): ?Customer
    {
        $code = latin_digits(preg_replace('/\s+/', '', $code));
        $row = GuestSignIn::query()->where('tenant_id', (string) tenant()->getTenantKey())->where('kind', GuestSignIn::KIND_EMAIL)
            ->where('email', $email)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $row || $row->attempts >= self::CODE_TRIES || preg_match('/^\d{6}$/', $code) !== 1
            || ! hash_equals((string) $row->code_hash, self::codeHash($email, $code))) {
            $row?->increment('attempts');

            return null;
        }

        return $this->claim($row) ? $this->resolve($email) : null;
    }

    /** This restaurant's guest of a proven email, or a new guest with only that email. */
    public function resolve(string $email): Customer
    {
        return GuestIdentity::forProvenEmail($email)
            ?? Customer::query()->create(['phone' => null, 'name' => '', 'email' => $email, 'email_verified_at' => now(), 'source' => 'email']);
    }

    private function claim(GuestSignIn $row): bool
    {
        return DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]) === 1;
    }

    private static function codeHash(string $email, string $code): string
    {
        return hash_hmac('sha256', tenant()->getTenantKey().'|'.$email.'|'.$code, (string) config('app.key'));
    }

    /** Limiter key of this restaurant, the value hashed. */
    public static function key(string $kind, string $value): string
    {
        return 'guest-email:'.$kind.':'.tenant()->getTenantKey().':'.hash('sha256', $value);
    }

    /** Queued, in the language the guest is reading. */
    public static function mail(string $email, GuestAccountEmail $notification): void
    {
        Notification::route('mail', $email)->notify($notification->locale(app()->getLocale()));
    }

    /**
     * The restaurant as the email shows it, read now (the queue has no restaurant context).
     *
     * @return array{name:string, logo:?string, color:string, menu:string}
     */
    public static function place(): array
    {
        $s = Settings::all();
        $name = trim(tr($s['brand_name'], $s['brand_name_en'])) ?: (string) tenant()->name;

        return [
            'name' => $name,
            'logo' => $s['logo'] !== '' ? url('/storage/'.$s['logo']) : null,
            'color' => (string) $s['color_primary'],
            'menu' => route('menu.show'),
        ];
    }
}
