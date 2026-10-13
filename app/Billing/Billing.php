<?php

namespace App\Billing;

use App\Models\AddonPrice;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Subscription money rules in one place (adapted from Davaya):
 *  - choosing a plan restarts the period today; the unused value of a paid period is credited;
 *  - renewing the same plan extends the period from its end;
 *  - a payment is applied exactly once (payments.idempotency_key is unique);
 *  - manual transfers (InstaPay / Vodafone Cash) are "claimed" by the restaurant and confirmed by EgWeb.
 */
final class Billing
{
    public const GRACE_DAYS = 5;

    /** Unused value of the current paid period (0 during trial or when nothing was paid). */
    public function credit(Subscription $sub): float
    {
        if ($sub->status !== 'active' || !$sub->current_period_end || !$sub->current_period_start || !$sub->period_value) {
            return 0.0;
        }
        $total = max(1, $sub->current_period_start->diffInSeconds($sub->current_period_end));
        $left = max(0, now()->diffInSeconds($sub->current_period_end, false));
        return round((float) $sub->period_value * min(1, $left / $total), 2);
    }

    /** @return array{lines: list<array{label:string, amount:float}>, subtotal: float, discount: float, total: float, renewal: bool} */
    public function quote(Tenant $tenant, Plan $plan, int $months): array
    {
        $months = in_array($months, Plan::CYCLES, true) ? $months : 1;
        $sub = $tenant->subscription()->with('plan')->first();
        $renewal = $sub && $sub->status === 'active' && $sub->plan_id === $plan->id && $sub->cycle_months === $months;

        $full = round($plan->monthly_price * $months, 2);
        $price = $plan->priceFor($months);
        $lines = [['label' => __(':plan plan — :cycle', ['plan' => $plan->displayName(), 'cycle' => cycle_label($months)]), 'amount' => $full]];
        if ($full - $price > 0) {
            $lines[] = ['label' => __(':pct% discount for :cycle', ['pct' => money($plan->cycle_discounts[(string) $months] ?? 0), 'cycle' => cycle_label($months)]), 'amount' => -round($full - $price, 2)];
        }
        // add-ons already on the subscription are billed with the plan for the same cycle
        foreach ($sub?->addons ?? [] as $addon) {
            $price = AddonPrice::query()->where('key', $addon->key)->first();
            $lines[] = ['label' => ($price?->displayName() ?? $addon->key) . ($addon->quantity > 1 ? " × {$addon->quantity}" : '') . ' — ' . cycle_label($months),
                'amount' => round($addon->unit_price * $addon->quantity * $months, 2)];
            $full += round($addon->unit_price * $addon->quantity * $months, 2);
        }
        $credit = $sub && !$renewal ? $this->credit($sub) : 0.0;
        if ($credit > 0) {
            $lines[] = ['label' => __('Unused balance of your current plan'), 'amount' => -$credit];
        }
        $total = round(max(0, array_sum(array_column($lines, 'amount'))), 2);

        return ['lines' => $lines, 'subtotal' => $full, 'discount' => round($full - $total, 2), 'total' => $total, 'renewal' => $renewal];
    }

