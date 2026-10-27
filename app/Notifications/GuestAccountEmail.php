<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A guest's email from one restaurant (App\Services\Customers\SignIn): «ادخل حسابك» (a one-time link + a 6-digit code)
 * «اختار باسورد جديد» (the reset link) or «أكّد إيميلك» (after an order with an email). Sent from the platform mailer with the restaurant's name as the sender's
 * name and its logo on top; every link lands on the restaurant's own address the guest was using. Queued: the
 * request answers the same way at once whether an account exists or not.
 */
class GuestAccountEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public const SIGN_IN = 'sign_in';

    public const RESET = 'reset';

    /** «أكّد إيميلك» after an order (App\Services\Customers\SignIn\EmailConfirmation) */
    public const CONFIRM = 'confirm';

    /**
     * @param  array{name:string, logo:?string, color:string, menu:string}  $place
     */
    public function __construct(
        public string $kind,
        public string $url,
        public ?string $code,
        public array $place,
        public int $minutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->place['name'];
        $subject = match ($this->kind) {
            self::RESET => __('Choose a new password for your account at :place', ['place' => $name]),
            self::CONFIRM => __('Confirm your email at :place', ['place' => $name]),
            default => __('Your sign-in code at :place: :code', ['place' => $name, 'code' => (string) $this->code]),
        };

        return (new MailMessage)
            ->from((string) config('mail.from.address'), $name)
            ->subject($subject)
            ->view(['html' => 'emails.guest-account', 'text' => 'emails.guest-account-text'], [
                'kind' => $this->kind, 'url' => $this->url, 'code' => $this->code, 'place' => $this->place, 'minutes' => $this->minutes,
            ]);
    }
}
