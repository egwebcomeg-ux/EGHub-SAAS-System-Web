<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The period ended unpaid: the menu stays online until the grace period ends. */
class SubscriptionPastDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sub = $this->subscription;

        return (new MailMessage)
            ->subject(__('Your EgMenu subscription has ended'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('The subscription of :restaurant has ended. Your menu stays online until :date.', [
                'restaurant' => $sub->tenant->name, 'date' => local_time($sub->grace_ends_at, 'Y/m/d'),
            ]))
            ->line(__('Pay before that date to keep it running. Nothing is deleted.'))
            ->action(__('Pay now'), platform_url('app', '/billing'))
            ->salutation(__('EgMenu team'));
    }
}
