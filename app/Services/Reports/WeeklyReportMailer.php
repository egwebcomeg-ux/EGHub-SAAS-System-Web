<?php

namespace App\Services\Reports;

use App\Models\Tenant;
use App\Notifications\WeeklyReport;
use App\Services\Entitlements;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Sends last week's numbers (Monday to Sunday) to the owners of every restaurant that turned the weekly report on
 * and has the advanced reports. Run on Monday morning by egmenu:weekly-report.
 */
final class WeeklyReportMailer
{
    /** @return int restaurants emailed */
    public function run(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            tenancy()->initialize($tenant);
            try {
                Settings::flush();
                if (Settings::get('weekly_report_email') === '1' && Entitlements::for($tenant)->allows('advanced_reports')) {
                    $sent += $this->send($tenant, $today) ? 1 : 0;
                }
            } finally {
                tenancy()->end();
                Settings::flush();
            }
        }

        return $sent;
    }

    /** Builds and sends the report of the week before $today for the current restaurant. */
    public function send(Tenant $tenant, CarbonImmutable $today): bool
    {
        $owners = $tenant->owners();
        if ($owners->isEmpty()) {
            return false;
        }
        $from = $today->subWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $to = $from->addDays(6);
        $advanced = new AdvancedReport($from, $to);
        $sales = (new SalesReport($from, $to))->build();

        Notification::send($owners, new WeeklyReport([
            'restaurant' => tr(Settings::get('brand_name'), Settings::get('brand_name_en')) ?: (string) $tenant->name,
            'from' => $from->format('Y/m/d'),
            'to' => $to->format('Y/m/d'),
            'comparison' => $advanced->comparison(),
            'top_items' => array_map(fn ($i) => ['name' => $i['name'], 'qty' => $i['qty']], array_slice($sales['top_items'], 0, 5)),
            'low' => array_map(fn ($i) => ['name' => $i['name'], 'views' => $i['views'], 'orders' => $i['orders']], array_slice($advanced->items()['low'], 0, 3)),
        ]));

        return true;
    }
}
