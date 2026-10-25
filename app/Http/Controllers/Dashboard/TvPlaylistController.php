<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\TvGroup;
use App\Models\TvMedia;
use App\Models\TvScreen;
use App\Models\TvSlide;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\ItemVideo;
use App\Services\Screens\ScreenPairing;
use App\Support\ScreenDesigns;
use App\Support\ScreenShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One TV screen's page in the dashboard: how it looks (orientation, colours, language, ticker, hours, QR corner), its
 * playlist of slides (add, edit, reorder, delete, split into areas, copy to other screens), the restaurant's screen
 * media (pictures and videos) and the paired device (status, what it shows now, «تحديث الشاشة», «فك الربط»).
 * A group's shared playlist (TvGroup) is edited with the same slide actions (the *Group* methods).
 * Owner and manager (menu.manage), screens in the plan (feature tv_screens). Every id posted here is checked inside
 * the current restaurant, and staff tied to a branch only reach that branch's screens (TvScreen::manageableBy).
 */
class TvPlaylistController extends Controller
{
    public function edit(Request $request, TvScreen $screen): View
    {
        $this->manage($request, $screen);
        $rights = Entitlements::current();
        $screen->load(['branch', 'group']);
        $owner = $screen->playlistOwner();

        return view('dashboard.tv-screen', $this->editorData($request, $owner) + [
            'screen' => $screen,
            'groups' => $request->user()->branch_id ? collect() : TvGroup::query()->orderBy('name')->get(),
            'limit' => $rights->limit('screens'),
            'paired' => ScreenPairing::pairedCount(),
        ]);
    }

