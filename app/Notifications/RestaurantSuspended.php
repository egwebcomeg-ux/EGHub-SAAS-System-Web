<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** EgWeb paused the restaurant (Admin\TenantsController::suspend): the menu is offline, the dashboard stays open to pay. */
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
            ->line(__('The menu of :restaurant is paused. Guests who scan your QR codes see a short notice instead of the menu.', ['restaurant' => $this->tenant->name]))
            ->line(__('Your menu, orders and customers are all kept. Pay any open invoice from the dashboard, or write to us on WhatsApp, and the menu comes back.'))
            ->action(__('Open the dashboard'), platform_url('app', '/billing'))
            ->salutation(__('EgHub team'));
    }
}
