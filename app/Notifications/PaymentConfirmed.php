<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Receipt: the invoice is paid and what it switched on. */
class PaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice;
        $mail = (new MailMessage)
            ->subject(__('Payment received · invoice :number', ['number' => $invoice->number]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('We received :amount EGP for invoice :number (:description). Thank you!', [
                'amount' => money($invoice->total), 'number' => $invoice->number, 'description' => $invoice->description(),
            ]));
        $end = $invoice->tenant->subscription()->first()?->current_period_end;
        if ($invoice->kind !== 'addon' && $end) {
            $mail->line(__('Your subscription is active until :date.', ['date' => local_time($end, 'Y/m/d')]));
        }

        return $mail->action(__('View invoice'), platform_url('app', '/billing/invoices/'.$invoice->number))
            ->salutation(__('EgHub team'));
    }
}
