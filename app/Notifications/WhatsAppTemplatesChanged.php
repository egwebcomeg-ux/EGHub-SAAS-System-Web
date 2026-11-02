<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the team answering the EgHub number: Meta changed the status of a WhatsApp template. */
class WhatsAppTemplatesChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, array{0: ?string, 1: string}>  $changed */
    public function __construct(public array $changed) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(__('WhatsApp templates changed at Meta'));
        foreach ($this->changed as $name => [, $status]) {
            $mail->line($name.': '.$status);
        }

        return $mail->line(__('When a template is approved, its service can be switched on.'));
    }
}
