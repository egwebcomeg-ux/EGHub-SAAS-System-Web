<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Grace period over: the menu is offline, the dashboard stays open to pay. */
class RestaurantSuspended extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Tenant $tenant) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('The menu of :restaurant is paused', ['restaurant' => $this->tenant->name]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('The menu of :restaurant is paused because the subscription was not renewed.', ['restaurant' => $this->tenant->name]))
            ->line(__('Your menu, orders and customers are all kept. The menu comes back as soon as the payment is confirmed.'))
            ->action(__('Pay now'), platform_url('app', '/billing'))
            ->salutation(__('EgMenu team'));
    }
}
