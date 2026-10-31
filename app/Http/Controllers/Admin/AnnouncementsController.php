<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Plan;
use App\Models\PlatformAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «إعلانات للمطاعم» (tenants.manage): a short notice shown as a banner on the restaurant dashboards, to all of them or
 * to one plan's restaurants, between two moments (restaurant time). Every change is in the activity log.
 */
class AnnouncementsController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::query()->with('plan', 'author')->latest('id')->paginate(25),
            'announcement' => new Announcement(['level' => 'info']),
            'plans' => $this->plans(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = Announcement::query()->create([...$this->validated($request), 'created_by' => $request->user('admin')->id]);
        PlatformAuditLog::record($request->user('admin'), 'announcement.created', $announcement->title, ['id' => $announcement->id, 'plan' => $announcement->plan?->code]);

        return redirect()->route('admin.announcements')->with('ok', __('Announcement saved.'));
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.edit', ['announcement' => $announcement, 'plans' => $this->plans()]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));
        PlatformAuditLog::record($request->user('admin'), 'announcement.updated', $announcement->title, ['id' => $announcement->id, 'plan' => $announcement->plan?->code]);

        return redirect()->route('admin.announcements')->with('ok', __('Announcement saved.'));
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        PlatformAuditLog::record($request->user('admin'), 'announcement.deleted', $announcement->title, ['id' => $announcement->id]);
        $announcement->delete();

        return redirect()->route('admin.announcements')->with('ok', __('Announcement deleted.'));
    }

    /**
     * The fields, with the dates typed in restaurant time stored as UTC.
     *
     * @return array{title: string, body: string, link: ?string, level: string, plan_id: ?int, starts_at: mixed, ends_at: mixed}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:500',
            'link' => 'nullable|url:http,https|max:255',
            'level' => ['required', Rule::in(Announcement::LEVELS)],
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'starts_at' => 'nullable|date',
            'ends_at' => ['nullable', 'date', $request->filled('starts_at') ? 'after:starts_at' : 'after:now'],
        ]);

        return [
            'title' => $data['title'],
            'body' => $data['body'],
            'link' => $data['link'] ?? null,
            'level' => $data['level'],
            'plan_id' => isset($data['plan_id']) ? (int) $data['plan_id'] : null,
            'starts_at' => filled($data['starts_at'] ?? null) ? local_input($data['starts_at']) : now(),
            'ends_at' => filled($data['ends_at'] ?? null) ? local_input($data['ends_at']) : null,
        ];
    }

    /** @return Collection<int, Plan> every plan, so an announcement on a plan archived since keeps its target */
    private function plans(): Collection
    {
        return Plan::query()->orderBy('sort_order')->get(['id', 'code', 'name', 'name_en']);
    }
}
