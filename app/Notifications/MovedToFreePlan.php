<?php

namespace App\Notifications;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Trial or paid period ended unpaid: the restaurant is on the free plan now (menu online, paid features paused). */
class MovedToFreePlan extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param 'trial'|'subscription' $reason */
    public function __construct(public Tenant $tenant, public ?Plan $previous, public string $reason) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->tenant->name;

        return (new MailMessage)
            ->subject($this->reason === 'trial' ? __('Your EgMenu trial has ended') : __('Your EgMenu subscription has ended'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line($this->reason === 'trial'
                ? __('The free trial of :restaurant is over, so it is now on the free plan.', ['restaurant' => $name])
                : __('The :plan subscription of :restaurant was not renewed, so it is now on the free plan.', ['plan' => $this->previous?->displayName() ?? '', 'restaurant' => $name]))
            ->line(__('Your menu and QR codes keep working. Table and online ordering, the loyalty card and the other paid features are paused until you choose a plan; everything you set up is kept.'))
            ->action(__('Choose a plan'), platform_url('app', '/billing'))
            ->salutation(__('EgMenu team'));
    }
}
