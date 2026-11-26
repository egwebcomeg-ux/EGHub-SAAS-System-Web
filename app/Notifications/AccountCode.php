<?php

namespace App\Notifications;

use App\Services\Auth\EmailCode;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * The six-digit code of App\Services\Auth\EmailCode, sent at once (not queued: the person is waiting for it).
 * $action names the sensitive action being confirmed (EmailCode::CONFIRM only).
 */
class AccountCode extends Notification
{
    public function __construct(public string $code, public string $purpose, public string $name, public string $action = '') {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $why = match ($this->purpose) {
            EmailCode::LOGIN => __('Someone signed in to your EgHub account with the right password. Enter this code to finish signing in.'),
            EmailCode::VERIFY => __('Enter this code in your EgHub dashboard to confirm your email.'),
            EmailCode::ENABLE => __('Enter this code to turn on two-factor authentication by email.'),
            default => __('Enter this code to confirm: :action', ['action' => $this->action]),
        };

        return (new MailMessage)
            ->subject(__('Your EgHub code: :code', ['code' => $this->code]))
            ->greeting(__('Hello :name,', ['name' => $this->name]))
            ->line($why)
            ->line(new HtmlString('<div dir="ltr" style="margin:8px 0;text-align:center;font-size:32px;font-weight:800;letter-spacing:8px;color:#131D3D">'.e($this->code).'</div>'))
            ->line(__('The code works for :minutes minutes and only once.', ['minutes' => EmailCode::MINUTES]))
            ->line($this->purpose === EmailCode::LOGIN
                ? __('Not you? Someone knows your password: change it now from «Forgot password».')
                : __('If you did not ask for this, ignore this email.'))
            ->salutation(__('EgHub team'));
    }
}
