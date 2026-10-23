<?php

namespace App\Billing;

use App\Billing\Gateways\GatewayResult;
use App\Billing\Gateways\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paying an EgMenu invoice online (Kashier today, behind App\Billing\Gateways\PaymentGateway).
 *  - The super admin switches it on (gateway_kashier_enabled), picks the methods shown (kashier_methods) and the
 *    amount from which installments are offered besides yearly invoices (kashier_installments_min).
 *  - start(): one pending Payment per attempt (merchant_ref = the gateway order reference), then the hosted checkout.
 *  - settle(): the gateway's verified answer. Success goes through Billing::markPaid with one key per attempt,
 *    so a replayed webhook or the return-page re-check never applies an invoice twice.
 * The restaurant (tenant) always comes from the payment row, never from what the gateway or the browser sends.
 */
final class OnlinePayments
{
    /**
     * Methods EgWeb can offer through the gateway, in display order. installments: shown under "Pay in installments".
     * Only names Kashier documents as payment methods (card, wallet, bank_installments, and its BNPL providers).
     *
     * @var array<string, array{ar: string, en: string, installments: bool}>
     */
    public const METHODS = [
        'card' => ['ar' => 'فيزا / ماستركارد / ميزة', 'en' => 'Card (Visa / Mastercard / Meeza)', 'installments' => false],
        'wallet' => ['ar' => 'المحافظ الإلكترونية', 'en' => 'Mobile wallets', 'installments' => false],
        'valu' => ['ar' => 'ValU', 'en' => 'valU', 'installments' => true],
        'aman' => ['ar' => 'أمان', 'en' => 'Aman', 'installments' => true],
        'souhoola' => ['ar' => 'سهولة', 'en' => 'Souhoola', 'installments' => true],
        'tru' => ['ar' => 'TRU', 'en' => 'TRU', 'installments' => true],
        'contact' => ['ar' => 'كونتكت', 'en' => 'Contact', 'installments' => true],
        'forsa' => ['ar' => 'فرصة', 'en' => 'Forsa', 'installments' => true],
        'mogo' => ['ar' => 'MoGo', 'en' => 'MoGo', 'installments' => true],
        'octo' => ['ar' => 'Octo', 'en' => 'Octo', 'installments' => true],
        'bank_installments' => ['ar' => 'تقسيط البنوك', 'en' => 'Bank installments', 'installments' => true],
    ];

    /** Pay now, or spread the invoice through an installment provider. */
    public const PLANS = ['now', 'installments'];

    public function __construct(private PaymentGateway $gateway, private Billing $billing) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    /** Switched on in the super admin and every key is in .env. */
    public function enabled(): bool
    {
        return PlatformSetting::get('gateway_'.$this->gateway->key().'_enabled') === '1' && $this->gateway->isConfigured();
    }

    /** @return list<string> the METHODS keys the super admin ticked */
    public function selectedMethods(): array
    {
        $picked = array_filter(array_map('trim', explode(',', (string) PlatformSetting::get($this->gateway->key().'_methods', ''))));

        return array_values(array_intersect(array_keys(self::METHODS), $picked));
    }

    /** @return list<string> */
    public function payNowMethods(): array
    {
        return $this->enabled() ? array_values(array_filter($this->selectedMethods(), fn (string $m) => ! self::METHODS[$m]['installments'])) : [];
    }

    /** @return list<string> */
    public function installmentMethods(): array
    {
        return $this->enabled() ? array_values(array_filter($this->selectedMethods(), fn (string $m) => self::METHODS[$m]['installments'])) : [];
    }

