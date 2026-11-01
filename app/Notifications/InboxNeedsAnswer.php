<?php

namespace App\Notifications;

use App\Models\PlatformConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the team: someone on the EgHub WhatsApp number waits for a person («محادثات واتساب»). */
class InboxNeedsAnswer extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PlatformConversation $conversation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('WhatsApp: :name is waiting for an answer', ['name' => $this->conversation->displayName()]))
            ->line(__(':name wrote to the EgHub number and waits for someone from the team.', ['name' => $this->conversation->displayName()]))
            ->line(__('Mobile: :phone', ['phone' => '+'.$this->conversation->phone]))
            ->action(__('Open the conversation'), platform_url('admin', '/inbox/'.$this->conversation->id))
            ->salutation('EgHub');
    }
}
