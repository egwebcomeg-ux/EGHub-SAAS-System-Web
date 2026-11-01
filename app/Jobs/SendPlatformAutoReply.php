<?php

namespace App\Jobs;

use App\Models\PlatformConversationMessage;
use App\Services\WhatsApp\PlatformAutoReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Answers one message to the EgHub number right after the webhook responded: Meta must get its 200 without waiting for the model, and the person must not wait for the next queue run. */
class SendPlatformAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public int $messageId) {}

    public function handle(PlatformAutoReply $autoReply): void
    {
        $message = PlatformConversationMessage::query()->find($this->messageId);
        if ($message) {
            $autoReply->answer($message);
        }
    }
}
