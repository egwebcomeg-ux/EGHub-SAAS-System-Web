<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A few days before the trial or paid period ends: renew to keep the menu online. */
class SubscriptionEndingSoon extends Notification implements ShouldQueue
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
        $trial = $sub->status === 'trialing';

        return (new MailMessage)
            ->subject($trial ? __('Your EgHub free trial ends soon') : __('Your EgHub subscription ends soon'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(($trial ? __('The free trial of :restaurant ends on :date.', ['restaurant' => $sub->tenant->name, 'date' => local_time($sub->endsAt(), 'Y/m/d')])
                : __('The subscription of :restaurant ends on :date.', ['restaurant' => $sub->tenant->name, 'date' => local_time($sub->endsAt(), 'Y/m/d')])))
            ->line(__('Choose a plan and pay from your dashboard so your menu stays online without a break.'))
            ->action(__('Renew now'), platform_url('app', '/billing'))
            ->salutation(__('EgHub team'));
    }
}