    /** The screen's look, hours, group and QR corner. */
    public function settings(Request $request, TvScreen $screen): RedirectResponse
    {
        $this->manage($request, $screen);
        $tenant = tenant()->getTenantKey();
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'orientation' => ['required_without:shape', 'nullable', Rule::in(TvScreen::ORIENTATIONS)],
            'theme' => ['required', Rule::in(TvScreen::THEMES)],
            'language' => ['nullable', Rule::in(TvScreen::LANGUAGES)],
            'ticker_text' => 'nullable|string|max:300',
            'tv_group_id' => ['nullable', Rule::exists('tv_groups', 'id')->where('tenant_id', $tenant)],
            'playlist' => ['nullable', Rule::in(['group', 'own'])],
        ] + ScreenShape::rules() + $this->hourRules());
        // staff tied to a branch keep the screen in their branch and do not move it between groups
        $user = $request->user();
        if ($user->branch_id) {
            $data['branch_id'] = $user->branch_id;
        }
        // «مقاس الشاشة واتجاهها» (shape, turn, size, distance, text size) when the form has it, else the plain orientation
        $shape = $request->has('shape') ? ScreenShape::attributes($data, $request->boolean('text_scale_manual')) : [];
        $data['orientation'] = $shape['orientation'] ?? $data['orientation'];
        $group = $user->branch_id || ! $request->has('tv_group_id') ? [] : [
            'tv_group_id' => $data['tv_group_id'] ?? null,
            'own_playlist' => ($data['tv_group_id'] ?? null) !== null && ($data['playlist'] ?? 'group') === 'own',
        ];
        $screen->update([
            'name' => $data['name'], 'branch_id' => $data['branch_id'] ?? null, 'orientation' => $data['orientation'], 'theme' => $data['theme'],
            'language' => (string) ($data['language'] ?? ''), 'ticker_text' => $data['ticker_text'] ?? null,
            'is_active' => $request->boolean('is_active'), 'show_qr' => $request->boolean('show_qr'),
        ] + $group + $shape + $this->hours($data));

        return back()->with('ok', __('Screen saved.'));
    }

    /** One area or two: the screen's own playlist (a group's layout is saved on the group page). */
    public function zones(Request $request, TvScreen $screen): RedirectResponse
    {
        $this->manage($request, $screen);
        $data = $request->validate(['zone_layout' => ['required', Rule::in(TvScreen::ZONE_LAYOUTS)]]);
        $screen->update($data);

        return redirect()->to(route('dash.tv.edit', $screen).'#playlist')->with('ok', __('Screen saved.'));
    }

    public function storeSlide(Request $request, TvScreen $screen): RedirectResponse
    {
        $this->manage($request, $screen);

        return $this->addSlide($request, $screen);
    }

    public function updateSlide(Request $request, TvScreen $screen, TvSlide $slide): RedirectResponse
    {
        $this->manage($request, $screen);

        return $this->saveSlide($request, $screen, $slide);
    }

    public function moveSlide(Request $request, TvScreen $screen, TvSlide $slide): RedirectResponse
    {
        $this->manage($request, $screen);

        return $this->shiftSlide($request, $screen, $slide);
    }

    public function destroySlide(Request $request, TvScreen $screen, TvSlide $slide): RedirectResponse
    {
        $this->manage($request, $screen);

        return $this->removeSlide($screen, $slide);
    }

    public function storeGroupSlide(Request $request, TvGroup $group): RedirectResponse
    {
        TvGroupsController::authorizeGroups($request);

        return $this->addSlide($request, $group);
    }

    public function updateGroupSlide(Request $request, TvGroup $group, TvSlide $slide): RedirectResponse
    {
        TvGroupsController::authorizeGroups($request);

        return $this->saveSlide($request, $group, $slide);
    }

    public function moveGroupSlide(Request $request, TvGroup $group, TvSlide $slide): RedirectResponse
    {
        TvGroupsController::authorizeGroups($request);

        return $this->shiftSlide($request, $group, $slide);
    }

    public function destroyGroupSlide(Request $request, TvGroup $group, TvSlide $slide): RedirectResponse
    {
        TvGroupsController::authorizeGroups($request);

        return $this->removeSlide($group, $slide);
    }

    /** «انسخ البرنامج لشاشات تانية»: this screen's playlist replaces the own playlist of the screens ticked. */
    public function copy(Request $request, TvScreen $screen): RedirectResponse
    {
        $this->manage($request, $screen);

        return $this->copyTo($request, $screen->playlistOwner(), $screen->id);
    }

    public function copyGroup(Request $request, TvGroup $group): RedirectResponse
    {
        TvGroupsController::authorizeGroups($request);

        return $this->copyTo($request, $group, null);
    }

    /** A picture (re-encoded to WebP) or an MP4 / WebM video up to 80 MB for the screens. */
    public function upload(Request $request, ImageService $images, ItemVideo $videos): RedirectResponse|JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.TvMedia::MAX_VIDEO_KB, 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime,application/octet-stream',
                'extensions:jpg,jpeg,png,webp,gif,mp4,m4v,webm'],
        ], ['file.*' => __('Upload a JPG, PNG or WebP picture (5 MB) or an MP4 or WebM video (80 MB).')]);
        $file = $request->file('file');
        $isVideo = in_array(strtolower($file->getClientOriginalExtension()), ['mp4', 'm4v', 'webm'], true);
        $path = $isVideo
            ? $videos->store($file, TvMedia::MAX_VIDEO_KB, 'screens', __('Upload an MP4 or WebM video up to 80 MB.'))
            : $images->store($file, 'screen');
        $media = TvMedia::query()->create([
            'kind' => $isVideo ? 'video' : 'image', 'path' => $path,
            'name' => mb_substr(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 120),
            'size' => (int) Storage::disk('public')->size($path),
        ]);

        $back = $request->integer('screen') ? TvScreen::query()->find($request->integer('screen')) : null;
        $group = ! $back && $request->integer('group') ? TvGroup::query()->find($request->integer('group')) : null;
        $to = $back ? route('dash.tv.edit', $back).'#media' : ($group ? route('dash.tv.groups.show', $group).'#media' : url()->previous());
        $message = $media->isVideo() ? __('Video uploaded.') : __('Picture uploaded.');
        // the page uploads with a progress bar (XHR) and then opens $to itself
        if ($request->expectsJson()) {
            session()->flash('ok', $message);

            return response()->json(['redirect' => $to]);
        }

        return redirect()->to($to)->with('ok', $message);
    }

    public function destroyMedia(Request $request, TvMedia $media, ImageService $images, ItemVideo $videos): RedirectResponse
    {
        $slides = TvSlide::query()->where(fn ($q) => $q->where('media_id', $media->id)->orWhere('side_media_id', $media->id))->get(['tv_screen_id', 'tv_group_id']);
        $media->isVideo() ? $videos->delete($media->path, 'screens') : $images->delete($media->path);
        $media->delete();
        // slides that showed it change: their screens pick it up on the next check
        TvScreen::query()->whereIn('id', $slides->pluck('tv_screen_id')->filter()->unique())->get()->each->touch();
        TvGroup::query()->whereIn('id', $slides->pluck('tv_group_id')->filter()->unique())->get()->each->touch();

        return back()->with('ok', __('Deleted.'));
    }

    /** «تحديث الشاشة»: the device reloads the player on its next fast check (within seconds). */
    public function reload(Request $request, TvScreen $screen): RedirectResponse
    {
        $this->manage($request, $screen);
        $screen->writeQuietly(['reload_requested_at' => now()]);

        return back()->with('ok', __('The screen will reload in a few seconds.'));
    }

    /** «فك الربط»: the device goes back to showing a pairing code; the screen and its playlist stay. */
    public function unpair(Request $request, TvScreen $screen): RedirectResponse
    {
        $this->manage($request, $screen);
        $screen->unpair();

        return back()->with('ok', __('The device is unpaired. It now shows a new code.'));
    }

    /**
     * What the playlist editor (dashboard.partials.tv-playlist) needs for a screen or a group.
     *
     * @return array<string, mixed>
     */
    public function editorData(Request $request, TvScreen|TvGroup $owner): array
    {
        $rights = Entitlements::current();

        return [
            'owner' => $owner,
            'slides' => $owner->slides()->with(['media', 'sideMedia', 'offer', 'item', 'branch'])->get(),
            'media' => TvMedia::query()->latest('id')->get(),
            'categories' => Category::query()->orderBy('sort_order')->get(['id', 'name', 'name_en']),
            'items' => MenuItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'category_id', 'price', 'image']),
            'offers' => $rights->allows('offers') ? Offer::query()->orderByDesc('is_active')->orderBy('id')->get(['id', 'title', 'discount_percent', 'is_active']) : collect(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'types' => $this->types(),
            'designs' => ScreenDesigns::all(),
            'targets' => TvScreen::query()->manageableBy($request->user())->with(['branch', 'group'])->orderBy('id')->get(),
        ];
    }

    private function addSlide(Request $request, TvScreen|TvGroup $owner): RedirectResponse
    {
        $data = $this->validatedSlide($request);
        $data['sort_order'] = (int) $owner->slides()->max('sort_order') + 1;
        $owner->slides()->create($data);
        $owner->touch();

        return redirect()->to($this->editUrl($owner).'#playlist')->with('ok', __('Slide added.'));
    }

    private function saveSlide(Request $request, TvScreen|TvGroup $owner, TvSlide $slide): RedirectResponse
    {
        $this->own($owner, $slide);
        $slide->update($this->validatedSlide($request, $slide->type));
        $owner->touch();

        return redirect()->to($this->editUrl($owner).'#slide-'.$slide->id)->with('ok', __('Slide saved.'));
    }

    /** One place up or down in the playlist. */
    private function shiftSlide(Request $request, TvScreen|TvGroup $owner, TvSlide $slide): RedirectResponse
    {
        $this->own($owner, $slide);
        $up = $request->input('direction') === 'up';
        $slides = $owner->slides()->get()->values();
        $index = $slides->search(fn (TvSlide $s) => $s->id === $slide->id);
        $other = $slides->get($up ? $index - 1 : $index + 1);
        if ($other) {
            DB::transaction(function () use ($slides) {
                // renumber first, so slides that shared a number keep a strict order
                $slides->each(fn (TvSlide $s, int $i) => $s->sort_order === $i ? null : $s->update(['sort_order' => $i]));
            });
            [$a, $b] = [$slide->fresh(), $other->fresh()];
            $order = $a->sort_order;
            $a->update(['sort_order' => $b->sort_order]);
            $b->update(['sort_order' => $order]);
            $owner->touch();
        }

        return redirect()->to($this->editUrl($owner).'#slide-'.$slide->id);
    }

    private function removeSlide(TvScreen|TvGroup $owner, TvSlide $slide): RedirectResponse
    {
        $this->own($owner, $slide);
        $slide->delete();
        $owner->touch();

        return redirect()->to($this->editUrl($owner).'#playlist')->with('ok', __('Slide deleted.'));
    }

    /**
     * Copies $from's slides (and its area layout) onto each ticked screen the user manages, replacing that screen's own
     * slides; a ticked screen in a group then plays its own copy instead of the group's playlist.
     */
    private function copyTo(Request $request, TvScreen|TvGroup $from, ?int $sourceScreenId): RedirectResponse
    {
        $data = $request->validate(['screen_ids' => 'required|array|min:1|max:500', 'screen_ids.*' => 'integer'],
            ['screen_ids.required' => __('Tick the screens to copy the playlist to.')]);
        $targets = TvScreen::query()->manageableBy($request->user())->whereIn('id', $data['screen_ids'])->get()
            ->reject(fn (TvScreen $t) => $t->id === $sourceScreenId || ($from instanceof TvScreen && $t->id === $from->id));
        if ($targets->isEmpty()) {
            return back()->withErrors(['screen_ids' => __('Tick the screens to copy the playlist to.')]);
        }
        $slides = $from->slides()->get();
        DB::transaction(function () use ($targets, $slides, $from) {
            foreach ($targets as $target) {
                $target->slides()->delete();
                foreach ($slides as $slide) {
                    $copy = $slide->replicate(['tv_screen_id', 'tv_group_id']);
                    $copy->tv_screen_id = $target->id;
                    $copy->tv_group_id = null;
                    $copy->save();
                }
                $target->update(['zone_layout' => $from->zone_layout ?: 'full', 'own_playlist' => $target->tv_group_id !== null]);
                $target->touch();
            }
        });

        return back()->with('ok', trans_choice('Playlist copied to :count screen.|Playlist copied to :count screens.', $targets->count()));
    }

    private function editUrl(TvScreen|TvGroup $owner): string
    {
        return $owner instanceof TvGroup ? route('dash.tv.groups.show', $owner) : route('dash.tv.edit', $owner);
    }

    /** @return array<string, string> type => label, the ready slide only with the pager or the customer display */
    private function types(): array
    {
        $rights = Entitlements::current();

        return collect(TvSlide::TYPES)
            ->filter(fn (string $t) => match ($t) {
                'offer' => $rights->allows('offers'),
                'ready' => $rights->allows(TvSlide::READY_FEATURE),
                default => true,
            })
            ->mapWithKeys(fn (string $t) => [$t => TvSlide::typeLabel($t)])->all();
    }

    /** @return array<string, mixed> */
    private function validatedSlide(Request $request, ?string $type = null): array
    {
        $tenant = tenant()->getTenantKey();
        $type ??= (string) $request->input('type');
        // the picture and the video lists are separate fields, so the form works without its script
        $request->merge(['type' => $type, 'media_id' => match ($type) {
            'image' => $request->input('image_id', $request->input('media_id')),
            'video' => $request->input('video_id', $request->input('media_id')),
            'promo' => $request->input('promo_image_id'),
            default => null,
        }, 'item_id' => $type === 'promo' ? $request->input('promo_item_id') : $request->input('item_id')]);
        $mediaRule = fn (string $kind) => Rule::exists('tv_media', 'id')->where('tenant_id', $tenant)->where('kind', $kind);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys($this->types()))],
            'duration' => ['required', 'integer', $type === 'video' ? 'min:0' : 'min:5', 'max:600'],
            'zone' => ['nullable', Rule::in(TvSlide::ZONES)],
            'category_ids' => 'nullable|array|max:100',
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'layout' => ['nullable', Rule::in(TvSlide::LAYOUTS)],
            'columns' => 'nullable|integer|min:1|max:4',
            'item_id' => ['nullable', Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'media_id' => [Rule::requiredIf(in_array($type, ['image', 'video'], true)), 'nullable', $mediaRule($type === 'video' ? 'video' : 'image')],
            'side_media_id' => ['nullable', Rule::exists('tv_media', 'id')->where('tenant_id', $tenant)],
            'offer_id' => ['nullable', Rule::exists('offers', 'id')->where('tenant_id', $tenant)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
            'template' => [Rule::requiredIf($type === 'promo'), 'nullable', Rule::in(ScreenDesigns::keys())],
            'title' => [Rule::requiredIf($type === 'promo' && ! $request->filled('item_id') && $request->input('template') !== 'scan'), 'nullable', 'string', 'max:80'],
            'title_en' => 'nullable|string|max:80',
            'subtitle' => 'nullable|string|max:140',
            'subtitle_en' => 'nullable|string|max:140',
            'price' => 'nullable|numeric|min:0|max:1000000',
            'was' => 'nullable|numeric|min:0|max:1000000',
            'badge' => 'nullable|string|max:30',
            'bg' => ['nullable', Rule::in(TvSlide::BACKGROUNDS)],
            'promo_ends_at' => 'nullable|date_format:Y-m-d\TH:i',
        ] + $this->hourRules(), [
            'media_id.required' => __('Pick a picture or a video from the screen library (upload it first).'),
            'title.required' => __('Pick an item or type a title for the design.'),
        ]);

        $menu = $type === 'menu';
        $promo = $type === 'promo';

        return [
            'type' => $type,
            'duration' => (int) $data['duration'],
            'zone' => $data['zone'] ?? 'main',
            'category_ids' => $menu ? (array_values(array_map('intval', $data['category_ids'] ?? [])) ?: null) : null,
            'layout' => $menu ? ($data['layout'] ?? 'list') : 'list',
            'columns' => $menu ? (int) ($data['columns'] ?? 3) : 3,
            'show_images' => $menu ? $request->boolean('show_images') : true,
            'item_id' => ($menu && ($data['layout'] ?? '') === 'featured') || $promo ? ($data['item_id'] ?? null) : null,
            'media_id' => in_array($type, ['image', 'video', 'promo'], true) ? ($data['media_id'] ?? null) : null,
            'side_media_id' => $menu && ($data['layout'] ?? 'list') !== 'featured' ? ($data['side_media_id'] ?? null) : null,
            'offer_id' => $type === 'offer' ? ($data['offer_id'] ?? null) : null,
            'branch_id' => $type === 'ready' ? ($data['branch_id'] ?? null) : null,
            'template' => $promo ? $data['template'] : null,
            'options' => $promo ? array_filter([
                'title' => trim((string) ($data['title'] ?? '')), 'title_en' => trim((string) ($data['title_en'] ?? '')),
                'subtitle' => trim((string) ($data['subtitle'] ?? '')), 'subtitle_en' => trim((string) ($data['subtitle_en'] ?? '')),
                'price' => isset($data['price']) ? (string) $data['price'] : '', 'was' => isset($data['was']) ? (string) $data['was'] : '',
                'badge' => trim((string) ($data['badge'] ?? '')), 'bg' => $data['bg'] ?? 'dark',
                'ends_at' => isset($data['promo_ends_at']) ? str_replace('T', ' ', $data['promo_ends_at']) : '',
            ], fn ($v) => $v !== '') : null,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ] + $this->hours($data);
    }

    /** @return array<string, mixed> */
    private function hourRules(): array
    {
        return [
            'days' => 'nullable|array|max:7',
            'days.*' => 'integer|between:0,6',
            'starts_at' => 'nullable|date_format:H:i|required_with:ends_at',
            'ends_at' => 'nullable|date_format:H:i|required_with:starts_at',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{days: list<int>|null, starts_at: string|null, ends_at: string|null}
     */
    private function hours(array $data): array
    {
        $days = array_values(array_unique(array_map('intval', $data['days'] ?? [])));
        sort($days);

        return [
            // every day ticked is the same as none
            'days' => $days === [] || count($days) === 7 ? null : $days,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ];
    }

    /** Staff tied to a branch reach only that branch's screens; any other screen is "not found". */
    private function manage(Request $request, TvScreen $screen): void
    {
        abort_unless($screen->manageableBy($request->user()), 404);
    }

    private function own(TvScreen|TvGroup $owner, TvSlide $slide): void
    {
        $mine = $owner instanceof TvGroup ? $slide->tv_group_id === $owner->id : $slide->tv_screen_id === $owner->id;
        abort_unless($mine, 404);
    }

    /** @return Collection<int, TvScreen> */
    public static function screensFor(Request $request): Collection
    {
        return TvScreen::query()->manageableBy($request->user())->with(['branch', 'group'])->orderBy('id')->get();
    }
}
