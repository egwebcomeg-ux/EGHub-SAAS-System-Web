<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one queued WhatsApp message. Tenant context is restored by stancl's QueueTenancyBootstrapper. */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $messageId) {}

    public function handle(WhatsApp $whatsapp): void
    {
        $message = WhatsAppMessage::query()->find($this->messageId);
        if ($message) {
            $whatsapp->deliver($message);
        }
    }
}
