<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** To EgWeb's owners and sales: a restaurant just signed up; its lead is waiting in the customer follow-up. */
class NewSignup extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Tenant $tenant, public User $owner, public string $businessType) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = Lead::for($this->tenant);

        return (new MailMessage)
            ->subject(__('New sign-up: :restaurant', ['restaurant' => $this->tenant->name]))
            ->line(__(':name signed up :restaurant (:type).', ['name' => $this->owner->name, 'restaurant' => $this->tenant->name, 'type' => BusinessType::label($this->businessType)]))
            ->line(__('Mobile: :phone', ['phone' => (string) $this->owner->phone]))
            ->line(__('Email: :email', ['email' => $this->owner->email]))
            ->action(__('Open the call screen'), platform_url('admin', '/crm/leads/'.$lead->id))
            ->salutation('EgHub');
    }

    /**
     * Who gets it: active owners and sales people of the admin team who follow up customers.
     *
     * @return Collection<int, PlatformAdmin>
     */
    public static function recipients(): Collection
    {
        return PlatformAdmin::notifiable('crm.manage')->filter(fn (PlatformAdmin $admin) => in_array($admin->role, ['owner', 'sales'], true))->values();
    }
}
