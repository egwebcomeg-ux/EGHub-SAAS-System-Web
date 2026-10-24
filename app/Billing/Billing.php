<?php

namespace App\Billing;

use App\Models\AddonPrice;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\PaymentClaimed;
use App\Notifications\PaymentConfirmed;
use App\Services\Audit;
use App\Services\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Subscription money rules in one place (adapted from Davaya):
 *  - choosing a plan restarts the period today; the unused value of a paid period is credited;
 *  - renewing the same plan extends the period from its end;
 *  - a payment is applied exactly once (payments.idempotency_key is unique);
 *  - manual transfers (InstaPay / Vodafone Cash) are "claimed" by the restaurant and confirmed by EgWeb;
 *  - online payments (App\Billing\OnlinePayments) are confirmed by the gateway's signed webhook through markPaid.
 */
final class Billing
{
    public const GRACE_DAYS = 5;

    /** Unused value of the current paid period (0 during trial or when nothing was paid). */
    public function credit(Subscription $sub): float
    {
        if ($sub->status !== 'active' || ! $sub->current_period_end || ! $sub->current_period_start || ! $sub->period_value) {
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
        // add-ons already on the subscription are billed with the plan for the same cycle, with the cycle's discount
        $pct = (float) ($plan->cycle_discounts[(string) $months] ?? 0);
        $from = $renewal && $sub->current_period_end?->isFuture() ? $sub->current_period_end : now();
        $offerUntil = LaunchOffer::until($sub, $plan);
        foreach ($sub?->addons ?? [] as $addon) {
            $price = AddonPrice::query()->where('key', $addon->key)->first();
            // per-branch packs follow the current number of branches
            $unit = $price?->per_branch ? $price->priceFor($tenant) : $addon->unit_price;
            $free = $addon->key === LaunchOffer::ADDON ? LaunchOffer::freeMonths($offerUntil, $from, $months) : 0;
            $charged = $addon->quantity * $months - $free;
            $full += round($unit * $addon->quantity * $months, 2);
            // the extra branch of a subscription whose launch offer is over says so on the first invoice that bills it
            $offerEnded = $addon->key === LaunchOffer::ADDON && $sub?->branch_offer_until && ! $sub->branch_offer_until->greaterThan($from);
            if ($charged > 0) {
                $lines[] = ['label' => ($offerEnded ? __('Extra branch — launch offer ended') : ($price?->displayName() ?? $addon->key))
                    .($addon->quantity > 1 ? " × {$addon->quantity}" : '').' — '.cycle_label($months)
                    .($pct > 0 ? ' ('.__(':pct% off', ['pct' => money($pct)]).')' : ''),
                    'amount' => round($unit * $charged * (1 - $pct / 100), 2)];
            }
            if ($free > 0) {
                $lines[] = ['label' => __('Extra branch: launch offer, free until :date', ['date' => local_time($offerUntil, 'Y/m/d')]), 'amount' => 0.0];
            }
        }
        $credit = $sub && ! $renewal ? $this->credit($sub) : 0.0;
        if ($credit > 0) {
            $lines[] = ['label' => __('Unused balance of your current plan'), 'amount' => -$credit];
        }
        $total = round(max(0, array_sum(array_column($lines, 'amount'))), 2);

        return ['lines' => $lines, 'subtotal' => $full, 'discount' => round($full - $total, 2), 'total' => $total, 'renewal' => $renewal];
    }

    /** Open invoice for a plan/cycle; an identical open one is reused, other open plan invoices are voided. */
    public function invoiceForPlan(Tenant $tenant, Plan $plan, int $months): Invoice
    {
        if ($plan->isArchived()) {
            throw new \DomainException('The '.$plan->code.' plan is archived and no longer sold.');
        }
        if ($plan->isContactSales()) {
            // Enterprise is agreed with sales and set by EgWeb from the super admin, never invoiced from the dashboard
            throw new \DomainException('The '.$plan->code.' plan is sold through sales only.');
        }
        $q = $this->quote($tenant, $plan, $months);

        return DB::transaction(function () use ($tenant, $plan, $months, $q) {
            $open = Invoice::query()->where('tenant_id', $tenant->id)->where('status', 'open')->where('kind', 'subscription')->get();
            $same = $open->first(fn (Invoice $i) => $i->plan_id === $plan->id && $i->cycle_months === $months && abs($i->total - $q['total']) < 0.01);
            if ($same) {
                return $same;
            }
            $open->each->update(['status' => 'void']);

            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id, 'number' => 'TMP-'.random_token(6), 'kind' => 'subscription',
                'plan_id' => $plan->id, 'cycle_months' => $months, 'lines' => $q['lines'], 'subtotal' => $q['subtotal'],
                'discount' => $q['discount'], 'total' => $q['total'], 'status' => 'open', 'due_at' => now()->addDays(3),
                'meta' => ['renewal' => $q['renewal']],
            ]);
            $invoice->update(['number' => 'EGM-'.now()->format('Ym').'-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);

            return $invoice->fresh();
        });
    }

    /**
     * Invoice for adding a pack (POS, kitchen display...) or extra branch/staff. During a paid period it is prorated
     * to the period end (the next renewal then bills it for the whole cycle); otherwise one month.
     */
    public function invoiceForAddon(Tenant $tenant, AddonPrice $addon, int $quantity = 1): Invoice
    {
        $sub = $tenant->subscription()->with('plan')->first();
        $paidPeriod = $sub && $sub->status === 'active' && $sub->current_period_end?->isFuture();
        $months = $paidPeriod ? max(0.1, round(now()->diffInDays($sub->current_period_end) / 30, 2)) : 1;
        // a paid 6 or 12 months cycle gives its discount to what is added during it
        $pct = $paidPeriod ? (float) ($sub->plan?->cycle_discounts[(string) $sub->cycle_months] ?? 0) : 0.0;
        $amount = round($addon->priceFor($tenant) * $quantity * $months * (1 - $pct / 100));
        $label = $addon->displayName().($quantity > 1 ? " × $quantity" : '').' — '
            .($paidPeriod ? __('until :date', ['date' => local_time($sub->current_period_end, 'Y/m/d')]) : cycle_label(1))
            .($pct > 0 ? ' ('.__(':pct% off', ['pct' => money($pct)]).')' : '');
        if ($addon->isCreditPack()) {
            // message credits are bought once, whatever the subscription period
            $amount = round((float) $addon->monthly_price * $quantity);
            $label = $addon->displayName().($quantity > 1 ? " × $quantity" : '');
        } elseif ($this->launchOfferCoversNextBranch($sub, $addon)) {
            // the first extra branch of a Complete subscription is free while its launch offer lasts
            $until = $sub->branch_offer_until;
            $paidMonths = $until->greaterThanOrEqualTo($sub->current_period_end) ? 0 : round($until->diffInDays($sub->current_period_end) / 30, 2);
            $amount = round($addon->priceFor($tenant) * $paidMonths * (1 - $pct / 100));
            $label = __('Extra branch: launch offer, free until :date', ['date' => local_time($until, 'Y/m/d')]);
        }

        return DB::transaction(function () use ($tenant, $addon, $quantity, $amount, $label) {
            Invoice::query()->where('tenant_id', $tenant->id)->where('status', 'open')->where('kind', 'addon')
                ->where('meta->addon', $addon->key)->update(['status' => 'void']);
            $invoice = Invoice::query()->create([
                'tenant_id' => $tenant->id, 'number' => 'TMP-'.random_token(6), 'kind' => 'addon',
                'lines' => [['label' => $label, 'amount' => $amount]], 'subtotal' => $amount, 'discount' => 0, 'total' => $amount,
                'status' => 'open', 'due_at' => now()->addDays(3), 'meta' => ['addon' => $addon->key, 'quantity' => $quantity],
            ]);
            $invoice->update(['number' => 'EGM-'.now()->format('Ym').'-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT)]);

            return $invoice->fresh();
        });
    }

    /** Whether the next extra branch this subscription buys is its first one, inside its launch offer. */
    public function launchOfferCoversNextBranch(?Subscription $sub, AddonPrice $addon): bool
    {
        if ($addon->key !== LaunchOffer::ADDON || ! $sub || $sub->status !== 'active' || ! $sub->branch_offer_until?->isFuture()
            || ! $sub->current_period_end?->isFuture() || $sub->plan?->code !== LaunchOffer::PLAN) {
            return false;
        }

        return (int) $sub->addons()->where('key', LaunchOffer::ADDON)->value('quantity') === 0;
    }

    /**
     * A Complete subscription that becomes paid inside its launch offer while it already runs more active branches
     * than its plan includes (the free second branch counted in its limit, LaunchOffer::coversBranchNow): that branch
     * becomes its first `branches` add-on. quote() bills it at 0 until branch_offer_until and at its price from the
     * first renewal after it. Nothing happens once the subscription has a branch add-on, so paying again adds nothing.
     */
    public function claimLaunchOfferBranch(Tenant $tenant): bool
    {
        $sub = $tenant->subscription()->with('plan', 'addons')->first();
        if (! LaunchOffer::coversBranchNow($sub) || $sub->status !== 'active' || (int) $sub->addons->firstWhere('key', LaunchOffer::ADDON)?->quantity > 0) {
            return false;
        }
        $included = $sub->plan->entitlements()['limits']['branches'] ?? null;
        $active = Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->count();
        $covered = $included === null ? 0 : min(1, $active - (int) $included);
        if ($covered <= 0 || ! AddonPrice::query()->where('key', LaunchOffer::ADDON)->exists()) {
            return false;
        }
        $this->setAddon($tenant, LaunchOffer::ADDON, $covered);
        $tenant->run(fn () => Audit::record('billing.launch_offer_branch_added', LaunchOffer::ADDON, [
            'quantity' => $covered, 'branches' => $active, 'free_until' => $sub->branch_offer_until->toDateString(),
        ]));

        return true;
    }

    /** Switches an add-on on or off for a restaurant (paid invoice, or EgWeb granting it by hand). */
    public function setAddon(Tenant $tenant, string $key, int $quantity): void
    {
        $sub = $tenant->subscription()->firstOrFail();
        if ($quantity <= 0) {
            $sub->addons()->where('key', $key)->delete();
        } else {
            $price = AddonPrice::query()->where('key', $key)->firstOrFail();
            $sub->addons()->updateOrCreate(['key' => $key], ['quantity' => $quantity, 'unit_price' => $price->priceFor($tenant)]);
            // a pack that covers smaller packs replaces them (e.g. the full operations bundle)
            if ($price->isFeaturePack() && $price->features) {
                $covered = AddonPrice::query()->where('type', 'feature')->where('key', '!=', $key)->get()
                    ->filter(fn (AddonPrice $p) => $p->features && array_diff($p->features, $price->features) === [])->pluck('key');
                $sub->addons()->whereIn('key', $covered)->delete();
            }
        }
        Entitlements::flush();
    }

    /** The restaurant says it transferred the money: recorded as a pending payment for EgWeb to confirm. */
    public function claimManualPayment(Invoice $invoice, string $method, string $reference, ?string $sender): Payment
    {
        // one open claim per invoice; an online checkout left pending (Payment::ONLINE_GATEWAYS) is a separate attempt
        $payment = Payment::query()->where('invoice_id', $invoice->id)->where('status', 'pending')
            ->whereNotIn('gateway', Payment::ONLINE_GATEWAYS)->first() ?? new Payment(['invoice_id' => $invoice->id, 'status' => 'pending']);
        $payment->fill(['gateway' => $method, 'reference' => $reference, 'idempotency_key' => 'claim:'.$invoice->id.':'.random_token(4),
            'amount' => $invoice->total, 'payload' => ['sender' => $sender]])->save();
        Notification::send(PlatformAdmin::notifiable('billing.manage'), new PaymentClaimed($payment));

        return $payment;
    }

    /**
     * Records a successful payment and applies the invoice. Idempotent: the same key (gateway transaction id,
     * or the admin confirmation) is applied once; later deliveries return false and change nothing.
     * $attempt is the online checkout being settled (its row becomes the succeeded payment); without it the pending
     * manual claim is the one confirmed, or a new payment row is written.
     *
     * @param  array<string, mixed>  $extra  more payment columns to store (method, installments ...)
     */
    public function markPaid(Invoice $invoice, string $gateway, ?string $reference, float $amount, string $idempotencyKey, ?int $recordedBy = null,
        ?Payment $attempt = null, array $extra = []): bool
    {
        $applied = DB::transaction(function () use ($invoice, $gateway, $reference, $amount, $idempotencyKey, $recordedBy, $attempt, $extra) {
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);
            if ($invoice->status !== 'open' || Payment::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return false;
            }
            $pending = $attempt && $attempt->invoice_id === $invoice->id
                ? Payment::query()->lockForUpdate()->find($attempt->id)
                : Payment::query()->where('invoice_id', $invoice->id)->where('status', 'pending')
                    ->whereNotIn('gateway', Payment::ONLINE_GATEWAYS)->first();
            if ($pending) {
                $pending->update(['status' => 'succeeded', 'gateway' => $gateway, 'reference' => $reference ?? $pending->reference,
                    'amount' => $amount, 'idempotency_key' => $idempotencyKey, 'recorded_by' => $recordedBy, 'failure_reason' => null] + $extra);
            } else {
                Payment::query()->create(['invoice_id' => $invoice->id, 'gateway' => $gateway, 'reference' => $reference,
                    'idempotency_key' => $idempotencyKey, 'amount' => $amount, 'status' => 'succeeded', 'recorded_by' => $recordedBy] + $extra);
            }
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            $this->apply($invoice);

            return true;
        });
        if ($applied) {
            Notification::send($invoice->tenant->owners(), new PaymentConfirmed($invoice->fresh()));
        }

        return $applied;
    }

    /**
     * A paid plan invoice: new period (or extension on renewal), restaurant back online. A paid add-on: switched on.
     * A paid credit pack: its messages are added to the restaurant's WhatsApp credits.
     */
    private function apply(Invoice $invoice): void
    {
        $tenant = $invoice->tenant;
        if ($invoice->kind === 'addon' && ($pack = AddonPrice::query()->where('key', $invoice->meta['addon'] ?? '')->first())?->isCreditPack()) {
            // WhatsApp credits go to the wallet; the subscription is not touched
            app(Credits::class)->add($tenant, (int) $pack->credits * (int) ($invoice->meta['quantity'] ?? 1), 'purchase', $invoice->number);

            return;
        }
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
        LaunchOffer::grant($tenant->subscription()->first(), $start);
        $this->claimLaunchOfferBranch($tenant);
        if ($tenant->isSuspended()) {
            $tenant->update(['status' => 'active', 'suspended_at' => null]);
        }
        Entitlements::flush();
    }
}
