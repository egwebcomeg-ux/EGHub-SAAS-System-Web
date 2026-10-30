<?php

namespace App\Services\Crm;

use App\Billing\Billing;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\TenantDiscount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Discounts given on a sales call. Granting one replaces the restaurant's usable discount (one at a time), writes the
 * lead's timeline and the platform audit log, and re-issues an open subscription invoice nobody started paying, so
 * the price the caller promised is the price on the invoice right away. The caller's limit is checked by the
 * controller (PlatformAdmin::discountLimit()); Billing::quote() applies it; Billing::markPaid() uses it up.
 */
final class TenantDiscounts
{
    public function __construct(private Billing $billing) {}

    public function grant(Tenant $tenant, PlatformAdmin $admin, float $percent, int $invoices, CarbonInterface $expiresAt, string $note): TenantDiscount
    {
        $discount = DB::transaction(function () use ($tenant, $admin, $percent, $invoices, $expiresAt, $note) {
            $replaced = TenantDiscount::query()->usable()->where('tenant_id', $tenant->id)->get();
            $replaced->each->update(['revoked_at' => now()]);
            $discount = TenantDiscount::query()->create([
                'tenant_id' => $tenant->id, 'percent' => $percent, 'invoices' => $invoices, 'expires_at' => $expiresAt,
                'note' => $note, 'granted_by' => $admin->id,
            ]);
            Lead::for($tenant)->events()->create(['type' => 'discount', 'admin_id' => $admin->id, 'notes' => $note, 'meta' => [
                'percent' => $percent, 'invoices' => $invoices, 'expires_at' => local_today($expiresAt), 'replaced' => $replaced->pluck('id')->all(),
            ]]);
            PlatformAuditLog::record($admin, 'crm.discount_granted', $tenant->id, [
                'discount' => $discount->id, 'percent' => $percent, 'invoices' => $invoices, 'expires_at' => local_today($expiresAt),
                'note' => $note, 'limit' => $admin->discountLimit(), 'replaced' => $replaced->pluck('id')->all(),
            ]);

            return $discount;
        });
        $this->reissueOpenInvoice($tenant);

        return $discount;
    }

    public function revoke(TenantDiscount $discount, PlatformAdmin $admin): void
    {
        if ($discount->revoked_at !== null) {
            return;
        }
        $discount->update(['revoked_at' => now()]);
        $tenant = $discount->tenant;
        Lead::for($tenant)->events()->create(['type' => 'discount', 'admin_id' => $admin->id, 'meta' => ['revoked' => $discount->id, 'percent' => $discount->percent]]);
        PlatformAuditLog::record($admin, 'crm.discount_revoked', $tenant->id, ['discount' => $discount->id, 'percent' => $discount->percent]);
        $this->reissueOpenInvoice($tenant);
    }

    /**
     * The open subscription invoice, quoted again with the discount as it is now. Left alone when a payment was claimed
     * or started on it (the restaurant may be paying that amount right now) or its plan is no longer invoiced.
     */
    private function reissueOpenInvoice(Tenant $tenant): void
    {
        $open = Invoice::query()->with('plan')->where('tenant_id', $tenant->id)->where('status', 'open')->where('kind', 'subscription')->latest('id')->get();
        $invoice = $open->first();
        if (! $invoice || ! $invoice->plan || Invoice::query()->whereIn('id', $open->pluck('id'))->whereHas('payments')->exists()) {
            return;
        }
        try {
            $this->billing->invoiceForPlan($tenant, $invoice->plan, (int) $invoice->cycle_months);
        } catch (\DomainException) {
            // archived or sales-only plan: the restaurant picks a plan again and gets a fresh quote
        }
    }
}
