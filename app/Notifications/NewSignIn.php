<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "New sign-in to your EgHub account" from a browser the account never used (App\Services\Auth\LoginAlert). */
class NewSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $at, public string $device, public string $ip, public string $securityUrl) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('New sign-in to your EgHub account'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your account was just signed in to from a device it had not used before.'))
            ->line(__('Time: :at', ['at' => $this->at]))
            ->line(__('Device: :device', ['device' => $this->device]))
            ->line(__('IP address: :ip', ['ip' => $this->ip]))
            ->line(__('If this was you, there is nothing to do.'))
            ->line(__('Not you? Change your password now and turn on two-factor authentication.'))
            ->action(__('Account security'), $this->securityUrl)
            ->salutation(__('EgHub team'));
    }
}
