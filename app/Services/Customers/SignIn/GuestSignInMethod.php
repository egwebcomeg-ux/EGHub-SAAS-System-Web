<?php

namespace App\Services\Customers\SignIn;

use App\Models\Customer;

/**
 * One way for a guest to prove "this mobile is mine" on a new phone and open their account (حسابي). Today:
 * the mobile + the number of one of their delivered orders (OrderNumberSignIn). A WhatsApp code can be added later
 * as another implementation with its own controller action; SignInGuard does the throttling and the audit counts
 * for every method alike.
 */
interface GuestSignInMethod
{
    /** Short name for the audit counts, e.g. "order_number". */
    public function key(): string;

    /**
     * The guest when the proof matches, else null. Must not tell apart "unknown mobile" and "wrong proof".
     *
     * @param  array<string, string>  $input
     */
    public function attempt(string $phone, array $input): ?Customer;
}
