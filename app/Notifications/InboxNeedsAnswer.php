<?php

namespace App\Notifications;

use App\Models\PlatformConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the team: someone who wrote to the EgHub number or page waits for a person («المحادثات»). */
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
        $where = ['name' => $this->conversation->displayName(), 'channel' => $this->conversation->channelLabel()];

        return (new MailMessage)
            ->subject(__(':channel: :name is waiting for an answer', $where))
            ->line(__(':name wrote to EgHub on :channel and waits for someone from the team.', $where))
            ->lineIf($this->conversation->isWhatsApp(), __('Mobile: :phone', ['phone' => '+'.$this->conversation->phone]))
            ->action(__('Open the conversation'), platform_url('admin', '/inbox/'.$this->conversation->id))
            ->salutation('EgHub');
    }
}
