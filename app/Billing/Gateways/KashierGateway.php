<?php

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kashier (developers.kashier.io), through its Payment Sessions API:
 *  - POST {api}/v3/payment/sessions with the secret key in Authorization and the payment API key in "api-key";
 *    the answer's sessionUrl is the hosted checkout (card, wallets, bank installments, BNPL such as valU);
 *  - the server webhook carries x-kashier-signature = HMAC-SHA256(payment API key) of the data fields named in
 *    data.signatureKeys, sorted, as key=value pairs joined with & (values URL-encoded, RFC 3986);
 *  - GET {api}/v3/payment/sessions/{id}/payment tells where a session stands (CREATED ... PAID / FAILED).
 * Keys live in .env only (services.kashier); the browser redirect back is never trusted on its own.
 */
final class KashierGateway implements PaymentGateway
{
    /** BNPL providers Kashier lists as standalone methods; they are offered through allowedMethods "bnpl". */
    private const BNPL = ['valu', 'souhoola', 'aman', 'contact', 'tru', 'forsa', 'mogo', 'octo'];

    /** Session states that end the attempt without money. */
    private const FAILED_STATES = ['FAILED', 'EXPIRED', 'ABANDONED', 'REJECTED', 'VOIDED', 'REVERSED'];

    public function key(): string
    {
        return 'kashier';
    }

    public function isConfigured(): bool
    {
        return $this->config('merchant_id') !== '' && $this->config('api_key') !== '' && $this->config('secret_key') !== '';
    }

    public function checkout(Invoice $invoice, Payment $payment, array $methods, string $returnUrl, string $webhookUrl): CheckoutSession
    {
        $owner = $invoice->tenant?->owners()->first();
        $response = Http::baseUrl($this->apiUrl())->acceptJson()->timeout(20)
            ->withHeaders(['Authorization' => $this->config('secret_key'), 'api-key' => $this->config('api_key')])
            ->post('/v3/payment/sessions', [
                'expireAt' => now()->addHours(2)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'maxFailureAttempts' => 3,
                'paymentType' => 'credit',
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => 'EGP',
                'order' => $payment->merchant_ref,
                'merchantId' => $this->config('merchant_id'),
                'mode' => $this->mode(),
                'merchantRedirect' => $returnUrl,
                'serverWebhook' => $webhookUrl,
                'failureRedirect' => true,
                'allowedMethods' => $this->allowedMethods($methods),
                'display' => app()->getLocale() === 'en' ? 'en' : 'ar',
                'type' => 'one-time',
                'description' => mb_substr('EgMenu '.$invoice->number.' · '.$invoice->description(), 0, 119),
                'customer' => array_filter(['email' => $owner?->email, 'reference' => (string) $invoice->tenant_id]),
                'metaData' => ['invoice' => $invoice->number, 'tenant' => (string) $invoice->tenant_id],
            ]);

        $sessionId = (string) $response->json('_id', '');
        $url = (string) $response->json('sessionUrl', '');
        if (! $response->successful() || $sessionId === '' || ! str_starts_with($url, 'https://')) {
            // the body may echo the merchant's API key: only the status and the error code are logged
            Log::warning('Kashier session refused', ['status' => $response->status(), 'error' => $response->json('message') ?? $response->json('error')]);

            throw new \RuntimeException('Kashier did not open a payment session.');
        }

        return new CheckoutSession($url, $sessionId);
    }

    public function verifyWebhook(Request $request): ?GatewayResult
    {
        $apiKey = $this->config('api_key');
        $given = strtolower((string) $request->header('x-kashier-signature', ''));
        // the raw JSON: Laravel's input middleware would trim strings / turn "" into null and break the signature
        $body = json_decode((string) $request->getContent(), true);
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        if ($apiKey === '' || $given === '' || ! is_array($data) || ! is_array($data['signatureKeys'] ?? null)) {
            return null;
        }
        if (! hash_equals($this->signature($data, $apiKey), $given)) {
            return null;
        }
        $ref = (string) ($data['merchantOrderId'] ?? '');
        if ($ref === '') {
            return null;
        }
        $method = strtolower((string) ($data['method'] ?? '')) ?: null;

        return new GatewayResult(
            merchantRef: $ref,
            status: match (strtoupper((string) ($data['status'] ?? ''))) {
                'SUCCESS' => GatewayResult::SUCCEEDED,
                'FAILURE' => GatewayResult::FAILED,
                default => GatewayResult::PENDING,
            },
            transactionId: isset($data['transactionId']) ? (string) $data['transactionId'] : null,
            method: $method,
            amount: is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null,
            currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            installments: ! empty($data['installmentPlan']) || ($method !== null && ! in_array($method, ['card', 'wallet'], true)),
            reason: $this->reason($data),
            event: isset($body['event']) ? strtolower((string) $body['event']) : null,
            data: array_intersect_key($data, array_flip(['kashierOrderId', 'orderReference', 'transactionId', 'transactionResponseCode', 'method', 'status', 'channel'])),
        );
    }

