<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Marketing\MetaConversions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tells the restaurant's Meta pixel about a placed order from the server (App\Services\Marketing\MetaConversions).
 * Tenant context comes from stancl. A failed call is retried a few times; the guest and the order never wait for it.
 */
class SendMetaPurchase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /** @param array<string, string> $browser what the guest's browser showed when ordering (website orders only) */
    public function __construct(public int $orderId, public array $browser = [])
    {
        $this->onQueue('low'); // an ad event never holds up an order's message
    }

    public function handle(): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order || $order->status === 'cancelled') {
            return;
        }
        if (! MetaConversions::send($order, $this->browser) && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 600);
        }
    }
}