    public static function label(string $method): string
    {
        $row = self::METHODS[$method] ?? null;

        return $row ? tr($row['ar'], $row['en']) : ucfirst(str_replace('_', ' ', $method));
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'succeeded' => __('Paid'),
            'failed' => __('Failed'),
            'abandoned' => __('Abandoned'),
            default => __('In progress'),
        };
    }

    /** Installments are offered for a 12-month plan invoice, or any open invoice from the admin's minimum amount. */
    public function installmentsAllowedFor(Invoice $invoice): bool
    {
        if ($invoice->status !== 'open' || $this->installmentMethods() === []) {
            return false;
        }
        $minimum = (float) PlatformSetting::get($this->gateway->key().'_installments_min', '0');

        return ($invoice->kind === 'subscription' && (int) $invoice->cycle_months === 12) || ($minimum > 0 && $invoice->total >= $minimum);
    }

    /**
     * The marketing line under the yearly price ("pay online ..., spread the yearly plan over valU, Aman ..."), or null
     * when online payments are off or no installment provider is live: the site never promises what is not offered.
     */
    public function installmentsPromise(): ?string
    {
        $providers = $this->providerNames();
        if ($providers === null) {
            return null;
        }
        $now = $this->payNowNames();

        return $now === null
            ? __('The yearly plan can be paid in installments with :providers', ['providers' => $providers])
            : __('Pay online by :methods, and the yearly plan can be paid in installments with :providers', ['methods' => $now, 'providers' => $providers]);
    }

    /** "How do I pay the subscription?" on the marketing site, matching what is switched on right now. */
    public function howToPay(): string
    {
        $now = $this->payNowNames();
        if ($now === null) {
            return __('By Vodafone Cash or InstaPay from your dashboard. Paying for a whole year at once gets a discount.');
        }
        $providers = $this->providerNames();

        return __('Online from your dashboard by :methods, or by Vodafone Cash or InstaPay. Paying for a whole year at once gets a discount.', ['methods' => $now])
            .($providers ? ' '.__('The yearly plan can be paid in installments with :providers', ['providers' => $providers]).'.' : '');
    }

    /** "card or mobile wallets", or null when nothing is payable online right now. */
    private function payNowNames(): ?string
    {
        $names = array_map(fn (string $m) => $m === 'card' ? __('card') : __('mobile wallets'), $this->payNowMethods());

        return $names === [] ? null : self::join($names, true);
    }

    /** "valU, Aman and Souhoola" (bank installments only when no BNPL provider is on), or null. */
    private function providerNames(): ?string
    {
        $methods = $this->installmentMethods();
        $providers = array_values(array_filter($methods, fn (string $m) => $m !== 'bank_installments'));
        if ($providers === [] && in_array('bank_installments', $methods, true)) {
            $providers = ['bank_installments'];
        }

        return $providers === [] ? null : self::join(array_map(fn (string $m) => self::label($m), $providers), false);
    }

    /**
     * Opens a checkout for the invoice and returns where to send the restaurant.
     *
     * @throws \DomainException when the plan is not on offer for this invoice
     * @throws \RuntimeException when the gateway does not open a session
     */
    public function start(Invoice $invoice, string $plan, string $returnUrl, string $webhookUrl): string
    {
        $methods = $plan === 'installments' ? $this->installmentMethods() : $this->payNowMethods();
        if ($invoice->status !== 'open' || (float) $invoice->total <= 0 || $methods === []
            || ($plan === 'installments' && ! $this->installmentsAllowedFor($invoice))) {
            throw new \DomainException('This way of paying is not available for this invoice.');
        }
        $gateway = $this->gateway->key();
        $ref = 'EGM'.$invoice->id.'-'.strtoupper(random_token(5));
        $payment = DB::transaction(function () use ($invoice, $gateway, $ref, $plan, $methods) {
            // an earlier checkout left unfinished is replaced (a late success for it still settles the invoice)
            Payment::query()->where('invoice_id', $invoice->id)->where('gateway', $gateway)->where('status', 'pending')
                ->update(['status' => 'abandoned']);

            return Payment::query()->create([
                'invoice_id' => $invoice->id, 'gateway' => $gateway, 'status' => 'pending', 'amount' => $invoice->total,
                'merchant_ref' => $ref, 'idempotency_key' => $gateway.':pending:'.$ref,
                'installments' => $plan === 'installments', 'payload' => ['plan' => $plan, 'methods' => $methods],
            ]);
        });
        try {
            $session = $this->gateway->checkout($invoice, $payment, $methods, $returnUrl, $webhookUrl);
        } catch (\Throwable $e) {
            $payment->update(['status' => 'failed', 'failure_reason' => 'checkout_unavailable']);

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
        $payment->update(['idempotency_key' => $gateway.':session:'.$session->gatewayId,
            'payload' => $payment->payload + ['session_id' => $session->gatewayId]]);
        Audit::record('billing.online_checkout', $invoice->number, ['plan' => $plan, 'ref' => $ref]);

        return $session->redirectUrl;
    }

    /** The latest online attempt on the invoice, asked again from the gateway while it is still pending. */
    public function refresh(Invoice $invoice): ?Payment
    {
        $payment = Payment::query()->where('invoice_id', $invoice->id)->whereIn('gateway', Payment::ONLINE_GATEWAYS)->latest('id')->first();
        if ($payment && in_array($payment->status, ['pending', 'failed'], true) && $invoice->status === 'open'
            && $payment->gateway === $this->gateway->key() && ($result = $this->gateway->fetchStatus($payment))) {
            return $this->settle($result) ?? $payment;
        }

        return $payment;
    }

    /**
     * Applies a verified gateway answer to its payment attempt. Returns the payment, or null when the reference is
     * unknown or the event is not a payment (refunds and voids are handled by EgWeb by hand).
     */
    public function settle(GatewayResult $result): ?Payment
    {
        $gateway = $this->gateway->key();
        if ($result->event !== null && $result->event !== 'pay') {
            Log::info('Online payment event ignored', ['gateway' => $gateway, 'event' => $result->event, 'ref' => $result->merchantRef]);

            return null;
        }
        $payment = Payment::query()->where('gateway', $gateway)->where('merchant_ref', $result->merchantRef)->first();
        if (! $payment) {
            Log::warning('Online payment for an unknown reference', ['gateway' => $gateway, 'ref' => $result->merchantRef]);

            return null;
        }
        $invoice = $payment->invoice;
        $details = array_filter(['method' => $result->method, 'installments' => $result->installments || $payment->installments,
            'payload' => ($payment->payload ?? []) + ['gateway' => $result->data]], fn ($v) => $v !== null);

        if ($result->status === GatewayResult::SUCCEEDED) {
            if (! $this->amountMatches($result, $payment)) {
                // never activates a plan on a different amount: EgWeb looks at it in the super admin
                $payment->update(['failure_reason' => 'amount_mismatch', 'reference' => $result->transactionId ?? $payment->reference] + $details);
                Log::warning('Online payment amount mismatch', ['payment' => $payment->id, 'amount' => $result->amount, 'currency' => $result->currency]);

                return $payment->fresh();
            }
            $key = $gateway.':'.$payment->merchant_ref;
            $applied = $this->billing->markPaid($invoice, $gateway, $result->transactionId, (float) $payment->amount, $key, null, $payment, $details);
            $payment->refresh();
            if ($applied) {
                $this->audit($invoice, 'billing.paid_online', ['ref' => $payment->merchant_ref, 'method' => $payment->method, 'installments' => $payment->installments]);
            } elseif ($payment->status !== 'succeeded') {
                // money taken for an invoice that was meanwhile voided or paid otherwise: kept for EgWeb to settle
                $payment->update(['status' => 'succeeded', 'idempotency_key' => $key, 'reference' => $result->transactionId ?? $payment->reference,
                    'failure_reason' => 'invoice_not_open'] + $details);
                Log::warning('Online payment received for an invoice that is not open', ['payment' => $payment->id, 'invoice' => $invoice->number]);
            }

            return $payment->fresh();
        }
        if ($result->status === GatewayResult::FAILED && in_array($payment->status, ['pending', 'abandoned'], true)) {
            $payment->update(['status' => 'failed', 'failure_reason' => $result->reason ?? 'declined'] + $details);
            $this->audit($invoice, 'billing.online_failed', ['ref' => $payment->merchant_ref, 'reason' => $payment->failure_reason]);
        }

        return $payment->fresh();
    }

    private function amountMatches(GatewayResult $result, Payment $payment): bool
    {
        if ($result->currency !== null && $result->currency !== 'EGP') {
            return false;
        }
        if ($result->amount === null) {
            return true; // the signed session carries the amount we set; nothing else to compare
        }
        $expected = (float) $payment->amount;

        // pounds, or piasters (the unit is not stated for every Kashier payload)
        return abs($result->amount - $expected) < 0.01 || abs($result->amount / 100 - $expected) < 0.01;
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(Invoice $invoice, string $action, array $meta): void
    {
        $tenant = $invoice->tenant;
        if (! $tenant) {
            return;
        }
        $current = tenant();
        if ($current && $current->getTenantKey() === $tenant->getTenantKey()) {
            Audit::record($action, $invoice->number, $meta);

            return;
        }
        tenancy()->initialize($tenant);
        try {
            Audit::record($action, $invoice->number, $meta);
        } finally {
            $current ? tenancy()->initialize($current) : tenancy()->end();
        }
    }

    /** @param  list<string>  $names */
    private static function join(array $names, bool $or): string
    {
        if (count($names) < 2) {
            return (string) ($names[0] ?? '');
        }
        $last = array_pop($names);
        $first = implode(app()->getLocale() === 'en' ? ', ' : '، ', $names);

        return $or ? __(':first or :last', ['first' => $first, 'last' => $last]) : __(':first and :last', ['first' => $first, 'last' => $last]);
    }
}
