<?php

namespace App\Notifications;

use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the restaurant's owners: EgWeb connected the custom domain they asked for, or could not (with the reason). */
class DomainDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Domain $domain) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->domain->status === 'active';
        $mail = (new MailMessage)
            ->subject($approved
                ? __('Your domain :domain is connected', ['domain' => $this->domain->domain])
                : __('Your domain :domain could not be connected', ['domain' => $this->domain->domain]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]));
        if ($approved) {
            $mail->line(__('Your menu now opens on :domain. Your QR codes and links keep working: they lead there from now on.', ['domain' => $this->domain->domain]));
        } else {
            $mail->line(__('We could not connect :domain to your menu.', ['domain' => $this->domain->domain]))
                ->line(__('Reason: :reason', ['reason' => (string) $this->domain->note]));
        }

        return $mail->action(__('Open the domain page'), platform_url('app', '/settings/domain'))->salutation(__('EgHub team'));
    }
}
