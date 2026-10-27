<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\GuestSignIn;
use App\Models\Order;
use App\Notifications\GuestAccountEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * «أكّد إيميلك»: the email a guest typed at checkout (customers.pending_email) becomes their sign-in once they open the
 * link we send it. Typing an email next to someone's mobile must never open that person's account, so the link
 * confirms only:
 *  - in a browser already signed in as that guest (they proved the mobile: a code, WhatsApp, a previous order number,
 *    their account link), or
 *  - for a guest that order itself created, whose every order carried that email (nothing of anyone else's to see).
 * An imported or older guest (saved addresses, earlier orders) therefore keeps the email pending until the mobile is
 * proven in that browser. Valid HOURS, used once.
 */
final class EmailConfirmation
{
    public const HOURS = 48;

    /** Sends the «أكّد إيميلك» link for $email to $customer (quietly nothing over the limits). */
    public function request(Customer $customer, string $email, string $ip): void
    {
        $emailKey = EmailSignIn::key('confirm-email', $email);
        $ipKey = EmailSignIn::key('confirm-ip', $ip);
        if (RateLimiter::tooManyAttempts($emailKey, 3) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            return;
        }
        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($ipKey, 3600);
        $tenant = (string) tenant()->getTenantKey();
        GuestSignIn::query()->where('tenant_id', $tenant)->where('kind', 'confirm')->where('customer_id', $customer->id)->whereNull('used_at')->update(['used_at' => now()]);
        $token = random_token(32);
        GuestSignIn::query()->create([
            'tenant_id' => $tenant, 'kind' => 'confirm', 'secret_hash' => GuestSignIn::hash($token), 'email' => $email,
            'customer_id' => $customer->id, 'expires_at' => now()->addHours(self::HOURS),
        ]);
        EmailSignIn::mail($email, new GuestAccountEmail(GuestAccountEmail::CONFIRM, route('menu.account.email.confirm', $token), null, EmailSignIn::place(), self::HOURS * 60));
    }

    public function pending(string $token): ?GuestSignIn
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return GuestSignIn::query()->where('secret_hash', GuestSignIn::hash($token))->where('kind', 'confirm')
            ->where('tenant_id', (string) tenant()->getTenantKey())->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    /**
     * Confirms the email when this browser may: 'ok' with the guest, 'elsewhere' (open it where you are signed in /
     * prove the mobile first; the link stays good), 'taken' (another guest here has it), 'invalid'.
     *
     * @return array{0:'ok'|'elsewhere'|'taken'|'invalid', 1:?Customer}
     */
    public function confirm(string $token, ?Customer $browser): array
    {
        $row = $this->pending($token);
        $customer = $row ? Customer::query()->find($row->customer_id) : null;
        if (! $row || ! $customer) {
            return ['invalid', null];
        }
        $email = (string) $row->email;
        if (! ($browser?->is($customer)) && ! self::madeByThisEmail($customer, $email)) {
            return ['elsewhere', null];
        }
        if (Customer::query()->where('email', $email)->whereKeyNot($customer->id)->exists()) {
            return ['taken', null];
        }
        if (DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]) !== 1) {
            return ['invalid', null];
        }
        $customer->forceFill(['email' => $email, 'email_verified_at' => now()]
            + ($customer->pending_email === $email ? ['pending_email' => null] : []))->save();

        return ['ok', $customer];
    }

    /** A guest the checkout itself created, every order of theirs typed with this email: nobody else's history. */
    public static function madeByThisEmail(Customer $customer, string $email): bool
    {
        if (in_array($customer->source, ['import', 'pos', 'callcenter'], true) || (float) $customer->credit_balance != 0.0) {
            return false;
        }
        $orders = Order::query()->where('customer_id', $customer->id)->orderBy('id')->get(['id', 'guest_email', 'created_at']);
        $first = $orders->first();

        return $first !== null && $orders->every(fn (Order $o) => $o->guest_email === $email)
            && $customer->created_at->gte($first->created_at->copy()->subMinutes(2));
    }
}
