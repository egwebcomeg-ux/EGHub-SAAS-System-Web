<?php

namespace App\Billing;

use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp message credits of a restaurant. Bought as packs (paid invoice → purchase), spent one per message,
 * given back when the provider refuses a message, adjusted by EgWeb by hand. Every change is a transaction row.
 * Wallets are platform rows keyed by tenant id, so they work from the dashboard, the queue and the super admin alike.
 */
final class Credits
{
    public function balance(Tenant|string $tenant): int
    {
        return (int) CreditWallet::query()->where('tenant_id', $this->id($tenant))->value('balance');
    }

    /** Adds (or with a negative delta removes, never below zero) credits. Returns the new balance. */
    public function add(Tenant|string $tenant, int $delta, string $reason, ?string $reference = null): int
    {
        $id = $this->id($tenant);

        return DB::transaction(function () use ($id, $delta, $reason, $reference) {
            $wallet = $this->wallet($id);
            $delta = max(-$wallet->balance, $delta);
            $wallet->update(['balance' => $wallet->balance + $delta]);
            CreditTransaction::query()->create(['tenant_id' => $id, 'delta' => $delta, 'reason' => $reason,
                'reference' => $reference ? mb_substr($reference, 0, 80) : null, 'balance_after' => $wallet->balance]);

            return $wallet->balance;
        });
    }

    /** Takes one credit for a message; false (nothing taken) when the wallet is empty. Safe under concurrency. */
    public function spend(Tenant|string $tenant, string $reference): bool
    {
        $id = $this->id($tenant);

        return DB::transaction(function () use ($id, $reference) {
            $wallet = $this->wallet($id);
            if ($wallet->balance < 1) {
                return false;
            }
            // conditional decrement: two workers can never take the last credit twice
            $taken = CreditWallet::query()->whereKey($wallet->id)->where('balance', '>=', 1)->decrement('balance');
            if ($taken !== 1) {
                return false;
            }
            CreditTransaction::query()->create(['tenant_id' => $id, 'delta' => -1, 'reason' => 'message',
                'reference' => $reference, 'balance_after' => $wallet->balance - 1]);

            return true;
        });
    }

    private function wallet(string $id): CreditWallet
    {
        CreditWallet::query()->firstOrCreate(['tenant_id' => $id], ['balance' => 0]);

        return CreditWallet::query()->where('tenant_id', $id)->lockForUpdate()->firstOrFail();
    }

    private function id(Tenant|string $tenant): string
    {
        return $tenant instanceof Tenant ? (string) $tenant->getTenantKey() : $tenant;
    }
}
