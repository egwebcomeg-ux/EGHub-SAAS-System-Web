<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Entitlements;
use App\Services\Reports\SalesReport;
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
        return view('dashboard.reports', [
            'report' => (new SalesReport($from, $to, $branchId))->build(),
            'from' => $from, 'to' => $to, 'preset' => $preset, 'branchId' => $branchId,
            'advanced' => Entitlements::current()->allows('advanced_reports'),
            'branches' => $request->user()->branch_id ? collect() : Branch::query()->orderBy('sort_order')->get(),
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
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'sales-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(Request $request): array
    {
        $advanced = Entitlements::current()->allows('advanced_reports');
        $preset = in_array($request->query('range'), self::PRESETS, true) ? $request->query('range') : 'today';
        if (!$advanced && !in_array($preset, ['today', 'yesterday', '7d'], true)) {
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
