<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\GuestSignIn;
use App\Notifications\GuestAccountEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * «اعمل حساب» / «ادخل» with an email and a password, per restaurant (the same email at two restaurants is two accounts,
 * each with its own password), and «نسيت الباسورد؟» (an emailed link, RESET_MINUTES, used once; the same answer
 * whether the email is known or not). A mobile another guest here already has is never taken over at sign-up: the
 * account is made without it (pending_phone) and the guest proves it later (WhatsApp, a previous order number, or a
 * link to the email that guest already has), which folds the new account into that guest (GuestIdentity::adopt).
 */
final class PasswordSignIn
{
    public const RESET_MINUTES = 60;

    public const IP_PER_HOUR = 20;

    public const EMAIL_FAILURES = 5;

    public const LOCK_SECONDS = 900;

    /**
     * @return array{0:'ok'|'email_taken'|'blocked', 1:?Customer}
     */
    public function register(string $name, string $phone, string $email, string $password, string $ip): array
    {
        $ipKey = EmailSignIn::key('signup-ip', $ip);
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            return ['blocked', null];
        }
        RateLimiter::hit($ipKey, 3600);
        if (Customer::query()->where('email', $email)->exists()) {
            return ['email_taken', null];
        }
        $taken = Customer::query()->where('phone', $phone)->exists();
        $customer = Customer::query()->create([
            'name' => mb_substr(trim($name), 0, 100), 'email' => $email, 'password' => $password, 'source' => 'signup',
            'phone' => $taken ? null : $phone, 'pending_phone' => $taken ? $phone : null,
        ]);

        return ['ok', $customer];
    }

    /** @return array{0:'ok'|'failed'|'blocked', 1:?Customer} */
    public function login(string $email, string $password, string $ip): array
    {
        $ipKey = EmailSignIn::key('login-ip', $ip);
        $lockKey = EmailSignIn::key('login-lock', $email);
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_PER_HOUR) || Cache::has($lockKey)) {
            return ['blocked', null];
        }
        RateLimiter::hit($ipKey, 3600);
        $customer = Customer::query()->where('email', $email)->whereNotNull('password')->latest('id')->first();
        // the same work for an unknown email, so the time does not tell
        $ok = Hash::check($password, $customer?->password ?? '$2y$04$'.str_repeat('a', 53)) && $customer !== null;
        if ($ok) {
            RateLimiter::clear(EmailSignIn::key('login-fail', $email));

            return ['ok', $customer];
        }
        if (RateLimiter::hit(EmailSignIn::key('login-fail', $email), self::LOCK_SECONDS) >= self::EMAIL_FAILURES) {
            Cache::put($lockKey, true, self::LOCK_SECONDS);
            RateLimiter::clear(EmailSignIn::key('login-fail', $email));
        }

        return ['failed', null];
    }

    /** «نسيت الباسورد؟»: a reset link to a guest with this email here; 'blocked' only on this IP's own limit. */
    public function forgot(string $email, string $ip): string
    {
        $ipKey = EmailSignIn::key('reset-ip', $ip);
        if (RateLimiter::tooManyAttempts($ipKey, EmailSignIn::IP_PER_HOUR)) {
            return 'blocked';
        }
        RateLimiter::hit($ipKey, 3600);
        $emailKey = EmailSignIn::key('reset-email', $email);
        if (RateLimiter::tooManyAttempts($emailKey, EmailSignIn::EMAIL_PER_HOUR)) {
            return 'sent';
        }
        RateLimiter::hit($emailKey, 3600);
        $customer = Customer::query()->where('email', $email)->orderByRaw('email_verified_at is null')->latest('id')->first();
        if (! $customer) {
            return 'sent';
        }
        $tenant = (string) tenant()->getTenantKey();
        GuestSignIn::query()->where('tenant_id', $tenant)->where('kind', GuestSignIn::KIND_RESET)->where('customer_id', $customer->id)
            ->whereNull('used_at')->update(['used_at' => now()]);
        $token = random_token(32);
        GuestSignIn::query()->create([
            'tenant_id' => $tenant, 'kind' => GuestSignIn::KIND_RESET, 'secret_hash' => GuestSignIn::hash($token), 'email' => $email,
            'customer_id' => $customer->id, 'expires_at' => now()->addMinutes(self::RESET_MINUTES),
        ]);
        EmailSignIn::mail($email, new GuestAccountEmail(GuestAccountEmail::RESET, route('menu.account.reset', $token), null, EmailSignIn::place(), self::RESET_MINUTES));

        return 'sent';
    }

    /** The reset waiting behind this link at the current restaurant, or null (used, expired, another restaurant's). */
    public function pendingReset(string $token): ?GuestSignIn
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return GuestSignIn::query()->where('secret_hash', GuestSignIn::hash($token))->where('kind', GuestSignIn::KIND_RESET)
            ->where('tenant_id', (string) tenant()->getTenantKey())->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    /** Sets the new password once; the email is proven by the link, every other reset link of the guest ends. */
    public function reset(string $token, string $password): ?Customer
    {
        $row = $this->pendingReset($token);
        if (! $row || DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]) !== 1) {
            return null;
        }
        $customer = Customer::query()->find($row->customer_id);
        if (! $customer || $customer->email !== $row->email) {
            return null;
        }
        $customer->forceFill(['password' => $password, 'email_verified_at' => $customer->email_verified_at ?? now()])->save();

        return $customer;
    }
}
