<?php

namespace App\Notifications;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To EgWeb admins: someone asked for a one-time service on egmenu.app/services; call them back. */
class ServiceRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ServiceRequest $request) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->request;
        $mail = (new MailMessage)
            ->subject(__('Service request: :service · :restaurant', ['service' => $r->serviceName(), 'restaurant' => $r->restaurant]))
            ->line(__(':name from :restaurant asked for: :service.', ['name' => $r->name, 'restaurant' => $r->restaurant, 'service' => $r->serviceName()]))
            ->line(__('Mobile: :phone', ['phone' => $r->phone]));
        if ($r->email) {
            $mail->line(__('Email: :email', ['email' => $r->email]));
        }
        if ($r->quantity) {
            $mail->line(__('Quantity: :n', ['n' => $r->quantity]));
        }
        if ($r->notes) {
            $mail->line(__('Notes: :notes', ['notes' => $r->notes]));
        }

        return $mail->action(__('Review service requests'), platform_url('admin', '/services'))->salutation('EgMenu');
    }
}
