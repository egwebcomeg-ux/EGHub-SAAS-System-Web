<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AdminSubscriptionReport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «الاشتراكات والإيرادات» (billing.manage): the subscriptions by plan and status, the MRR, who started and stopped
 * paying in a month, the paid invoices per month, what ends in the next 14 days, and the list with its CSV export.
 */
class SubscriptionsController extends Controller
{
    public function index(Request $request, AdminSubscriptionReport $report): View
    {
        $filters = AdminSubscriptionReport::filters($request);

        return view('admin.subscriptions', [
            'filters' => $filters,
            'totals' => $report->totals(),
            'mrr' => $report->mrr(),
            'byPlan' => $report->byPlan(),
            'movement' => $report->movement($filters['month']),
            'revenue' => $report->revenueByMonth(),
            'expiring' => $report->expiring($filters['plan']),
            'subscriptions' => $report->listing($filters)->paginate(50)->withQueryString(),
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'name_en']),
        ]);
    }

    /** The list with the page's plan and status filters, as a CSV Excel opens in Arabic (UTF-8 BOM). */
    public function export(Request $request, AdminSubscriptionReport $report): StreamedResponse
    {
        $rows = $report->listing(AdminSubscriptionReport::filters($request));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Restaurant'), __('Link'), __('Plan'), __('Status'), __('Cycle'), __('Ends'), __('Period value'), __('Monthly value')]);
            $rows->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $sub) {
                    /** @var Subscription $sub */
                    fputcsv($out, csv_row([
                        (string) $sub->tenant?->name, $sub->tenant_id, (string) $sub->plan?->displayName(), $sub->statusLabel(),
                        cycle_label((int) $sub->cycle_months), $sub->endsAt() ? local_today($sub->endsAt()) : '',
                        (float) $sub->period_value, AdminSubscriptionReport::monthlyValue($sub),
                    ]));
                }
            });
            fclose($out);
        }, 'subscriptions-'.local_today().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
