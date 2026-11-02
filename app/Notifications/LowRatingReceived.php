<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the restaurant's owner and managers: a guest gave 1 or 2 stars, worth a call while it is fresh. */
class LowRatingReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $restaurant, public int $rating, public string $where, public ?string $comment, public string $url) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':restaurant: a guest gave :n of 5', ['restaurant' => $this->restaurant, 'n' => $this->rating]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('A guest gave :n of 5.', ['n' => $this->rating]).($this->where !== '' ? ' '.$this->where : ''))
            ->lineIf(filled($this->comment), __('Their comment: :comment', ['comment' => (string) $this->comment]))
            ->action(__('Open the ratings'), $this->url)
            ->salutation('EgHub');
    }
}
