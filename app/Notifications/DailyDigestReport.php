<?php

namespace App\Notifications;

use App\Services\Reports\DailyDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Every morning: yesterday at the restaurant, what a manager signs off (App\Services\Reports\DailyDigest). */
class DailyDigestReport extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string,mixed> $digest DailyDigest::build() and the restaurant's name */
    public function __construct(public array $digest) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function method(string $method): string
    {
        return [
            'cash' => __('Cash'), 'card' => __('Card'), 'instapay' => __('InstaPay'), 'wallet' => __('Wallet'),
            'credit' => __('On account'), 'corporate' => __('Company vouchers'), 'split' => __('Split payment'),
        ][$method] ?? $method;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->digest;
        $mail = (new MailMessage)
            ->subject(__('Yesterday at :restaurant (:date)', ['restaurant' => $d['restaurant'], 'date' => $d['date']]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line('**'.__('Sales').':** '.__(':n orders · :amount paid', ['n' => $d['orders'], 'amount' => money($d['revenue'])]));
        if ($d['methods']) {
            $mail->line(collect($d['methods'])->map(fn ($v, $m) => self::method((string) $m).' '.money($v))->implode(' · '));
        }
        if ($d['discounts'] > 0) {
            $mail->line('**'.__('Discounts given').':** '.money($d['discounts']));
        }
        foreach ($d['cancelled'] as $c) {
            $mail->line('❌ '.__('Cancelled :order: :reason (approved by :approver)', ['order' => $c['order'], 'reason' => $c['reason'] ?: '—', 'approver' => $c['approved_by'] ?: $c['by'] ?: '—']));
        }
        foreach ($d['refunds'] as $r) {
            $mail->line('↩️ '.__('Refund :order of :amount: :reason (approved by :approver)', ['order' => $r['order'], 'amount' => money($r['amount']), 'reason' => $r['reason'], 'approver' => $r['approved_by'] ?: '—']));
        }
        foreach (DailyDigest::cashOff($d) as $s) {
            $mail->line('💵 '.__('Shift of :user: counted :counted, expected :expected (:difference)', [
                'user' => $s['user'], 'counted' => money($s['counted']), 'expected' => money($s['expected']), 'difference' => ($s['difference'] > 0 ? '+' : '').money($s['difference']),
            ]));
        }
        if ($d['open_orders'] > 0) {
            $mail->line('⏳ '.__(':n orders are still open: close or cancel them from the order board.', ['n' => $d['open_orders']]));
        }
        if ($d['visits_waiting'] > 0) {
            $mail->line('🏢 '.__(':n company visits are waiting for your answer.', ['n' => $d['visits_waiting']]));
        }
        if ($d['low_stock']) {
            $mail->line('📦 '.__('Running low').': '.implode('، ', $d['low_stock']));
        }

        return $mail
            ->action(__('Open the reports'), platform_url('app', '/reports'))
            ->line(__('You can stop this email from Settings → Messages.'))
            ->salutation(__('EgHub team'));
    }
}