    public function fetchStatus(Payment $payment): ?GatewayResult
    {
        $sessionId = (string) ($payment->payload['session_id'] ?? '');
        if ($sessionId === '' || ! $this->isConfigured()) {
            return null;
        }
        try {
            $response = Http::baseUrl($this->apiUrl())->acceptJson()->timeout(10)
                ->withHeaders(['Authorization' => $this->config('secret_key')])
                ->get('/v3/payment/sessions/'.rawurlencode($sessionId).'/payment');
        } catch (\Throwable $e) {
            Log::warning('Kashier status check failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return null;
        }
        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data)) {
            return null;
        }
        $state = strtoupper((string) ($data['status'] ?? ''));
        $method = strtolower((string) ($data['method'] ?? '')) ?: null;

        return new GatewayResult(
            // asked about our own session id: the answer belongs to this payment whatever it echoes
            merchantRef: (string) $payment->merchant_ref,
            status: match (true) {
                $state === 'PAID' => GatewayResult::SUCCEEDED,
                in_array($state, self::FAILED_STATES, true) => GatewayResult::FAILED,
                default => GatewayResult::PENDING,
            },
            transactionId: null,
            method: $method,
            amount: is_numeric($data['amount'] ?? null) ? (float) $data['amount'] : null,
            currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            installments: $method !== null && ! in_array($method, ['card', 'wallet'], true),
            reason: in_array($state, self::FAILED_STATES, true) ? strtolower($state) : null,
            event: 'pay',
            data: ['session_status' => $state, 'kashierOrderId' => $data['orderId'] ?? null],
        );
    }

    /**
     * Kashier's webhook signature: the fields listed in signatureKeys, sorted, as a query string.
     *
     * @param  array<string, mixed>  $data
     */
    public function signature(array $data, string $apiKey): string
    {
        $keys = array_values(array_filter((array) ($data['signatureKeys'] ?? []), 'is_string'));
        sort($keys, SORT_STRING);
        $pairs = [];
        foreach ($keys as $key) {
            $pairs[$key] = $data[$key] ?? '';
        }

        return hash_hmac('sha256', http_build_query($pairs, '', '&', PHP_QUERY_RFC3986), $apiKey);
    }

    /**
     * allowedMethods for the session: card, wallet, bank_installments, and "bnpl" for the BNPL providers
     * (narrowed to "bnpl[valu]" when only one is offered, the form the docs give). Kashier intersects it
     * with what the merchant account is entitled to.
     *
     * @param  list<string>  $methods
     */
    public function allowedMethods(array $methods): string
    {
        $bnpl = array_values(array_intersect(self::BNPL, $methods));
        $out = array_values(array_intersect(['card', 'wallet', 'bank_installments'], $methods));
        if ($bnpl !== []) {
            $out[] = count($bnpl) === 1 ? 'bnpl['.$bnpl[0].']' : 'bnpl';
        }

        return implode(',', $out ?: ['card']);
    }

    /** @param  array<string, mixed>  $data */
    private function reason(array $data): ?string
    {
        if (strtoupper((string) ($data['status'] ?? '')) === 'SUCCESS') {
            return null;
        }
        $message = $data['transactionResponseMessage'] ?? null;
        $text = is_array($message) ? ($message['en'] ?? reset($message)) : $message;

        return mb_substr((string) ($text ?: ($data['transactionResponseCode'] ?? 'declined')), 0, 250) ?: null;
    }

    private function mode(): string
    {
        return $this->config('mode') === 'live' ? 'live' : 'test';
    }

    private function apiUrl(): string
    {
        return $this->config('base_url') ?: ($this->mode() === 'live' ? 'https://api.kashier.io' : 'https://test-api.kashier.io');
    }

    private function config(string $key): string
    {
        return trim((string) config('services.kashier.'.$key, ''));
    }
}
