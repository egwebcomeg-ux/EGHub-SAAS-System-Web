<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\SalesCall;
use App\Services\Crm\SalesReport;
use App\Services\Crm\TeamStats;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «تقارير المبيعات» (crm.manage): a period on screen, on paper and as a CSV file. A sales person gets their own work;
 * crm.team picks the whole team or one person. Also the printed file of one restaurant or place.
 */
class SalesReportsController extends Controller
{
    public function index(Request $request, SalesReport $report, TeamStats $stats): View
    {
        return view('admin.crm.reports.index', $this->data($request, $report) + ['team' => $this->team($request, $stats)]);
    }

    public function print(Request $request, SalesReport $report): View
    {
        return view('admin.crm.reports.print', $this->data($request, $report));
    }

    public function export(Request $request, SalesReport $report): StreamedResponse
    {
        $data = $this->data($request, $report);
        $rows = $data['report'];
        $sheet = [[__('Sales report'), $data['from'].' → '.$data['to'], $data['person']?->name ?? __('All the team')], []];
        foreach ([
            [__('Sign-ups'), [__('New') => 'new', __('Talked to') => 'contacted', __('Interested') => 'interested', __('Subscribed') => 'won', __('Not interested') => 'lost', __('Overdue follow-ups') => 'overdue'], $rows['leads']],
            [__('Places'), [__('Added') => 'added', __('Contacted') => 'contacted', __('WhatsApp offers') => 'offers', __('Visits booked') => 'appointments', __('Visits done') => 'visits', __('Signed up') => 'trials', __('Subscribed') => 'won'], $rows['places']],
        ] as [$title, $labels, $values]) {
            $sheet[] = [$title];
            foreach ($labels as $label => $key) {
                $sheet[] = [$label, $values[$key]];
            }
            $sheet[] = [];
        }
        $sheet[] = [__('Phone calls')];
        foreach ([__('All calls') => 'total', __('Outgoing') => 'outgoing', __('Incoming') => 'incoming', __('Talked') => 'talked', __('Missed') => 'missed', __('Missed, not returned') => 'not_returned', __('Waiting for a summary') => 'unsummarized'] as $label => $key) {
            $sheet[] = [$label, $rows['calls'][$key]];
        }
        $sheet[] = [__('Talk time'), SalesCall::formatDuration($rows['calls']['seconds'])];
        $sheet[] = [__('Average call'), SalesCall::formatDuration($rows['calls']['average'])];
        $sheet[] = [];
        $sheet[] = [__('Team member'), __('Phone calls'), __('Talk time'), __('Missed, not returned'), __('Logged follow-ups'), __('Visits booked'), __('Visits done'), __('Subscribed'), __('Overdue follow-ups')];
        foreach ($rows['people'] as $person) {
            $sheet[] = [$person['admin']->name, $person['calls'], SalesCall::formatDuration($person['seconds']), $person['not_returned'], $person['logged'], $person['appointments'], $person['visits'], $person['won'], $person['overdue']];
        }
        $sheet[] = [];
        $sheet[] = [__('Why they said no')];
        foreach ($rows['lost'] as $reason) {
            $sheet[] = [$reason['label'], $reason['count']];
        }

        return response()->streamDownload(function () use ($sheet) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($sheet as $row) {
                fputcsv($out, csv_row($row));
            }
            fclose($out);
        }, 'sales-report-'.$data['from'].'-'.$data['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function leadFile(Lead $lead): View
    {
        abort_unless($lead->tenant, 404);

        return view('admin.crm.reports.file', [
            'title' => $lead->tenant->name,
            'details' => array_filter([
                __('Stage') => $lead->stageLabel(),
                __('Plan') => $lead->tenant->subscription?->plan?->displayName(),
                __('Assigned to') => $lead->assignee?->name,
                __('Next follow-up') => $lead->next_follow_up_at ? local_time($lead->next_follow_up_at, 'Y/m/d h:i A') : null,
                __('Notes') => $lead->notes,
            ]),
            'events' => $lead->events()->with('admin')->latest('id')->limit(200)->get(),
            'calls' => SalesCall::query()->with('admin')->where('lead_id', $lead->id)->latest('started_at')->limit(200)->get(),
            'appointments' => collect(),
        ]);
    }

    public function prospectFile(Request $request, Prospect $prospect): View
    {
        abort_unless($prospect->isVisibleTo($request->user('admin')), 404);

        return view('admin.crm.reports.file', [
            'title' => $prospect->name,
            'details' => array_filter([
                __('Stage') => $prospect->stageLabel(),
                __('Kind') => $prospect->categoryLabel(),
                __('Phone') => $prospect->phone,
                __('Address') => $prospect->address ?: $prospect->place(),
                __('Owner') => $prospect->owner_name,
                __('Caller') => $prospect->caller?->name,
                __('Field rep') => $prospect->field?->name,
                __('Next follow-up') => $prospect->next_follow_up_at ? local_time($prospect->next_follow_up_at, 'Y/m/d h:i A') : null,
                __('Notes') => $prospect->notes,
            ]),
            'events' => $prospect->events()->with('admin')->latest('id')->limit(200)->get(),
            'calls' => SalesCall::query()->with('admin')->where('prospect_id', $prospect->id)->latest('started_at')->limit(200)->get(),
            'appointments' => $prospect->appointments()->with('field')->latest('scheduled_at')->limit(50)->get(),
        ]);
    }

    /** @return array{report: array<string, mixed>, from: string, to: string, person: ?PlatformAdmin, query: array<string, mixed>} */
    private function data(Request $request, SalesReport $report): array
    {
        $viewer = $request->user('admin');
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'admin' => 'nullable|integer',
        ]);
        $from = $data['from'] ?? local_at()->startOfMonth()->toDateString();
        $to = $data['to'] ?? local_at()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // only who oversees the team may look at someone else
        $person = $viewer->allows('crm.team')
            ? (! empty($data['admin']) ? PlatformAdmin::query()->find($data['admin']) : null)
            : $viewer;

        return [
            'report' => $report->run(local_day_start($from), Carbon::parse(local_day_start($to))->addDay(), $person),
            'from' => $from,
            'to' => $to,
            'person' => $person,
            'query' => array_filter(['from' => $from, 'to' => $to, 'admin' => $person && $viewer->allows('crm.team') ? $person->id : null]),
        ];
    }

    /** @return Collection<int, PlatformAdmin> */
    private function team(Request $request, TeamStats $stats)
    {
        return $request->user('admin')->allows('crm.team') ? $stats->members() : collect();
    }
}
