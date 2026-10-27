<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\GuestSignIn;
use Illuminate\Support\Facades\DB;

/**
 * «ادعي عملاءك»: a personal link per existing guest (an imported list, guests who only ever ordered by phone) that
 * opens a page on the restaurant's own address greeting them by name with their saved address, where they choose a
 * password (or continue with Google) and are signed in. One use, DAYS long, this restaurant's guest only; only the
 * hash is kept with the sign-ins (the campaign row keeps the link itself, encrypted, to show it on the send list).
 */
final class ClaimLinks
{
    public const DAYS = 14;

    /** A new link for $customer on the restaurant's canonical address (it is sent from the dashboard). */
    public function issue(Customer $customer, int $days = self::DAYS): string
    {
        $token = random_token(32);
        GuestSignIn::query()->create([
            'tenant_id' => (string) tenant()->getTenantKey(), 'kind' => GuestSignIn::KIND_CLAIM, 'secret_hash' => GuestSignIn::hash($token),
            'customer_id' => $customer->id, 'expires_at' => now()->addDays(max(1, min(60, $days))),
        ]);

        return tenant()->url('me/claim/'.$token);
    }

    /** The guest behind a link still good at the current restaurant, or null. */
    public function pending(string $token): ?Customer
    {
        $row = $this->row($token);

        return $row ? Customer::query()->find($row->customer_id) : null;
    }

    /** Uses the link up (the first use wins) and returns its guest. */
    public function redeem(string $token): ?Customer
    {
        $row = $this->row($token);
        if (! $row || DB::table('guest_sign_ins')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]) !== 1) {
            return null;
        }

        return Customer::query()->find($row->customer_id);
    }

    private function row(string $token): ?GuestSignIn
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return GuestSignIn::query()->where('secret_hash', GuestSignIn::hash($token))->where('kind', GuestSignIn::KIND_CLAIM)
            ->where('tenant_id', (string) tenant()->getTenantKey())->whereNull('used_at')->where('expires_at', '>', now())->first();
    }
}