    /** Open invoice for a plan/cycle; an identical open one is reused, other open plan invoices are voided. */
    public function invoiceForPlan(Tenant $tenant, Plan $plan, int $months): Invoice
    {
        $q = $this->quote($tenant, $plan, $months);
        return DB::transaction(function () use ($tenant, $plan, $months, $q) {
            $open = Invoice::query()->where('tenant_id', $tenant->id)->where('status', 'open')->where('kind', 'subscription')->get();
            $same = $open->first(fn (Invoice $i) => $i->plan_id === $plan->id && $i->cycle_months === $months && abs($i->total - $q['total']) < 0.01);
            if ($same) {
                return $same;
            }
            $open->each->update(['status' => 'void']);

            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id, 'number' => 'TMP-' . random_token(6), 'kind' => 'subscription',
                'plan_id' => $plan->id, 'cycle_months' => $months, 'lines' => $q['lines'], 'subtotal' => $q['subtotal'],
                'discount' => $q['discount'], 'total' => $q['total'], 'status' => 'open', 'due_at' => now()->addDays(3),
                'meta' => ['renewal' => $q['renewal']],
            ]);
            $invoice->update(['number' => 'EGM-' . now()->format('Ym') . '-' . str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);
            return $invoice->fresh();
        });
    }

    /**
     * Invoice for adding a pack (POS, kitchen display...) or extra branch/staff. During a paid period it is prorated
     * to the period end (the next renewal then bills it for the whole cycle); otherwise one month.
     */
    public function invoiceForAddon(Tenant $tenant, AddonPrice $addon, int $quantity = 1): Invoice
    {
        $sub = $tenant->subscription()->first();
        $paidPeriod = $sub && $sub->status === 'active' && $sub->current_period_end?->isFuture();
        $months = $paidPeriod ? max(0.1, round(now()->diffInDays($sub->current_period_end) / 30, 2)) : 1;
        $amount = round($addon->monthly_price * $quantity * $months);
        $label = $addon->displayName() . ($quantity > 1 ? " × $quantity" : '') . ' — '
            . ($paidPeriod ? __('until :date', ['date' => local_time($sub->current_period_end, 'Y/m/d')]) : cycle_label(1));

        return DB::transaction(function () use ($tenant, $addon, $quantity, $amount, $label) {
            Invoice::query()->where('tenant_id', $tenant->id)->where('status', 'open')->where('kind', 'addon')
                ->where('meta->addon', $addon->key)->update(['status' => 'void']);
            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id, 'number' => 'TMP-' . random_token(6), 'kind' => 'addon',
                'lines' => [['label' => $label, 'amount' => $amount]], 'subtotal' => $amount, 'discount' => 0, 'total' => $amount,
                'status' => 'open', 'due_at' => now()->addDays(3), 'meta' => ['addon' => $addon->key, 'quantity' => $quantity],
            ]);
            $invoice->update(['number' => 'EGM-' . now()->format('Ym') . '-' . str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);
            return $invoice->fresh();
        });
    }

    /** Switches an add-on on or off for a restaurant (paid invoice, or EgWeb granting it by hand). */
    public function setAddon(Tenant $tenant, string $key, int $quantity): void
    {
        $sub = $tenant->subscription()->firstOrFail();
        if ($quantity <= 0) {
            $sub->addons()->where('key', $key)->delete();
        } else {
            $price = AddonPrice::query()->where('key', $key)->firstOrFail();
            $sub->addons()->updateOrCreate(['key' => $key], ['quantity' => $quantity, 'unit_price' => $price->monthly_price]);
        }
        Entitlements::flush();
    }

    /** The restaurant says it transferred the money: recorded as a pending payment for EgWeb to confirm. */
    public function claimManualPayment(Invoice $invoice, string $method, string $reference, ?string $sender): Payment
    {
        return Payment::query()->updateOrCreate(
            ['invoice_id' => $invoice->id, 'status' => 'pending'],
            ['gateway' => $method, 'reference' => $reference, 'idempotency_key' => 'claim:' . $invoice->id . ':' . random_token(4),
                'amount' => $invoice->total, 'payload' => ['sender' => $sender]],
        );
    }

    /**
     * Records a successful payment and applies the invoice. Idempotent: the same key (gateway transaction id,
     * or the admin confirmation) is applied once; later deliveries return false and change nothing.
     */
    public function markPaid(Invoice $invoice, string $gateway, ?string $reference, float $amount, string $idempotencyKey, ?int $recordedBy = null): bool
    {
        return DB::transaction(function () use ($invoice, $gateway, $reference, $amount, $idempotencyKey, $recordedBy) {
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);
            if ($invoice->status !== 'open' || Payment::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return false;
            }
            $pending = Payment::query()->where('invoice_id', $invoice->id)->where('status', 'pending')->first();
            if ($pending) {
                $pending->update(['status' => 'succeeded', 'gateway' => $gateway, 'reference' => $reference ?? $pending->reference,
                    'amount' => $amount, 'idempotency_key' => $idempotencyKey, 'recorded_by' => $recordedBy]);
            } else {
                Payment::query()->create(['invoice_id' => $invoice->id, 'gateway' => $gateway, 'reference' => $reference,
                    'idempotency_key' => $idempotencyKey, 'amount' => $amount, 'status' => 'succeeded', 'recorded_by' => $recordedBy]);
            }
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            $this->apply($invoice);
            return true;
        });
    }

    /** A paid plan invoice: new period (or extension on renewal), restaurant back online. A paid add-on: switched on. */
    private function apply(Invoice $invoice): void
    {
        $tenant = $invoice->tenant;
        if ($invoice->kind === 'addon') {
            $current = (int) $tenant->subscription()->first()?->addons()->where('key', $invoice->meta['addon'])->value('quantity');
            $price = AddonPrice::query()->where('key', $invoice->meta['addon'])->first();
            $this->setAddon($tenant, $invoice->meta['addon'], $price?->isFeaturePack() ? 1 : $current + (int) ($invoice->meta['quantity'] ?? 1));
            return;
        }
        $sub = $tenant->subscription()->first();
        $months = (int) $invoice->cycle_months;
        $renewal = (bool) ($invoice->meta['renewal'] ?? false) && $sub && $sub->current_period_end?->isFuture();

        $start = $renewal ? $sub->current_period_start : now();
        $end = ($renewal ? $sub->current_period_end->copy() : now())->addMonthsNoOverflow($months);

        Subscription::query()->updateOrCreate(['tenant_id' => $tenant->id], [
            'plan_id' => $invoice->plan_id, 'status' => 'active', 'cycle_months' => $months, 'trial_ends_at' => null,
            'current_period_start' => $start, 'current_period_end' => $end, 'grace_ends_at' => null, 'reminded_at' => null,
            'period_value' => $invoice->total,
        ]);
        if ($tenant->isSuspended()) {
            $tenant->update(['status' => 'active', 'suspended_at' => null]);
        }
        Entitlements::flush();
    }
}
