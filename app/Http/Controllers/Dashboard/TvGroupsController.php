<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TvGroup;
use App\Models\TvScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «مجموعات الشاشات»: screens that share one playlist. The owner names a group, ticks its screens (any branch) and
 * edits its slides once (TvPlaylistController::*GroupSlide); every screen of the group plays them with its own branch
 * prices and look, unless that screen keeps its own playlist. Groups are restaurant-wide, so staff tied to one branch
 * do not manage them (they still copy playlists between their branch's screens).
 */
class TvGroupsController extends Controller
{
    public static function authorizeGroups(Request $request): void
    {
        abort_if($request->user()->branch_id !== null, 403);
    }

    public function store(Request $request): RedirectResponse
    {
        self::authorizeGroups($request);
        $data = $request->validate(['name' => 'required|string|max:60']);
        $group = TvGroup::query()->create(['name' => $data['name'], 'zone_layout' => 'full']);

        return redirect()->route('dash.tv.groups.show', $group)->with('ok', __('Group created. Tick its screens, then build its playlist.'));
    }

    public function show(Request $request, TvGroup $group): View
    {
        self::authorizeGroups($request);
        $editor = app(TvPlaylistController::class)->editorData($request, $group);

        return view('dashboard.tv-group', $editor + [
            'group' => $group,
            'members' => $group->screens()->with('branch')->get(),
        ]);
    }

    /** Name and which screens follow the group. */
    public function update(Request $request, TvGroup $group): RedirectResponse
    {
        self::authorizeGroups($request);
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'screen_ids' => 'nullable|array|max:500',
            'screen_ids.*' => 'integer',
        ]);
        DB::transaction(function () use ($group, $data) {
            $group->update(['name' => $data['name']]);
            // only this restaurant's screens (tenant scope): ticked ones join and follow the group, the others leave it
            $ids = TvScreen::query()->whereIn('id', array_map('intval', $data['screen_ids'] ?? []))->pluck('id');
            $leaving = TvScreen::query()->where('tv_group_id', $group->id)->whereNotIn('id', $ids)->get();
            $leaving->each(fn (TvScreen $s) => $s->update(['tv_group_id' => null, 'own_playlist' => false]));
            // a screen that joins follows the group; one already in it keeps its choice (own playlist or the group's)
            TvScreen::query()->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('tv_group_id')->orWhere('tv_group_id', '!=', $group->id))->get()
                ->each(fn (TvScreen $s) => $s->update(['tv_group_id' => $group->id, 'own_playlist' => false]));
            $group->touch();
        });

        return redirect()->route('dash.tv.groups.show', $group)->with('ok', __('Group saved.'));
    }

    /** One area or two for every screen of the group. */
    public function zones(Request $request, TvGroup $group): RedirectResponse
    {
        self::authorizeGroups($request);
        $group->update($request->validate(['zone_layout' => ['required', Rule::in(TvScreen::ZONE_LAYOUTS)]]));

        return redirect()->to(route('dash.tv.groups.show', $group).'#playlist')->with('ok', __('Group saved.'));
    }

    /** The group and its playlist go; its screens play their own playlists again. */
    public function destroy(Request $request, TvGroup $group): RedirectResponse
    {
        self::authorizeGroups($request);
        DB::transaction(function () use ($group) {
            TvScreen::query()->where('tv_group_id', $group->id)->get()
                ->each(fn (TvScreen $s) => $s->update(['tv_group_id' => null, 'own_playlist' => false]));
            $group->slides()->delete();
            $group->delete();
        });

        return redirect()->route('dash.devices', ['tab' => 'tv'])->with('ok', __('Group deleted. Its screens play their own playlists again.'));
    }
}
