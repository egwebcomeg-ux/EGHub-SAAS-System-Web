<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Reset your password" email with a link valid for 60 minutes (staff and platform admins). */
class ResetPasswordLink extends Notification
{
    public function __construct(public string $url) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reset your EgMenu password'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('We received a request to reset the password of your account.'))
            ->action(__('Choose a new password'), $this->url)
            ->line(__('The link works for 60 minutes. If you did not ask for this, ignore this email and your password stays the same.'))
            ->salutation(__('EgMenu team'));
    }
}
