<?php

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * An online gateway the restaurant pays its EgHub invoice through (Kashier today; Paymob or another one later
 * implements the same contract and is bound in AppServiceProvider). The gateway never decides what is paid:
 * App\Billing\OnlinePayments turns a verified GatewayResult into Billing::markPaid, exactly once.
 */
interface PaymentGateway
{
    /** Stored in payments.gateway, e.g. "kashier". */
    public function key(): string;

    /** Every key the gateway needs is present in config (services.<gateway>). */
    public function isConfigured(): bool;

    /**
     * Opens a hosted checkout for the payment attempt (its merchant_ref is the gateway order reference).
     *
     * @param  list<string>  $methods  OnlinePayments::METHODS keys the checkout may offer
     *
     * @throws \RuntimeException when the gateway refuses or cannot be reached
     */
    public function checkout(Invoice $invoice, Payment $payment, array $methods, string $returnUrl, string $webhookUrl): CheckoutSession;

    /** The webhook's outcome when its signature is valid; null for anything unsigned or tampered with. */
    public function verifyWebhook(Request $request): ?GatewayResult;

    /** Asks the gateway itself where the attempt stands (server to server); null when it cannot tell. */
    public function fetchStatus(Payment $payment): ?GatewayResult;
}
