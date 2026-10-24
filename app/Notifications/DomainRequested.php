<?php

namespace App\Notifications;

use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To EgWeb admins: a restaurant asked for a custom domain (to register on egweb.com.eg, or to connect). */
class DomainRequested extends Notification implements ShouldQueue
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
        $tenant = $this->domain->tenant;

        return (new MailMessage)
            ->subject(__('Domain request: :domain (:restaurant)', ['domain' => $this->domain->domain, 'restaurant' => $tenant->name]))
            ->line($this->domain->source === 'egweb'
                ? __(':restaurant wants to book :domain through egweb.com.eg. After it is registered, point it to the server and activate it.', ['restaurant' => $tenant->name, 'domain' => $this->domain->domain])
                : __(':restaurant wants to connect its own domain :domain. Add it as an alias on the server with SSL, then activate it.', ['restaurant' => $tenant->name, 'domain' => $this->domain->domain]))
            ->action(__('Open the restaurant'), platform_url('admin', '/tenants/'.$tenant->id))
            ->salutation('EgHub');
    }
}
