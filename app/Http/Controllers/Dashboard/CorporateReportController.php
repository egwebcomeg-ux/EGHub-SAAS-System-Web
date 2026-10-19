<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CorporateContract;
use App\Models\CorporateVoucherRedemption;
use App\Models\User;
use App\Services\Reports\CorporateReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "تقرير الفاوتشرات" (feature corporate_vouchers, permission contracts.manage or reports.view): what was issued,
 * redeemed, lost, expired and is still owed to the companies' staff, by contract, branch, cashier and day; vouchers
 * ending soon and the exceptions log. CSV / Excel exports of the summary and of every use. Staff tied to a branch
 * see only it.
 */
class CorporateReportController extends Controller
{
    public const PRESETS = ['today', 'week', 'month', 'custom'];

    public function index(Request $request): View
    {
        $report = $this->report($request);

        return view('dashboard.corporate.report', [
            'r' => $report->build(), 'report' => $report, 'preset' => $this->preset($request),
            'contracts' => CorporateContract::query()->orderBy('name')->get(['id', 'name']),
            'branches' => $request->user()->branch_id ? collect() : Branch::query()->orderBy('sort_order')->get(['id', 'name', 'name_en']),
            'cashiers' => User::query()->whereIn('id', CorporateVoucherRedemption::query()->whereNotNull('user_id')->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The summary (what=summary) or every use (what=uses) as CSV or Excel. */
    public function export(Request $request): Response
    {
        $request->validate(['what' => ['nullable', Rule::in(['summary', 'uses'])], 'format' => ['nullable', Rule::in(['csv', 'xlsx'])]]);
        $report = $this->report($request);
        $name = 'vouchers-'.($request->query('what') === 'uses' ? 'uses' : 'report').'-'.$report->from->format('Ymd').'-'.$report->to->format('Ymd');

        if ($request->query('what') === 'uses') {
            $rows = [[__('Date'), __('Company'), __('Serial'), __('Branch'), __('Order'), __('Amount'), __('Lost (one use)'), __('Exception'), __('Cashier')]];
            foreach ($report->rows() as $row) {
                $rows[] = $row;
            }

            return CorporateContractsController::download($rows, $name, (string) $request->query('format'), __('Uses'));
        }

        $r = $report->build();
        $k = $r['kpis'];
        $rows = [
            [__('Figure'), __('Value')],
            [__('Value issued'), $k['issued']], [__('Value redeemed'), $k['redeemed']], [__('Of it on paper bills'), $k['paper']],
            [__('Still outstanding'), $k['outstanding']], [__('Lost (one use)'), $k['lost']], [__('Expired unused'), $k['expired']],
            [__('Uses'), $k['uses']], [__('Vouchers used'), $k['used_vouchers']], [__('Vouchers not used yet'), $k['unused_vouchers']],
            [__('Exceptions approved'), $k['exceptions']], [__('Exceptions value'), $k['exceptions_value']],
            [''],
            [__('Company'), __('Vouchers'), __('Issued value'), __('Redeemed'), __('Remaining'), __('Lost (one use)'), __('Expired'), __('Usage %'), __('Payment')],
        ];
        foreach ($r['contracts'] as $c) {
            $rows[] = [$c['contract']->name, $c['vouchers'], $c['issued'], $c['redeemed'], $c['remaining'], $c['lost'], $c['expired'], $c['usage'], $c['contract']->paidLabel()];
        }

        return CorporateContractsController::download($rows, $name, (string) $request->query('format'), __('Vouchers report'));
    }

    private function report(Request $request): CorporateReport
    {
        [$from, $to] = $this->range($request);
        $branch = $request->user()->branch_id ?: ($request->filled('branch') ? (int) $request->query('branch') : null);
        $mode = in_array($request->query('mode'), array_keys(CorporateContract::MODES), true) ? (string) $request->query('mode') : null;

        return new CorporateReport($from, $to, $branch, $request->filled('contract') ? (int) $request->query('contract') : null,
            $request->filled('cashier') ? (int) $request->query('cashier') : null, $mode);
    }

    private function preset(Request $request): string
    {
        return in_array($request->query('range'), self::PRESETS, true) ? (string) $request->query('range') : 'month';
    }

    /** @return array{0:CarbonImmutable, 1:CarbonImmutable} */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::today();

        return match ($this->preset($request)) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(CarbonImmutable::SATURDAY), $today],
            'custom' => $this->custom($request, $today),
            default => [$today->startOfMonth(), $today],
        };
    }

    /** @return array{0:CarbonImmutable, 1:CarbonImmutable} at most a year */
    private function custom(Request $request, CarbonImmutable $today): array
    {
        $from = rescue(fn () => CarbonImmutable::parse((string) $request->query('from')), $today, false)->startOfDay();
        $to = rescue(fn () => CarbonImmutable::parse((string) $request->query('to')), $today, false)->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        return [$from, $to];
    }
}
