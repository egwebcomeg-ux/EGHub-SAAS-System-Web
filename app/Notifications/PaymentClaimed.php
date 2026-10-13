<?php

namespace App\Notifications;

use App\Billing\PaymentMethods;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To EgWeb admins: a restaurant says it transferred money; confirm it from the admin panel. */
class PaymentClaimed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->payment->invoice;

        return (new MailMessage)
            ->subject(__('Payment to confirm: :restaurant · :amount EGP', ['restaurant' => $invoice->tenant->name, 'amount' => money($this->payment->amount)]))
            ->line(__(':restaurant says it paid invoice :number by :method, reference :reference.', [
                'restaurant' => $invoice->tenant->name, 'number' => $invoice->number,
                'method' => PaymentMethods::label($this->payment->gateway), 'reference' => $this->payment->reference,
            ]))
            ->action(__('Review payments'), platform_url('admin', '/invoices'))
            ->salutation('EgMenu');
    }
}
