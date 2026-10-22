<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\MenuVisit;
use App\Services\Entitlements;
use App\Services\Orders\DeliveryService;
use App\Services\Orders\DriverStats;
use App\Services\Reports\AdvancedReport;
use App\Services\Reports\SalesReport;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales reports. Daily reports (Pro): today / yesterday / last 7 days. Advanced (Business): any range up to a year,
 * last 30 days, this month, per-branch filter and comparison, CSV export. Staff tied to a branch see only it.
 */
class ReportsController extends Controller
{
    public const PRESETS = ['today', 'yesterday', '7d', '30d', 'month', 'custom'];

    public function index(Request $request): View
    {
        [$from, $to, $preset] = $this->range($request);
        $branchId = $this->branch($request);
        $advanced = Entitlements::current()->allows('advanced_reports');

        return view('dashboard.reports', [
            'report' => (new SalesReport($from, $to, $branchId))->build(),
            'from' => $from, 'to' => $to, 'preset' => $preset, 'branchId' => $branchId,
            'advanced' => $advanced,
            'extra' => $advanced ? (new AdvancedReport($from, $to, $branchId))->build() : null,
            'weeklyEmail' => Settings::get('weekly_report_email') === '1',
            'visits' => MenuVisit::query()->whereBetween('day', [$from->startOfDay(), $to->endOfDay()])
                ->selectRaw('COALESCE(SUM(views), 0) as views, COALESCE(SUM(scans), 0) as scans')->first(),
            'topTables' => DiningTable::query()->where('scans_count', '>', 0)->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->orderByDesc('scans_count')->limit(5)->get(['name', 'scans_count', 'last_scanned_at']),
            'branches' => $request->user()->branch_id ? collect() : Branch::query()->orderBy('sort_order')->get(),
            // delivery drivers in the range (plan feature of delivery): only the drivers who delivered or failed something
            'delivery' => Entitlements::current()->allows('delivery_zones|online_ordering')
                ? (new DriverStats($from->startOfDay(), $to->endOfDay(), $branchId))->active(DeliveryService::driverQuery()->orderBy('name')->get())
                : [],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Entitlements::current()->allows('advanced_reports'), 403);
        [$from, $to] = $this->range($request);
        // the file is streamed after the request returns: the report pins the tenant through the query it built now
        $rows = iterator_to_array((new SalesReport($from, $to, $this->branch($request)))->rows(), false);
        $header = [__('Order'), __('Date'), __('Branch'), __('Type'), __('Source'), __('Table'), __('Status'), __('Payment'),
            __('Payment method'), __('Subtotal'), __('Discount'), __('Service'), __('Tax'), __('Total')];

        return response()->streamDownload(function () use ($rows, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, csv_row($row));
            }
            fclose($out);
        }, 'sales-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One table of the advanced reports as CSV (same range and branch as the page). */
    public function exportTable(Request $request, string $table): StreamedResponse
    {
        abort_unless(Entitlements::current()->allows('advanced_reports'), 403);
        [$from, $to] = $this->range($request);
        $report = new AdvancedReport($from, $to, $this->branch($request));
        $pct = fn (?float $v) => $v === null ? '' : $v;
        [$header, $rows] = match ($table) {
            'comparison' => [[__('Figure'), __('This period'), __('Previous period'), __('Change %')], collect($report->comparison())
                ->map(fn ($r, $k) => [AdvancedReport::kpiLabel($k), $r['current'], $r['previous'], $pct($r['delta'])])->values()->all()],
            'items' => [[__('Item'), __('Opened'), __('Quantity ordered'), __('Orders'), __('Opened → ordered %')],
                array_map(fn ($r) => [$r['name'], $r['views'], $r['qty'], $r['orders'], $pct($r['conversion'])], $report->items()['rows'])],
            'heatmap' => (function () use ($report) {
                $map = $report->heatmap();

                return [array_merge([__('Day')], array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23))),
                    array_map(fn ($d) => array_merge([AdvancedReport::dayName($d)], $map['cells'][$d]), $map['days'])];
            })(),
            'branches' => [[__('Branch'), __('Sales'), __('Orders'), __('Average order'), __('Share %')],
                array_map(fn ($r) => [$r['name'], $r['revenue'], $r['orders'], $r['average'], $r['share']], $report->branches())],
            'sources', 'types' => [[$table === 'sources' ? __('Source') : __('Type'), __('Orders'), __('Sales'), __('Share %')],
                array_map(fn ($r) => [$r['label'], $r['orders'], $r['revenue'], $r['share']], $report->split($table === 'sources' ? 'source' : 'type'))],
            'guests' => [[__('Week of'), __('New guests'), __('Returning guests')],
                array_map(fn ($r) => [$r['week'], $r['new'], $r['returning']], $report->weeks())],
            default => abort(404),
        };

        return response()->streamDownload(function () use ($rows, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, csv_row($row));
            }
            fclose($out);
        }, $table.'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(Request $request): array
    {
        $advanced = Entitlements::current()->allows('advanced_reports');
        $preset = in_array($request->query('range'), self::PRESETS, true) ? $request->query('range') : 'today';
        if (! $advanced && ! in_array($preset, ['today', 'yesterday', '7d'], true)) {
            $preset = 'today';
        }
        $today = CarbonImmutable::today();

        return match ($preset) {
            'yesterday' => [$today->subDay(), $today->subDay(), $preset],
            '7d' => [$today->subDays(6), $today, $preset],
            '30d' => [$today->subDays(29), $today, $preset],
            'month' => [$today->startOfMonth(), $today, $preset],
            'custom' => $this->custom($request, $today),
            default => [$today, $today, 'today'],
        };
    }

    private function custom(Request $request, CarbonImmutable $today): array
    {
        $from = rescue(fn () => CarbonImmutable::parse((string) $request->query('from')), $today, false);
        $to = rescue(fn () => CarbonImmutable::parse((string) $request->query('to')), $today, false);
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        return [$from->startOfDay(), $to->startOfDay(), 'custom'];
    }

    private function branch(Request $request): ?int
    {
        if ($request->user()->branch_id) {
            return $request->user()->branch_id;
        }

        return Entitlements::current()->allows('advanced_reports') && $request->filled('branch') ? (int) $request->query('branch') : null;
    }
}
