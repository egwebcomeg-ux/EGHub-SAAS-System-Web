<?php

namespace App\Notifications;

use App\Services\Reports\AdvancedReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Monday morning: last week's numbers of the restaurant compared with the week before (advanced reports). */
class WeeklyReport extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{restaurant:string, from:string, to:string, comparison:array<string, array{current:float, previous:float, delta:?float}>, top_items:list<array{name:string, qty:int}>, low:list<array{name:string, views:int, orders:int}>}  $report
     */
    public function __construct(public array $report) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->report;
        $mail = (new MailMessage)
            ->subject(__('Your week at :restaurant', ['restaurant' => $r['restaurant']]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Here are the numbers of :from – :to, compared with the week before.', ['from' => $r['from'], 'to' => $r['to']]));

        foreach ($r['comparison'] as $key => $c) {
            $money = in_array($key, ['revenue', 'average'], true);
            $value = $money ? money($c['current']).' '.__('EGP') : (string) (int) $c['current'];
            $change = $c['delta'] === null ? '' : ' ('.($c['delta'] > 0 ? '+' : '').$c['delta'].'%)';
            $mail->line('**'.AdvancedReport::kpiLabel($key).':** '.$value.$change);
        }
        if ($r['top_items']) {
            $mail->line('**'.__('Best sellers').':** '.collect($r['top_items'])->map(fn ($i) => $i['name'].' ×'.$i['qty'])->implode('، '));
        }
        if ($r['low']) {
            $mail->line('**'.__('Opened a lot, ordered rarely').':** '.collect($r['low'])->map(fn ($i) => $i['name'].' ('.$i['views'].' / '.$i['orders'].')')->implode('، '));
        }

        return $mail
            ->action(__('Open the reports'), platform_url('app', '/reports?range=7d'))
            ->line(__('You can stop this email from Settings → Weekly report.'))
            ->salutation(__('EgMenu team'));
    }
}
