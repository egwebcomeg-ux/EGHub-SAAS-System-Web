<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PosTerminal;
use App\Services\Printing\PrintJobs;
use App\Services\Reports\ZReport;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * End of day (Z) report: one branch (staff tied to a branch see only it), optionally one terminal, one day. On
 * screen, printed on 80mm paper or A4, or downloaded as CSV / Excel.
 */
class ZReportController extends Controller
{
    public function show(Request $request): Response
    {
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d', 'branch' => 'nullable|integer', 'terminal' => 'nullable|integer',
            'format' => ['nullable', Rule::in(['80mm', 'a4', 'csv', 'xlsx', 'job'])],
        ]);
        $day = $request->filled('date') ? CarbonImmutable::createFromFormat('Y-m-d', (string) $request->query('date'))->startOfDay() : CarbonImmutable::today();
        if ($day->isFuture()) {
            $day = CarbonImmutable::today();
        }
        $branch = $request->user()->branch_id
            ? Branch::query()->findOrFail($request->user()->branch_id)
            : ($request->filled('branch') ? Branch::query()->findOrFail((int) $request->query('branch')) : PosController::branch($request));
        $terminals = PosTerminal::query()->where('branch_id', $branch->id)->orderBy('code')->get();
        $terminal = $request->filled('terminal') ? $terminals->firstWhere('id', (int) $request->query('terminal')) : null;
        $report = new ZReport($day, $branch->id, $terminal?->id);
        $z = $report->build();
        $format = (string) $request->query('format', '');

        if ($format === 'job') { // the EgHub cashier app prints it on its own printer
            return response()->json(['job' => PrintJobs::zReport($z, $branch, $day, $terminal?->label())]);
        }
        if (in_array($format, ['csv', 'xlsx'], true)) {
            $name = 'z-report-'.$day->format('Ymd').'-'.$branch->slug.($terminal ? '-'.$terminal->code : '');

            return CorporateContractsController::download($report->rows($z), $name, $format, __('End of day (Z)'));
        }

        return response()->view($format === '' ? 'dashboard.pos.zreport' : 'dashboard.pos.zreport-print', [
            'z' => $z, 'day' => $day, 'branch' => $branch, 'terminal' => $terminal, 'terminals' => $terminals, 'paper' => $format,
            'branches' => $request->user()->branch_id ? collect([$branch]) : Branch::query()->orderBy('sort_order')->get(), 'settings' => Settings::all(),
        ]);
    }
}
