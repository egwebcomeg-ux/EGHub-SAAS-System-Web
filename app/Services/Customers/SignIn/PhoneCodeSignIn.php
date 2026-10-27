<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\GuestSignIn;
use App\Services\Settings;
use App\Services\Sms\Sms;
use App\Services\WhatsApp\OwnNumber;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * «ادخل برقم موبايلك»: the guest types their mobile and gets a 6-digit code (MINUTES, used once, TRIES wrong codes),
 * then this phone opens THAT restaurant's guest with that mobile (an imported or ordering guest keeps their addresses
 * and history), or a new guest. The code goes out the best way this restaurant has, each only when available:
 *  1. whatsapp  the Authentication template from the restaurant's OWN connected WhatsApp number (Meta bills the
 *               restaurant's account, like every message from its own number: no EgHub credit);
 *  2. sms       the configured SMS provider (config/sms.php);
 * and when neither can send, or the guest asks for another way: 3. reverse  the guest sends a code to the EgHub
 * number (WhatsAppSignIn, free); 4. email  a sign-in link to the email that guest already has here. A password is
 * never sent anywhere. Every code sent is kept with its channel and estimated cost (usage()).
 */
final class PhoneCodeSignIn
{
    public const MINUTES = 5;

    public const TRIES = 5;

    public const RESEND_SECONDS = 60;

    public const PHONE_PER_HOUR = 5;

    public const IP_PER_HOUR = 15;

    public const TENANT_PER_HOUR = 300;

    /** The ways that can send a code to a mobile now, best first. */
    public static function senders(): array
    {
        return array_values(array_filter([
            OwnNumber::sender(OwnNumber::AUTH_TEMPLATE, WhatsApp::locale()) !== null ? 'whatsapp' : null,
            Sms::enabled() ? 'sms' : null,
        ]));
    }

    /**
     * The phone box always shows first: with no WhatsApp number or SMS yet the code goes to the email the guest has
     * here, and without one the box says so and points to the other ways (start()).
     */
    public static function available(): bool
    {
        return true;
    }

    /**
     * The other ways for this mobile: the senders not used, the reverse WhatsApp code, an email the guest has here.
     *
     * @return list<array{channel:string, label:string}>
     */
    public static function alternatives(string $phone, ?string $used = null): array
    {
        $out = [];
        foreach (self::senders() as $channel) {
            if ($channel !== $used) {
                $out[] = ['channel' => $channel, 'label' => self::label($channel)];
            }
        }
        if (WhatsAppSignIn::available()) {
            $out[] = ['channel' => 'reverse', 'label' => self::label('reverse')];
        }
        $email = self::emailOf($phone);
        if ($email !== null && $used !== 'email') {
            $out[] = ['channel' => 'email', 'label' => __('A code to my email (:email)', ['email' => self::maskEmail($email)])];
        }
        // always there: the email ways and the mobile + a previous order number, further down the sheet
        $out[] = ['channel' => 'email_sign_in', 'label' => __('Sign in with my email instead')];
        $out[] = ['channel' => 'order', 'label' => __('My mobile + a previous order number')];

        return $out;
    }

    public static function label(string $channel): string
    {
        return match ($channel) {
            'whatsapp' => __('A code on WhatsApp'),
            'sms' => __('A code by SMS'),
            'reverse' => __('I send a code on WhatsApp'),
            'email' => __('A code to my email'),
            default => $channel,
        };
    }

