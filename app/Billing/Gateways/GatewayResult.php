<?php

namespace App\Billing\Gateways;

/**
 * What a gateway says about one payment attempt, after its signature (or the server-to-server call) was checked.
 * status: succeeded | failed | pending. event: the gateway operation ("pay", "refund" ...), null when not given.
 */
final readonly class GatewayResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    /** @param  array<string, mixed>  $data  the verified fields, kept on the payment for support */
    public function __construct(
        public string $merchantRef,
        public string $status,
        public ?string $transactionId = null,
        public ?string $method = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public bool $installments = false,
        public ?string $reason = null,
        public ?string $event = null,
        public array $data = [],
    ) {}
}
