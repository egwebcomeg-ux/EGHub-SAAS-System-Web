<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TvAlert;
use App\Models\TvGroup;
use App\Models\TvScreen;
use App\Services\Screens\ScreenAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «رسالة عاجلة» from the phone: one line shown on the chosen screens on top of whatever plays (a band at the top or
 * the whole screen), for some minutes or until cleared. The screens pick it up on their fast check
 * (ScreenAlerts::POLL_SECONDS). Staff tied to a branch send to and clear on that branch's screens only.
 */
class TvAlertsController extends Controller
{
    public function index(Request $request): View
    {
        $screens = TvScreen::query()->manageableBy($request->user())->with(['branch', 'group'])->orderBy('id')->get();
        $ids = $screens->pluck('id')->all();

        return view('dashboard.tv-alerts', [
            'screens' => $screens,
            'groups' => TvGroup::query()->whereIn('id', $screens->pluck('tv_group_id')->filter()->unique())->orderBy('name')->get(),
            'alerts' => TvAlert::query()->active()->latest('id')->get()->filter(fn (TvAlert $a) => $this->reaches($a, $ids))->values(),
            'poll' => ScreenAlerts::POLL_SECONDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text' => 'required|string|max:200',
            'style' => ['required', Rule::in(TvAlert::STYLES)],
            'minutes' => ['required', 'integer', Rule::in(TvAlert::DURATIONS)],
            'screen_ids' => 'required|array|min:1|max:500',
            'screen_ids.*' => 'integer',
        ], ['screen_ids.required' => __('Tick the screens that show the message.')]);
        $ids = TvScreen::query()->manageableBy($request->user())->whereIn('id', $data['screen_ids'])->pluck('id')->all();
        if ($ids === []) {
            return back()->withInput()->withErrors(['screen_ids' => __('Tick the screens that show the message.')]);
        }
        TvAlert::query()->create([
            'user_id' => $request->user()->id,
            'text' => trim($data['text']),
            'style' => $data['style'],
            'screen_ids' => array_values(array_map('intval', $ids)),
            'ends_at' => (int) $data['minutes'] > 0 ? now()->addMinutes((int) $data['minutes']) : null,
        ]);

        return redirect()->route('dash.tv.alerts')->with('ok', __('Sent. The screens show it within :s seconds.', ['s' => ScreenAlerts::POLL_SECONDS + 2]));
    }

    public function clear(Request $request, TvAlert $alert): RedirectResponse
    {
        $ids = TvScreen::query()->manageableBy($request->user())->pluck('id')->all();
        abort_unless($request->user()->branch_id === null || $this->reachesOnly($alert, $ids), 404);
        $alert->update(['cleared_at' => now()]);

        return back()->with('ok', __('Message cleared. The screens go back to their playlist within seconds.'));
    }

    /** @param list<int> $ids */
    private function reaches(TvAlert $alert, array $ids): bool
    {
        return array_intersect(array_map('intval', $alert->screen_ids ?? []), $ids) !== [];
    }

    /** Every screen of the message is one the user manages. @param list<int> $ids */
    private function reachesOnly(TvAlert $alert, array $ids): bool
    {
        return array_diff(array_map('intval', $alert->screen_ids ?? []), $ids) === [];
    }
}
