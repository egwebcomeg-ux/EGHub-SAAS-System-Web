<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the owner of a restaurant EgWeb made for them (App\Services\WelcomeKit): the essentials and the link to the
 * welcome page. Never the password: the page shows it, while it is still the temporary one.
 */
class WelcomeKitReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $place,
        public string $welcomeUrl,
        public string $menuUrl,
        public string $dashboardUrl,
        public ?CarbonInterface $trialEndsAt,
        public string $plan,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Your EgHub dashboard for :place is ready', ['place' => $this->place]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('We set up :place on EgHub for you: the menu online, the dashboard and the other modules with sample data to try.', ['place' => $this->place]))
            ->line(__('Your menu link: :url', ['url' => $this->menuUrl]))
            ->line(__('Sign in to your dashboard: :url', ['url' => $this->dashboardUrl]));
        if ($this->trialEndsAt) {
            $mail->line(__('Your free trial of the :plan plan runs until :date.', ['plan' => $this->plan, 'date' => local_time($this->trialEndsAt, 'Y/m/d')]));
        }

        return $mail
            ->line(__('Your welcome page has your sign-in details and your first 5 steps. You will choose your own password at the first sign-in.'))
            ->action(__('Open your welcome page'), $this->welcomeUrl)
            ->salutation(__('EgHub team'));
    }
}
