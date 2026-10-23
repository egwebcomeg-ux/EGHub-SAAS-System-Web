<?php

namespace App\Billing\Gateways;

/** A hosted checkout the restaurant is sent to: where to redirect, and the gateway's id for it. */
final readonly class CheckoutSession
{
    public function __construct(public string $redirectUrl, public string $gatewayId) {}
}
