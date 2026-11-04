<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\Orders\OrderConfirmation;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one queued WhatsApp message. Tenant context is restored by stancl's QueueTenancyBootstrapper. A provider
 * that cannot be reached, or asks for a pause, gets the message again twice (a minute, then two minutes later);
 * after that the message fails like any other.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $messageId) {}

    public function handle(WhatsApp $whatsapp): void
    {
        $message = WhatsAppMessage::query()->find($this->messageId);
        if (! $message) {
            return;
        }
        $message = $whatsapp->deliver($message);
        if ($message->status === 'queued' && $message->error === WhatsAppMessage::RETRY_LATER) {
            $this->release($this->attempts() * 60);
        }
    }

    /** Out of tries: the message is given up like any other failed one. */
    public function failed(?\Throwable $e): void
    {
        $message = WhatsAppMessage::query()->find($this->messageId);
        if ($message && $message->status === 'queued') {
            $message->update(['status' => 'failed', 'error' => mb_substr('provider unreachable: '.($e?->getMessage() ?? ''), 0, 255)]);
            OrderConfirmation::messageFailed($message);
        }
    }
}
