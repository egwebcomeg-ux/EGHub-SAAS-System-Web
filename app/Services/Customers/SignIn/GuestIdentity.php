<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;
use App\Models\Order;

/**
 * Who a proven email is at the current restaurant, and folding an account that holds only a sign-in (Google, an
 * email + password, nothing else) into the guest a stronger proof just opened. Everything runs inside the current
 * restaurant (Customer and Order carry the tenant scope): the same email at another restaurant is another guest.
 *
 * Trust of an email, from strongest: email_verified_at (Google, an emailed link or code, a reset); then customers.email
 * without it (typed at sign-up, or imported by the restaurant); customers.pending_email (typed at checkout) is never
 * trusted on its own: it becomes the guest's email only through «أكّد إيميلك» (EmailConfirmation).
 */
final class GuestIdentity
{
    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && mb_strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * The guest of this restaurant whose email was just proven by its owner, now marked verified; null when none.
     * An unproven sign-up password is dropped on the way: whoever typed it never showed the inbox was theirs.
     */
    public static function forProvenEmail(string $email): ?Customer
    {
        $customer = Customer::query()->where('email', $email)->orderByRaw('email_verified_at is null')->latest('id')->first();
        if ($customer) {
            if ($customer->email_verified_at === null) {
                $customer->forceFill(['email_verified_at' => now()] + ($customer->password !== null ? ['password' => null] : []))->save();
            }

            return $customer;
        }

        // an email typed at checkout (pending_email) never opens anyone by itself: EmailConfirmation decides
        return null;
    }

    /** Whether $customer holds only a way in (Google or email + password): no mobile, orders, points or money. */
    public static function isBare(Customer $customer): bool
    {
        return $customer->phone === '' && ($customer->google_sub !== null || $customer->password !== null || $customer->email !== null)
            && (int) $customer->points_balance === 0 && (float) $customer->credit_balance == 0.0
            && ! Order::query()->where('customer_id', $customer->id)->exists() && ! $customer->walletTransactions()->exists();
    }

    /**
     * A bare account ($bare: came in with Google or signed up with an email whose mobile belonged to another guest)
     * just proved, in the same browser, that it is $proven (WhatsApp, a previous order number, a link to $proven's
     * email, a claim link): its way in moves onto $proven and the bare row is removed. A Google account never replaces
     * another one; an email + password moves when $proven has no password of its own. Returns whether it moved.
     */
    public static function adopt(?Customer $bare, Customer $proven): bool
    {
        if (! $bare || $bare->is($proven) || ! self::isBare($bare)) {
            return false;
        }
        $moves = [];
        if ($bare->google_sub !== null && $proven->google_sub === null) {
            $moves += ['google_sub' => $bare->google_sub];
        }
        if ($bare->email !== null && $proven->password === null && ($bare->password !== null || $proven->email === null)) {
            $moves += ['email' => $bare->email, 'email_verified_at' => $bare->email_verified_at ?? $proven->email_verified_at, 'password' => $bare->password];
        }
        if ($moves === [] || ($bare->google_sub !== null && ! isset($moves['google_sub']))) {
            return false;
        }
        if (trim((string) $proven->name) === '' && trim((string) $bare->name) !== '') {
            $moves['name'] = $bare->name;
        }
        if ($bare->pending_phone !== null && $bare->pending_phone === $proven->phone) {
            $moves['pending_phone'] = null;
        }
        $bare->delete();
        // the password is already a hash: the cast keeps a hashed value as it is
        $proven->forceFill($moves)->save();

        return true;
    }
}
