<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallScriptItem;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Prospect;
use App\Models\SalesTarget;
use App\Services\Crm\TeamStats;
use App\Support\SalesPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «فريق المبيعات» (crm.team): each person's month against the targets, the team funnel, overdue follow-ups and the
 * pay estimate; the targets of a month and (the owner) the numbers of the pay plan. The printed playbook
 * («خطة فريق المبيعات») is for the whole team (crm.view) and reads the same numbers.
 */
class SalesTeamController extends Controller
{
    public function index(Request $request, TeamStats $stats): View
    {
        $month = $this->month($request);
        $members = $stats->members();

        return view('admin.crm.team', [
            'month' => $month,
            'cards' => $members->map(fn (PlatformAdmin $m) => $stats->card($m, $month)),
            'funnel' => $stats->funnel($month),
            'overdue' => Prospect::query()->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', local_day_start())
                ->selectRaw('caller_id, count(*) as n')->groupBy('caller_id')->pluck('n', 'caller_id'),
            'plan' => SalesPlan::all(),
            'admin' => $request->user('admin'),
        ]);
    }

    /** One person's targets for a month. */
    public function targets(Request $request, PlatformAdmin $member): RedirectResponse
    {
        abort_unless(SalesPlan::seat($member) !== null, 404);
        $rules = ['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']];
        foreach (array_keys(SalesTarget::METRICS) as $metric) {
            $rules[$metric] = 'required|integer|min:0|max:5000';
        }
        $data = $request->validate($rules);
        SalesTarget::query()->updateOrCreate(['admin_id' => $member->id, 'month' => $data['month']],
            collect($data)->only(array_keys(SalesTarget::METRICS))->map(fn ($v) => (int) $v)->all());

        return back()->with('ok', __('Targets saved.'));
    }

    /** The numbers of the pay plan (the platform owner). */
    public function plan(Request $request): RedirectResponse
    {
        abort_unless($request->user('admin')->isOwner(), 403);
        $rules = [];
        foreach (array_keys(SalesPlan::EDITABLE) as $path) {
            $rules[str_replace('.', '__', $path)] = 'required|numeric|min:0|max:100000';
        }
        $data = $request->validate($rules);
        SalesPlan::save(collect($data)->mapWithKeys(fn ($v, $k) => [str_replace('__', '.', $k) => $v])->all());

        return back()->with('ok', __('Pay plan saved.'));
    }

    /** «خطة فريق المبيعات», to read on screen and print. */
    public function playbook(Request $request): View
    {
        $full = Plan::query()->where('code', 'full')->first();
        $members = app(TeamStats::class)->members();
        $month = local_at()->format('Y-m');

        return view('admin.crm.playbook', [
            'plan' => SalesPlan::all(),
            'full' => $full,
            'members' => $members,
            'month' => $month,
            'targets' => $members->mapWithKeys(fn (PlatformAdmin $m) => [$m->id => SalesPlan::targets($m, $month)]),
            'objections' => CallScriptItem::query()->active()->ordered()->where('kind', 'objection')->limit(8)->get(),
            'pitch' => CallScriptItem::query()->active()->ordered()->whereIn('kind', ['opening', 'pitch', 'closing'])->get(),
        ]);
    }

    private function month(Request $request): string
    {
        $month = (string) $request->query('month', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : local_at()->format('Y-m');
    }
}
