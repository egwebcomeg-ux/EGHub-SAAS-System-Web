<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\GuestSignIn;
use App\Services\Settings;
use App\Services\WhatsApp\InteractiveGateway;
use App\Services\WhatsApp\PhoneVerification;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

use function Illuminate\Support\defer;

/**
 * «ادخل بواتساب»: a reverse code, free for EgHub. The guest taps the button; the menu host creates a pending sign-in
 * with a random 6-digit code (10 minutes, limited per IP and per restaurant) and opens wa.me/<EgHub number> with
 * «كود دخولي على <المحل> هو 482913» ready to send. The platform's signed WhatsApp webhook receives the message and
 * records the SENDER's mobile (Meta's `from`, never anything typed in the browser) on the pending code. The page,
 * which polls status() with its own HttpOnly cookie, then signs in as that mobile's guest at THAT restaurant (found
 * or created there only). The browser secret is kept hashed; the code only matches one pending, unexpired row.
 */
final class WhatsAppSignIn
{
    public const CODE_LENGTH = 6;

    public const MINUTES = 10;

    /** Codes one IP may ask for at one restaurant per 10 minutes. */
    public const IP_STARTS = 5;

    /** Codes one restaurant hands out per 10 minutes, all guests together. */
    public const TENANT_STARTS = 120;

    /** Messages carrying a code one mobile may send per hour, all restaurants together; wrong codes count too. */
    public const PHONE_TRIES = 6;

    /**
     * Offered only with the platform's own WhatsApp Cloud API number in WHATSAPP_NUMBER (not the support fallback,
     * which may be a person's phone) and an inbound webhook whose payloads can be verified.
     */
    public static function available(): bool
    {
        return (bool) config('whatsapp.guest_sign_in')
            && trim((string) config('whatsapp.number')) !== ''
            && PhoneVerification::number() !== null
            && (filled(config('whatsapp.app_secret')) || filled(config('whatsapp.webhook.secret')));
    }

    /** The menu host's cookie holding this browser's pending code (per restaurant). */
    public static function cookieName(): string
    {
        return 'wa_login_'.tenant()->getTenantKey();
    }

    /**
     * A new pending code for this browser at the current restaurant, or null when the limits are reached.
     *
     * @return array{secret:string, code:string, url:string, message:string, expires_at:int}|null
     */
    public function start(Request $request): ?array
    {
        $tenantId = (string) tenant()->getTenantKey();
        $ipKey = 'guest-wa-start:ip:'.$tenantId.':'.hash('sha256', (string) $request->ip());
        $tenantKey = 'guest-wa-start:tenant:'.$tenantId;
        if (RateLimiter::tooManyAttempts($ipKey, self::IP_STARTS) || RateLimiter::tooManyAttempts($tenantKey, self::TENANT_STARTS)) {
            return null;
        }
        RateLimiter::hit($ipKey, 600);
        RateLimiter::hit($tenantKey, 600);

        $secret = random_token(32);
        $code = $this->freshCode();
        $expires = now()->addMinutes(self::MINUTES);
        GuestSignIn::query()->create([
            'tenant_id' => $tenantId, 'kind' => GuestSignIn::KIND_WHATSAPP, 'secret_hash' => GuestSignIn::hash($secret),
            'code' => $code, 'expires_at' => $expires,
        ]);
        $message = __('My sign-in code at :place is :code', ['place' => self::brand(), 'code' => $code]);

        return [
            'secret' => $secret, 'code' => $code, 'message' => $message, 'expires_at' => $expires->getTimestamp(),
            'url' => 'https://wa.me/'.PhoneVerification::number().'?text='.rawurlencode($message),
        ];
    }

    /** HttpOnly, SameSite=Lax, for the life of the code. */
    public static function cookie(string $secret): Cookie
    {
        return cookie(self::cookieName(), $secret, self::MINUTES, null, null, self::secure(), true, false, 'lax');
    }

    public static function forgetCookie(): Cookie
    {
        return cookie(self::cookieName(), '', -2628000, null, null, self::secure(), true, false, 'lax');
    }

