<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\BusyMode;
use App\Services\Reports\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The restaurant's numbers as Hoba (App\Services\DashboardAssistant) may tell them: worked out here by the reports'
 * own code (App\Services\Reports\SalesReport) for today, yesterday, the last 7 days and this month, plus what is
 * going on right now. Only for staff who may open the reports while the plan has them; staff tied to a branch get
 * their branch only, as on the reports page. The model never queries anything: it reads this text.
 */
final class DashboardFigures
{
    public const CACHE_SECONDS = 300;

    public static function allowed(User $user): bool
    {
        return $user->can('reports.view') && Entitlements::current()->allows('daily_reports');
    }

    /** The numbers as reference text, or null when this user may not see them. */
    public static function text(User $user): ?string
    {
        if (! self::allowed($user)) {
            return null;
        }
        $branch = $user->branch_id ? (int) $user->branch_id : null;
        $key = 'hoba:figures:'.tenant()->getTenantKey().':'.($branch ?? 'all').':'.app()->getLocale();

        // the sums are kept a few minutes; what is going on right now never is
        return Cache::remember($key, self::CACHE_SECONDS, fn () => self::build($branch))."\n".self::now($branch);
    }

    private static function now(?int $branch): string
    {
        $open = Order::query()->whereIn('status', ['new', 'preparing', 'ready', 'on_way'])
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->where('created_at', '>=', local_day_start(local_today()))->count();
        $busy = BusyMode::state();

        return "- right now: {$open} orders still open today; taking orders: ".(Settings::get('ordering_enabled') === '1' ? 'on' : 'off')
            .'; busy mode: '.($busy ? 'on until '.$busy['until'] : 'off');
    }

    private static function build(?int $branch): string
    {
        $today = CarbonImmutable::parse(local_today());
        $currency = Settings::get(app()->getLocale() === 'ar' ? 'currency' : 'currency_en') ?: Settings::get('currency');
        $periods = [
            'today ('.$today->toDateString().')' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last 7 days (with today)' => [$today->subDays(6), $today],
            'this month so far' => [$today->startOfMonth(), $today],
        ];
        $out = ['NUMBERS OF THE PLACE'.($branch ? ' (this person\'s branch only)' : '').", in {$currency}, from the reports (sales = orders not cancelled, net of refunds):"];
        $week = null;
        foreach ($periods as $label => [$from, $to]) {
            $r = (new SalesReport($from, $to, $branch))->build();
            $week = str_starts_with($label, 'last 7') ? $r : $week;
            $methods = implode(', ', array_map(fn (array $m) => $m['key'].' '.money($m['total']), $r['by_method']));
            $types = implode(', ', array_map(fn (array $t) => ($t['key'] ?: '-').' '.$t['count'], $r['by_type']));
            $out[] = "- {$label}: sales ".money($r['revenue']).", orders {$r['orders']}, average ".money($r['average'])
                .", cancelled {$r['cancelled']}, discounts ".money($r['discounts']).', unpaid '.money($r['unpaid'])
                .($methods !== '' ? "; paid by: {$methods}" : '').($types !== '' ? "; order types: {$types}" : '');
        }
        if ($week) {
            $top = array_slice($week['top_items'], 0, 8);
            $out[] = '- best sellers, last 7 days: '.($top ? implode(', ', array_map(fn (array $i) => "{$i['name']} ×{$i['qty']} (".money($i['revenue']).')', $top)) : 'no sales');
            $hours = $week['hours'];
            arsort($hours);
            $busy = array_slice(array_filter($hours), 0, 3, true);
            $out[] = '- busiest hours, last 7 days: '.($busy ? implode(', ', array_map(fn ($h, $n) => sprintf('%02d:00 (%d orders)', $h, $n), array_keys($busy), $busy)) : 'none');
        }

        return implode("\n", $out);
    }
}