    /**
     * Sends a code to $phone by $channel (or the best sender). 'sent' with the channel; 'wait' (resend too soon, with
     * the seconds left); 'blocked' (limits); 'none' (no sender here: the page offers the other ways); 'failed' (the
     * provider refused: the page offers the other ways).
     *
     * @return array{status:string, channel:?string, wait?:int}
     */
    public function start(string $phone, ?string $channel, string $ip): array
    {
        $senders = self::senders();
        // no WhatsApp number or SMS here yet (or the guest asked for it): the code goes to the email this mobile's guest
        // already has; without one the page says so and offers the other ways
        $email = self::emailOf($phone);
        if ($email !== null) {
            $senders[] = 'email';
        }
        $channel = in_array($channel, $senders, true) ? $channel : ($senders[0] ?? null);
        if ($channel === null) {
            return ['status' => 'none', 'channel' => null];
        }
        $ipKey = EmailSignIn::key('otp-ip', $ip);
        $phoneKey = EmailSignIn::key('otp-phone', $phone);
        $tenantKey = EmailSignIn::key('otp-tenant', 'all');
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_PER_HOUR) || RateLimiter::tooManyAttempts($phoneKey, self::PHONE_PER_HOUR)
            || RateLimiter::tooManyAttempts($tenantKey, self::TENANT_PER_HOUR)) {
            return ['status' => 'blocked', 'channel' => $channel];
        }
        $tenant = (string) tenant()->getTenantKey();
        $last = GuestSignIn::query()->where('tenant_id', $tenant)->where('kind', 'phone')->where('phone', $phone)->latest('id')->first();
        if ($last && $last->created_at->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            return ['status' => 'wait', 'channel' => $last->channel, 'wait' => self::RESEND_SECONDS - (int) $last->created_at->diffInSeconds(now(), true)];
        }
        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($phoneKey, 3600);
        RateLimiter::hit($tenantKey, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        GuestSignIn::query()->where('tenant_id', $tenant)->where('kind', 'phone')->where('phone', $phone)->whereNull('used_at')->update(['used_at' => now()]);
        $row = GuestSignIn::query()->create([
            'tenant_id' => $tenant, 'kind' => 'phone', 'secret_hash' => GuestSignIn::hash(random_token(32)), 'phone' => $phone,
            'code_hash' => self::codeHash($phone, $code), 'expires_at' => now()->addMinutes(self::MINUTES), 'channel' => $channel,
        ]);
        try {
            $cost = $channel === 'email' ? $this->email((string) $email, $code, $ip) : $this->send($channel, $phone, $code);
        } catch (Throwable $e) {
            Log::warning('guest sign-in code not sent', ['channel' => $channel, 'error' => mb_substr($e->getMessage(), 0, 180)]);
            $row->forceFill(['used_at' => now(), 'expires_at' => now(), 'channel' => null, 'created_at' => now()->subSeconds(self::RESEND_SECONDS)])->save();

            return ['status' => 'failed', 'channel' => $channel];
        }
        $row->forceFill(['cost' => $cost])->save();

        return ['status' => 'sent', 'channel' => $channel];
    }

    /** The code typed for $phone: the guest of this restaurant with that mobile, or a new one; null when wrong. */
    public function verify(string $phone, string $code): ?Customer
    {
        $code = latin_digits(preg_replace('/\s+/', '', $code));
        $row = GuestSignIn::query()->where('tenant_id', (string) tenant()->getTenantKey())->where('kind', 'phone')->where('phone', $phone)
            ->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $row || $row->attempts >= self::TRIES || preg_match('/^\d{6}$/', $code) !== 1 || ! hash_equals((string) $row->code_hash, self::codeHash($phone, $code))) {
            $row?->increment('attempts');

            return null;
        }
        if (DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'verified_at' => now(), 'updated_at' => now()]) !== 1) {
            return null;
        }
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['source' => 'menu']);
        $row->forceFill(['customer_id' => $customer->id])->save();

        return $customer;
    }

    /**
     * The codes this restaurant sent in the last $days days, per channel, with their estimated cost.
     *
     * @return array<string, array{count:int, cost:float}>
     */
    public static function usage(int $days = 30): array
    {
        return GuestSignIn::query()->where('tenant_id', (string) tenant()->getTenantKey())->where('kind', 'phone')->whereNotNull('channel')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('channel, count(*) as n, sum(cost) as total')->groupBy('channel')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->channel => ['count' => (int) $r->n, 'cost' => round((float) $r->total, 2)]])->all();
    }

    /** The email the guest with this mobile has here (sign-up, Google, imported, confirmed), never a pending one. */
    public static function emailOf(string $phone): ?string
    {
        return GuestIdentity::normalizeEmail(Customer::query()->where('phone', $phone)->value('email'));
    }

    /** The code (and a sign-in link) by email: free. */
    private function email(string $email, string $code, string $ip): float
    {
        if (app(EmailSignIn::class)->start($email, $ip, $code) !== 'sent') {
            throw new \RuntimeException('email limit');
        }

        return 0.0;
    }

    /** Sends the code; returns its estimated cost. */
    private function send(string $channel, string $phone, string $code): float
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        if ($channel === 'whatsapp') {
            $connection = OwnNumber::sender(OwnNumber::AUTH_TEMPLATE, WhatsApp::locale()) ?? throw new \RuntimeException('own number not available');
            OwnNumber::sendCode($connection, $phone, $code, WhatsApp::locale());

            return (float) config('whatsapp.auth_cost', 0);
        }
        $driver = Sms::driver() ?? throw new \RuntimeException('no SMS provider');
        $brand = trim(tr(Settings::get('brand_name'), Settings::get('brand_name_en'))) ?: (string) tenant()->name;
        $text = $locale === 'en'
            ? "Your {$brand} sign-in code: {$code}. It works for ".self::MINUTES.' minutes. Never share it.'
            : "كود دخولك على {$brand}: {$code}. شغال ".self::MINUTES.' دقايق، ما تقولهوش لحد.';
        $result = $driver->send($phone, $text);
        if (! $result['ok']) {
            throw new \RuntimeException((string) $result['error']);
        }

        return round(Sms::segments($text) * (float) config('sms.cost_per_segment'), 3);
    }

    private static function codeHash(string $phone, string $code): string
    {
        return hash_hmac('sha256', tenant()->getTenantKey().'|p|'.$phone.'|'.$code, (string) config('app.key'));
    }

    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 2).'•••@'.$domain;
    }
}