    /**
     * Where this browser's code stands at the current restaurant: 'ok' with the guest (signed in once, the code is
     * then used up), 'pending', 'expired', or 'missing' (no code, someone else's, another restaurant's, used).
     *
     * @return array{0:'ok'|'pending'|'expired'|'missing', 1:?Customer}
     */
    public function status(Request $request): array
    {
        $secret = $request->cookie(self::cookieName());
        if (! is_string($secret) || preg_match('/^[a-f0-9]{64}$/', $secret) !== 1) {
            return ['missing', null];
        }
        $row = GuestSignIn::query()->where('secret_hash', GuestSignIn::hash($secret))->where('kind', GuestSignIn::KIND_WHATSAPP)
            ->where('tenant_id', (string) tenant()->getTenantKey())->whereNull('used_at')->first();
        if (! $row) {
            return ['missing', null];
        }
        if ($row->verified_at === null) {
            return [$row->expires_at->isPast() ? 'expired' : 'pending', null];
        }
        $claimed = DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
        $phone = normalize_phone((string) $row->phone);
        if ($claimed !== 1 || $phone === null) {
            return ['missing', null];
        }
        // the mobile WhatsApp vouched for: its guest here, or a new guest here
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], ['source' => 'menu']);
        $row->forceFill(['customer_id' => $customer->id])->save();

        return ['ok', $customer];
    }

    /**
     * One inbound text on the EgHub number (signed webhook): the pending, unexpired code in it is confirmed for the
     * sender's mobile. Returns the confirmed sign-in, or null when the text carries none.
     *
     * Only one code per message is tried, and every try counts against the sender's hourly limit, so a message
     * stuffed with numbers cannot guess another guest's code.
     */
    public function confirm(string $from, string $text): ?GuestSignIn
    {
        $phone = normalize_phone($from);
        $code = self::extractCode($text);
        if ($phone === null || $code === null) {
            return null;
        }
        $limitKey = 'guest-wa-confirm:'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($limitKey, self::PHONE_TRIES)) {
            return null;
        }
        RateLimiter::hit($limitKey, 3600);
        $row = GuestSignIn::query()->where('kind', GuestSignIn::KIND_WHATSAPP)->where('code', $code)
            ->whereNull('verified_at')->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $row) {
            return null;
        }
        $claimed = DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('verified_at')
            ->update(['phone' => $phone, 'verified_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }
        Log::info('guest whatsapp sign-in confirmed', ['tenant' => $row->tenant_id, 'sign_in' => $row->id]);
        $this->reply($phone);

        return $row->refresh();
    }

    /**
     * The code in an inbound text: its last 6-digit number, where the prepared message puts it (Arabic digits
     * accepted, extra words and a place name with digits ignored).
     */
    public static function extractCode(string $text): ?string
    {
        preg_match_all('/(?<!\d)(\d{'.self::CODE_LENGTH.'})(?!\d)/', latin_digits($text), $m);

        return $m[1] === [] ? null : end($m[1]);
    }

    /** A code no other pending sign-in holds right now. */
    private function freshCode(): string
    {
        do {
            $code = (string) random_int(10 ** (self::CODE_LENGTH - 1), 10 ** self::CODE_LENGTH - 1);
        } while (GuestSignIn::query()->where('kind', GuestSignIn::KIND_WHATSAPP)->where('code', $code)
            ->whereNull('verified_at')->where('expires_at', '>', now())->exists());

        return $code;
    }

    /** «تمام، ارجع للمنيو»: free text inside the 24-hour window the guest just opened, when the provider can send it. */
    private function reply(string $phone): void
    {
        $gateway = app(WhatsAppGateway::class);
        if (! $gateway instanceof InteractiveGateway) {
            return;
        }
        $text = __('Done, you are signed in. Go back to the menu.');
        defer(function () use ($gateway, $phone, $text) {
            try {
                $gateway->reply(whatsapp_number($phone), $text);
            } catch (Throwable $e) {
                Log::warning('guest whatsapp sign-in reply failed', ['error' => $e->getMessage()]);
            }
        });
    }

    private static function brand(): string
    {
        $settings = Settings::all();

        return trim((string) tr($settings['brand_name'] ?? '', $settings['brand_name_en'] ?? '')) ?: (string) tenant()->name;
    }

    private static function secure(): bool
    {
        return (bool) (config('session.secure') ?? (request()->isSecure() || app()->isProduction()));
    }
}
