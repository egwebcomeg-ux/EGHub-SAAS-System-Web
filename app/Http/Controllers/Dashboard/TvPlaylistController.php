<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Offer;
use App\Models\TvMedia;
use App\Models\TvScreen;
use App\Models\TvSlide;
use App\Services\Entitlements;
use App\Services\ImageService;
use App\Services\ItemVideo;
use App\Services\Screens\ScreenPairing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One TV screen's page in the dashboard: how it looks (orientation, colours, language, ticker, hours), its playlist of
 * slides (add, edit, reorder, delete), the restaurant's screen media (pictures and videos) and the paired device
 * (status, «تحديث الشاشة», «فك الربط»). Owner and manager (menu.manage), screens in the plan (feature tv_screens).
 * Every id posted here is checked inside the current restaurant.
 */
class TvPlaylistController extends Controller
{
    public function edit(TvScreen $screen): View
    {
        $rights = Entitlements::current();

        return view('dashboard.tv-screen', [
            'screen' => $screen->load('branch'),
            'slides' => $screen->slides()->with(['media', 'sideMedia', 'offer', 'item', 'branch'])->get(),
            'media' => TvMedia::query()->latest('id')->get(),
            'categories' => Category::query()->orderBy('sort_order')->get(['id', 'name', 'name_en']),
            'items' => MenuItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'category_id']),
            'offers' => $rights->allows('offers') ? Offer::query()->orderByDesc('is_active')->orderBy('id')->get(['id', 'title', 'discount_percent', 'is_active']) : collect(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'types' => $this->types(),
            'limit' => $rights->limit('screens'),
            'paired' => ScreenPairing::pairedCount(),
        ]);
    }

    /** The screen's look and hours. */
    public function settings(Request $request, TvScreen $screen): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', tenant()->getTenantKey())],
            'orientation' => ['required', Rule::in(TvScreen::ORIENTATIONS)],
            'theme' => ['required', Rule::in(TvScreen::THEMES)],
            'language' => ['nullable', Rule::in(TvScreen::LANGUAGES)],
            'ticker_text' => 'nullable|string|max:300',
        ] + $this->hourRules());
        $screen->update([
            'name' => $data['name'], 'branch_id' => $data['branch_id'] ?? null, 'orientation' => $data['orientation'], 'theme' => $data['theme'],
            'language' => (string) ($data['language'] ?? ''), 'ticker_text' => $data['ticker_text'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ] + $this->hours($data));

        return back()->with('ok', __('Screen saved.'));
    }

    public function storeSlide(Request $request, TvScreen $screen): RedirectResponse
    {
        $data = $this->validatedSlide($request);
        $data['sort_order'] = (int) $screen->slides()->max('sort_order') + 1;
        $screen->slides()->create($data);
        $screen->touch();

        return redirect()->to(route('dash.tv.edit', $screen).'#playlist')->with('ok', __('Slide added.'));
    }

    public function updateSlide(Request $request, TvScreen $screen, TvSlide $slide): RedirectResponse
    {
        $this->own($screen, $slide);
        $slide->update($this->validatedSlide($request, $slide->type));
        $screen->touch();

        return redirect()->to(route('dash.tv.edit', $screen).'#slide-'.$slide->id)->with('ok', __('Slide saved.'));
    }

    /** One place up or down in the playlist. */
    public function moveSlide(Request $request, TvScreen $screen, TvSlide $slide): RedirectResponse
    {
        $this->own($screen, $slide);
        $up = $request->input('direction') === 'up';
        $slides = $screen->slides()->get()->values();
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
            $screen->touch();
        }

        return redirect()->to(route('dash.tv.edit', $screen).'#slide-'.$slide->id);
    }

    public function destroySlide(TvScreen $screen, TvSlide $slide): RedirectResponse
    {
        $this->own($screen, $slide);
        $slide->delete();
        $screen->touch();

        return redirect()->to(route('dash.tv.edit', $screen).'#playlist')->with('ok', __('Slide deleted.'));
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
        $to = $back ? route('dash.tv.edit', $back).'#media' : url()->previous();
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
        $screens = TvSlide::query()->where(fn ($q) => $q->where('media_id', $media->id)->orWhere('side_media_id', $media->id))->pluck('tv_screen_id')->unique();
        $media->isVideo() ? $videos->delete($media->path, 'screens') : $images->delete($media->path);
        $media->delete();
        // slides that showed it change: their screens pick it up on the next check
        TvScreen::query()->whereIn('id', $screens)->get()->each->touch();

        return back()->with('ok', __('Deleted.'));
    }

    /** «تحديث الشاشة»: the device reloads the player on its next check (within a minute). */
    public function reload(TvScreen $screen): RedirectResponse
    {
        $screen->writeQuietly(['reload_requested_at' => now()]);

        return back()->with('ok', __('The screen will reload within a minute.'));
    }

    /** «فك الربط»: the device goes back to showing a pairing code; the screen and its playlist stay. */
    public function unpair(TvScreen $screen): RedirectResponse
    {
        $screen->unpair();

        return back()->with('ok', __('The device is unpaired. It now shows a new code.'));
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
        // the picture and the video lists are two fields, so the form works without its script
        $request->merge(['type' => $type, 'media_id' => match ($type) {
            'image' => $request->input('image_id', $request->input('media_id')),
            'video' => $request->input('video_id', $request->input('media_id')),
            default => null,
        }]);
        $mediaRule = fn (string $kind) => Rule::exists('tv_media', 'id')->where('tenant_id', $tenant)->where('kind', $kind);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys($this->types()))],
            'duration' => ['required', 'integer', $type === 'video' ? 'min:0' : 'min:5', 'max:600'],
            'category_ids' => 'nullable|array|max:100',
            'category_ids.*' => ['integer', Rule::exists('categories', 'id')->where('tenant_id', $tenant)],
            'layout' => ['nullable', Rule::in(TvSlide::LAYOUTS)],
            'columns' => 'nullable|integer|min:1|max:4',
            'item_id' => ['nullable', Rule::exists('menu_items', 'id')->where('tenant_id', $tenant)],
            'media_id' => [Rule::requiredIf(in_array($type, ['image', 'video'], true)), 'nullable', $mediaRule($type === 'video' ? 'video' : 'image')],
            'side_media_id' => ['nullable', Rule::exists('tv_media', 'id')->where('tenant_id', $tenant)],
            'offer_id' => ['nullable', Rule::exists('offers', 'id')->where('tenant_id', $tenant)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenant)],
        ] + $this->hourRules(), [
            'media_id.required' => __('Pick a picture or a video from the screen library (upload it first).'),
        ]);

        $menu = $type === 'menu';

        return [
            'type' => $type,
            'duration' => (int) $data['duration'],
            'category_ids' => $menu ? (array_values(array_map('intval', $data['category_ids'] ?? [])) ?: null) : null,
            'layout' => $menu ? ($data['layout'] ?? 'list') : 'list',
            'columns' => $menu ? (int) ($data['columns'] ?? 3) : 3,
            'show_images' => $menu ? $request->boolean('show_images') : true,
            'item_id' => $menu && ($data['layout'] ?? '') === 'featured' ? ($data['item_id'] ?? null) : null,
            'media_id' => in_array($type, ['image', 'video'], true) ? $data['media_id'] : null,
            'side_media_id' => $menu && ($data['layout'] ?? 'list') !== 'featured' ? ($data['side_media_id'] ?? null) : null,
            'offer_id' => $type === 'offer' ? ($data['offer_id'] ?? null) : null,
            'branch_id' => $type === 'ready' ? ($data['branch_id'] ?? null) : null,
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

    private function own(TvScreen $screen, TvSlide $slide): void
    {
        abort_unless($slide->tv_screen_id === $screen->id, 404);
    }
}
