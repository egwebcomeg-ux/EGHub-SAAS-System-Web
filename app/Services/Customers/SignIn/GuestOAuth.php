<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\GuestSignIn;
use App\Models\Tenant;
use App\Services\Auth\SocialProviders;
use App\Services\Settings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * «الدخول بجوجل» for a restaurant's guests. Menus live on many hosts (eghub.app/{slug}, {slug}.eghub.app, custom
 * domains) but Google accepts one fixed callback, so the trip goes through the main domain:
 *
 *  1. the menu host (/me/google) keeps a random nonce in its own cookie and sends the browser to
 *     eghub.app/auth/google/start with an encrypted, 10-minute `state`: the restaurant, the address to come back to
 *     (that restaurant's own /me/google/done on the same host, checked again on arrival: never an open redirect) and
 *     the nonce's hash;
 *  2. eghub.app keeps that context in its session and lets Socialite do the OAuth round trip (scopes openid email
 *     profile only, its own state in the same session);
 *  3. the callback finds or creates the guest inside THAT restaurant only (Google subject first, then the verified
 *     email; the same Google account at two restaurants is two guests) and hands a random ticket back to the menu
 *     host: single use, two minutes, valid for that restaurant only and only in the browser holding the nonce;
 *  4. the menu host trades the ticket for its usual remembered-guest cookie (App\Services\Customers\GuestAccount).
 *
 * Kept: the Google subject, the verified email and the name; never a Google token.
 * Another provider (Apple) is one more entry in PROVIDERS with its own enabled() check and identity mapping.
 */
final class GuestOAuth
{
    /** The providers a guest can sign in with, in button order. */
    public const PROVIDERS = ['google'];

    public const STATE_MINUTES = 10;

    public const TICKET_SECONDS = 120;

    /** The menu host's cookie holding the nonce of a sign-in in progress (per restaurant). */
    public static function nonceCookie(string $tenantId): string
    {
        return 'gsi_'.$tenantId;
    }

    /** Whether the provider's button shows: its keys are in .env (the same Google client as the staff sign-in). */
    public static function enabled(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true) && SocialProviders::isEnabled($provider);
    }

    /** The fixed callback on the main domain (config services.google.guest_redirect). */
    public static function callbackUrl(string $provider): string
    {
        return (string) config("services.$provider.guest_redirect");
    }

    /** eghub.app/auth/{provider}/start?state=… for the current restaurant, coming back to $returnUrl. */
    public static function startUrl(string $provider, Tenant $tenant, string $returnUrl, string $nonce): string
    {
        $state = Crypt::encryptString(json_encode([
            'p' => $provider,
            't' => (string) $tenant->getTenantKey(),
            'r' => $returnUrl,
            'n' => GuestSignIn::hash($nonce),
            'e' => now()->addMinutes(self::STATE_MINUTES)->getTimestamp(),
        ], JSON_UNESCAPED_SLASHES));

        return platform_url(null, '/auth/'.$provider.'/start').'?state='.rawurlencode($state);
    }

    /**
     * The context of a valid `state`: not tampered with (encrypted and signed with APP_KEY), not expired, for an
     * active restaurant, coming back to an address of that restaurant. Null otherwise.
     *
     * @return array{provider:string, tenant:string, return:string, nonce_hash:string}|null
     */
    public static function readState(string $provider, string $state): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($data) || ($data['p'] ?? null) !== $provider || ! is_string($data['t'] ?? null) || ! is_string($data['r'] ?? null)
            || ! is_string($data['n'] ?? null) || strlen($data['n']) !== 64 || (int) ($data['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }
        $tenant = Tenant::query()->find($data['t']);
        if (! $tenant || $tenant->isSuspended() || ! self::returnUrlBelongs($tenant, $data['r'])) {
            return null;
        }

        return ['provider' => $provider, 'tenant' => $tenant->id, 'return' => $data['r'], 'nonce_hash' => $data['n']];
    }

    /**
     * Whether $url is this restaurant's own "/me/google/done" on one of its hosts: eghub.app/{slug}/…, {slug}.eghub.app/…
     * or its active custom domain. Nothing else is ever redirected to.
     */
    public static function returnUrlBelongs(Tenant $tenant, string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ! isset($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        $slug = (string) $tenant->getTenantKey();
        $main = strtolower(central_domain());
        $done = '/me/google/done';

        if ($host === $main) {
            return $path === '/'.$slug.$done;
        }
        if ($host === $slug.'.'.$main) {
            return $path === $done;
        }
        $custom = $tenant->customDomain();

        return $custom !== null && strtolower((string) $custom->domain) === $host && $path === $done
            && Domain::query()->where('domain', $host)->where('tenant_id', $slug)->exists();
    }

    /**
     * The guest of $tenant for this Google identity, created when there is none: the Google subject first, then the
     * verified email of a guest not linked to another Google account. Runs in the restaurant's context and only ever
     * reads or writes that restaurant's rows.
     */
    public static function guestFor(Tenant $tenant, string $subject, ?string $verifiedEmail, string $name): Customer
    {
        return self::inTenant($tenant, function () use ($subject, $verifiedEmail, $name) {
            $customer = Customer::query()->where('google_sub', $subject)->first();
            if (! $customer && $verifiedEmail !== null) {
                $customer = Customer::query()->whereNull('google_sub')->where('email', $verifiedEmail)->first();
            }
            if (! $customer) {
                return Customer::query()->create([
                    'phone' => null, 'name' => mb_substr($name, 0, 100), 'google_sub' => $subject,
                    'email' => $verifiedEmail, 'email_verified_at' => $verifiedEmail !== null ? now() : null, 'source' => 'google',
                ]);
            }
            $customer->forceFill(array_filter([
                'google_sub' => $subject,
                'email' => $verifiedEmail ?? $customer->email,
                'name' => trim((string) $customer->name) === '' ? mb_substr($name, 0, 100) : null,
            ], fn ($v) => $v !== null));
            if ($verifiedEmail !== null && $customer->email === $verifiedEmail && $customer->email_verified_at === null) {
                // Google proved the inbox: a sign-up password nobody proved is dropped (GuestIdentity::forProvenEmail)
                $customer->forceFill(['email_verified_at' => now()] + ($customer->password !== null ? ['password' => null] : []));
            }
            $customer->save();

            return $customer;
        });
    }

    /** A random single-use ticket for the menu host, valid TICKET_SECONDS for this restaurant and this browser. */
    public static function issueTicket(Tenant $tenant, Customer $customer, string $nonceHash): string
    {
        $ticket = random_token(32);
        GuestSignIn::query()->create([
            'tenant_id' => $tenant->id, 'kind' => GuestSignIn::KIND_GOOGLE, 'secret_hash' => GuestSignIn::hash($ticket),
            'customer_id' => $customer->id, 'nonce_hash' => $nonceHash, 'expires_at' => now()->addSeconds(self::TICKET_SECONDS),
        ]);

        return $ticket;
    }

    /**
     * The guest of the CURRENT restaurant a ticket was issued for, once: used up by the first try, refused when
     * expired, issued for another restaurant, or presented by a browser without the matching nonce.
     */
    public static function redeemTicket(string $ticket, ?string $nonce): ?Customer
    {
        if (preg_match('/^[a-f0-9]{64}$/', $ticket) !== 1) {
            return null;
        }
        $row = GuestSignIn::query()->where('secret_hash', GuestSignIn::hash($ticket))->where('kind', GuestSignIn::KIND_GOOGLE)
            ->where('tenant_id', (string) tenant()->getTenantKey())->first();
        if (! $row) {
            return null;
        }
        // single use: only the request that flips used_at gets the guest
        $claimed = DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('used_at')->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1 || ! is_string($nonce) || $nonce === '' || ! hash_equals((string) $row->nonce_hash, GuestSignIn::hash($nonce))) {
            return null;
        }

        return Customer::query()->find($row->customer_id);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private static function inTenant(Tenant $tenant, \Closure $fn): mixed
    {
        $previous = tenancy()->initialized ? tenant() : null;
        tenancy()->initialize($tenant);
        try {
            return $fn();
        } finally {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();
            Settings::flush();
        }
    }
}
