<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** About two weeks before the launch offer's free branch ends: from the next renewal it is billed at its price. */
class LaunchOfferEnding extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription, public float $monthlyPrice) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your free extra branch ends soon'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('The second branch is free until :date, then :price EGP/month.', [
                'date' => local_time($this->subscription->branch_offer_until, 'd/m/Y'), 'price' => money($this->monthlyPrice),
            ]))
            ->line(__('From your next renewal after that date it is on your invoice at its normal price.'))
            ->action(__('Renew now'), platform_url('app', '/billing'))
            ->salutation(__('EgHub team'));
    }
}
