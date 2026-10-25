{{--
    The playlist editor of a screen or of a group's shared playlist ($owner): one area or two, the slides in order
    (add, edit, move, delete), «انسخ البرنامج» to other screens, and the screen library (pictures and videos).
    Posts to dash.tv.* for a screen and dash.tv.groups.* for a group.
--}}
@php
    $isGroup = $owner instanceof \App\Models\TvGroup;
    $r = fn (string $name, $slide = null) => $isGroup
        ? route('dash.tv.groups.' . $name, $slide ? [$owner, $slide] : $owner)
        : route('dash.tv.' . $name, $slide ? [$owner, $slide] : $owner);
    $zoneLayout = in_array($owner->zone_layout, \App\Models\TvScreen::ZONE_LAYOUTS, true) ? $owner->zone_layout : 'full';
    $zoneNames = ['full' => __('One area'), 'side' => __('Main area and a side column'), 'halves' => __('Two halves')];
    $summary = function (\App\Models\TvSlide $s) {
        return match ($s->type) {
            'menu' => match ($s->layout) { 'grid' => __('Photo cards'), 'featured' => __('One featured item') . ($s->item ? ': ' . $s->item->name : ''), default => __('List in columns') }
                . ' · ' . ($s->category_ids ? trans_choice(':count category|:count categories', count($s->category_ids)) : __('All categories'))
                . ($s->sideMedia ? ' · ' . __('split screen') : ''),
            'image', 'video' => $s->media?->name ?: __('Missing file'),
            'offer' => $s->offer ? $s->offer->title : __('Any offer that is running'),
            'ready' => $s->branch ? tr($s->branch->name, $s->branch->name_en) : __('The screen\'s branch'),
            'promo' => collect([\App\Support\ScreenDesigns::label($s->template), $s->option('title') ?: $s->item?->name])->filter()->implode(' · '),
            default => '',
        };
    };
    $hoursText = function ($m) {
        if (! $m->hasHours()) {
            return null;
        }
        $names = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
        $days = $m->days ? collect($m->days)->map(fn ($d) => $names[(int) $d] ?? '')->implode('، ') : __('Every day');

        return $days . ($m->starts_at ? ' · ' . substr($m->starts_at, 0, 5) . '–' . substr($m->ends_at, 0, 5) : '');
    };
    $portraitPreview = ! $isGroup && in_array($owner->orientation, ['portrait', 'portrait2'], true);
    $itemsJson = $items->mapWithKeys(fn ($it) => [$it->id => ['name' => $it->name, 'price' => money($it->price), 'image' => $it->image ? '/storage/' . $it->image : null]]);
    $imagesJson = $media->where('kind', 'image')->mapWithKeys(fn ($m) => [$m->id => $m->url()]);
@endphp

<section class="card space-y-4" id="playlist">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="section-title">{{ __('Playlist') }}</h2>
        <span class="text-sm text-navy-500">{{ trans_choice(':count slide|:count slides', $slides->count()) }}</span>
    </div>

    {{-- one area or two --}}
    <form method="post" action="{{ $r('zones') }}" class="rounded-xl bg-navy-50 p-3" data-zones-form>
        @csrf
        <p class="field-label mb-2">{{ __('Screen areas') }}</p>
        <div class="grid grid-cols-3 gap-2">
            @foreach ($zoneNames as $key => $label)
                <label class="flex cursor-pointer flex-col items-center gap-1.5 rounded-lg border border-navy-100 bg-white p-2 text-center text-xs font-semibold has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50" data-zone-option="{{ $key }}">
                    <input type="radio" name="zone_layout" value="{{ $key }}" @checked($zoneLayout === $key) class="sr-only" onchange="this.form.submit()">
                    <span class="flex h-9 w-14 gap-0.5 rounded border border-navy-300 bg-navy-100 p-0.5" aria-hidden="true">
                        @if ($key === 'full')<span class="flex-1 rounded-sm bg-navy-500"></span>
                        @elseif ($key === 'side')<span class="w-[68%] rounded-sm bg-navy-500"></span><span class="flex-1 rounded-sm bg-brand-400"></span>
                        @else<span class="flex-1 rounded-sm bg-navy-500"></span><span class="flex-1 rounded-sm bg-brand-400"></span>@endif
                    </span>
                    {{ $label }}
                </label>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-navy-500">{{ $zoneLayout === 'full' ? __('Split the screen to play the menu in one area and videos, pictures or offers in the other, at the same time. On a portrait screen the second area goes under the first.') : __('Each slide plays in the area you choose for it. Videos in both areas at once may stutter on old TVs: keep one of them to pictures.') }}</p>
        <noscript><button class="btn-ghost mt-2 min-h-9 px-3">{{ __('Save') }}</button></noscript>
    </form>

    @if ($slides->isEmpty())
        <div class="empty-state">{{ __('No slides yet: the screen shows your whole menu. Add slides to make your own playlist (menu, pictures, video, offers, ready orders).') }}</div>
    @endif
    <ol class="space-y-3" data-slides>
        @foreach ($slides as $i => $slide)
            <li class="rounded-xl border border-navy-100 p-3 {{ $slide->is_active ? '' : 'opacity-60' }}" id="slide-{{ $slide->id }}" data-slide="{{ $slide->type }}">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-navy-50 text-sm font-bold text-navy-700">{{ $i + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="badge bg-brand-50 text-brand-700">{{ \App\Models\TvSlide::typeLabel($slide->type) }}</span>
                            @if ($zoneLayout !== 'full')<span class="badge {{ $slide->zone === 'side' ? 'bg-sky-50 text-sky-800' : 'bg-navy-50 text-navy-700' }}" data-slide-zone="{{ $slide->zone }}">{{ $slide->zone === 'side' ? __('Side part') : __('Main part') }}</span>@endif
                            <span class="text-xs text-navy-500" dir="ltr">{{ $slide->type === 'video' && ! $slide->duration ? __('to the end') : $slide->duration . 's' }}</span>
                            @if ($h = $hoursText($slide))<span class="badge bg-amber-50 text-amber-800">{{ $h }}</span>@endif
                            @if ($slide->type === 'promo' && $slide->option('ends_at'))<span class="badge bg-amber-50 text-amber-800">{{ __('Until :time', ['time' => $slide->option('ends_at')]) }}</span>@endif
                            @unless ($slide->is_active)<span class="badge bg-navy-100 text-navy-600">{{ __('Hidden') }}</span>@endunless
                        </div>
                        <p class="mt-1 truncate text-sm font-semibold">{{ $summary($slide) }}</p>
                    </div>
                    <div class="flex items-center gap-1">
                        <form method="post" action="{{ $r('slides.move', $slide) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn-ghost min-h-10 min-w-10 px-2" @disabled($i === 0) aria-label="{{ __('Move up') }}" title="{{ __('Move up') }}">↑</button></form>
                        <form method="post" action="{{ $r('slides.move', $slide) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn-ghost min-h-10 min-w-10 px-2" @disabled($i === $slides->count() - 1) aria-label="{{ __('Move down') }}" title="{{ __('Move down') }}">↓</button></form>
                    </div>
                </div>
                <details class="mt-2" data-slide-details>
                    <summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('Edit') }}</summary>
                    <form method="post" action="{{ $r('slides.update', $slide) }}" class="mt-3 grid gap-3 sm:grid-cols-2" data-slide-form="{{ $slide->type }}">
                        @csrf
                        @include('dashboard.partials.tv-slide-fields', ['slide' => $slide])
                        <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                            <button class="btn-primary">{{ __('Save') }}</button>
                        </div>
                    </form>
                    <form method="post" action="{{ $r('slides.delete', $slide) }}" class="mt-2" onsubmit="return confirm(@js(__('Delete this slide?')))">@csrf<button class="min-h-10 px-1 text-sm text-red-600">{{ __('Delete slide') }}</button></form>
                </details>
            </li>
        @endforeach
    </ol>

    <details class="rounded-xl border border-dashed border-brand-300 p-3" id="add-slide" @if ($slides->isEmpty() || $errors->any() || request('add')) open @endif>
        <summary class="cursor-pointer font-bold text-brand-700">＋ {{ __('Add slide') }}</summary>
        <form method="post" action="{{ $r('slides.store') }}" class="mt-3 grid gap-3 sm:grid-cols-2" data-slide-form>
            @csrf
            @include('dashboard.partials.tv-slide-fields', ['slide' => null])
            <div class="sm:col-span-2"><button class="btn-primary">{{ __('Add slide') }}</button></div>
        </form>
    </details>
</section>

{{-- copy this playlist to other screens --}}
@php
    $copyTargets = $targets->reject(fn ($t) => ! $isGroup && $t->id === $owner->id);
@endphp
@if ($copyTargets->isNotEmpty())
    <section class="card space-y-3" id="copy">
        <h2 class="section-title">{{ __('Copy this playlist to other screens') }}</h2>
        <p class="text-sm text-navy-500">{{ __('The ticked screens get these slides in place of their own playlist. Each one keeps its own branch prices, look and hours. A screen in a group then plays its own copy.') }}</p>
        <form method="post" action="{{ $r('copy') }}" class="space-y-3" data-copy-form onsubmit="return confirm(@js(__('Replace the playlist of the ticked screens?')))">
            @csrf
            @include('dashboard.partials.tv-screen-picker', ['screens' => $copyTargets, 'checked' => []])
            <button class="btn-primary">{{ __('Copy the playlist') }}</button>
        </form>
    </section>
@endif

{{-- the screen library --}}
<section class="card space-y-3" id="media">
    <h2 class="section-title">{{ __('Pictures and videos') }}</h2>
    <p class="text-sm text-navy-500">{{ __('Pictures (JPG, PNG, WebP up to 5 MB) and videos (MP4 or WebM up to 80 MB, played muted). The screen downloads them first, so they keep playing without internet.') }}</p>
    <form method="post" action="{{ route('dash.tv.media.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2" data-upload>
        @csrf
        <input type="hidden" name="{{ $isGroup ? 'group' : 'screen' }}" value="{{ $owner->id }}">
        <label class="min-w-0 flex-1">
            <span class="field-label">{{ __('File') }}</span>
            <input class="field" type="file" name="file" required accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,.mp4,.webm,.m4v">
        </label>
        <button class="btn-primary" data-upload-button>{{ __('Upload') }}</button>
        <p class="hidden w-full text-sm font-semibold text-brand-700" data-upload-progress></p>
    </form>
    @if ($media->isEmpty())
        <p class="empty-state">{{ __('Nothing uploaded yet.') }}</p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($media as $m)
                <figure class="overflow-hidden rounded-xl border border-navy-100" data-media="{{ $m->kind }}">
                    <div class="aspect-video bg-navy-900">
                        @if ($m->isVideo())
                            <video src="{{ $m->url() }}" muted preload="metadata" playsinline class="h-full w-full object-cover"></video>
                        @else
                            <img src="{{ $m->url() }}" alt="" loading="lazy" class="h-full w-full object-cover">
                        @endif
                    </div>
                    <figcaption class="flex items-center justify-between gap-2 p-2 text-xs">
                        <span class="min-w-0 truncate" title="{{ $m->name }}">{{ $m->isVideo() ? '🎬' : '🖼' }} {{ $m->name ?: '#'.$m->id }} <span class="text-navy-400" dir="ltr">{{ number_format($m->size / 1048576, 1) }} MB</span></span>
                        <form method="post" action="{{ route('dash.tv.media.delete', $m) }}" onsubmit="return confirm(@js(__('Delete this file? Slides that use it stop showing it.')))">@csrf<button class="min-h-9 px-1 text-red-600">{{ __('Delete') }}</button></form>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    @endif
</section>

<script>
    // each slide form shows only the fields of its type
    document.querySelectorAll('[data-slide-form]').forEach((form) => {
        const sync = () => {
            const type = form.dataset.slideForm || form.querySelector('[name="type"]:checked')?.value;
            form.querySelectorAll('[data-for]').forEach((el) => {
                const on = el.dataset.for.split(' ').includes(type);
                el.hidden = !on;
                el.querySelectorAll('input, select, textarea').forEach((f) => { f.disabled = !on; });
            });
            form.dispatchEvent(new Event('slide-type'));
        };
        form.querySelectorAll('[name="type"]').forEach((r) => r.addEventListener('change', sync));
        sync();
    });

    // the ready designs: every small preview draws the chosen item or the typed words with the restaurant's brand
    (() => {
        const items = @json($itemsJson);
        const images = @json($imagesJson);
        document.querySelectorAll('[data-design-picker]').forEach((picker) => {
            const form = picker.closest('form');
            const frames = () => picker.querySelectorAll('iframe[data-design]');
            const value = (name) => { const f = form.querySelector(`[name="${name}"]`); return f && !f.disabled ? f.value.trim() : ''; };
            const data = () => {
                const item = items[value('promo_item_id')] || null;
                const ends = value('promo_ends_at');
                const bg = form.querySelector('[name="bg"]:checked');
                const money = (v) => v === '' ? '' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 });
                return {
                    title: value('title') || (item ? item.name : @js(__('Item name'))),
                    alt: value('title_en'),
                    desc: value('subtitle') || '',
                    price: item ? item.price : money(value('price')),
                    was: value('was') ? money(value('was')) : '',
                    image: item?.image || images[value('promo_image_id')] || null,
                    badge: value('badge'),
                    bg: bg ? bg.value : 'dark',
                    ends: ends ? new Date(ends).getTime() : null,
                };
            };
            const send = () => frames().forEach((f) => { try { f.contentWindow.postMessage({ type: 'eghub-design', data: data() }, location.origin); } catch (e) { /* not loaded yet */ } });
            const scale = () => frames().forEach((f) => {
                const box = f.parentElement;
                f.style.transform = `scale(${box.clientWidth / f.offsetWidth})`;
            });
            window.addEventListener('message', (e) => { if (e.origin === location.origin && e.data?.type === 'eghub-design-ready') { send(); } });
            form.addEventListener('input', send);
            form.addEventListener('change', send);
            picker.querySelectorAll('[data-orientation]').forEach((b) => b.addEventListener('click', () => {
                const portrait = b.dataset.orientation === 'portrait';
                picker.querySelectorAll('[data-orientation]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
                picker.querySelectorAll('[data-design-box]').forEach((box) => {
                    box.classList.toggle('aspect-video', !portrait);
                    box.classList.toggle('aspect-[9/16]', portrait);
                    const f = box.querySelector('iframe');
                    f.style.width = portrait ? '540px' : '960px';
                    f.style.height = portrait ? '960px' : '540px';
                    f.src = f.dataset.src + (portrait ? '&o=portrait' : '');
                });
                requestAnimationFrame(scale);
            }));
            new ResizeObserver(scale).observe(picker);
            form.addEventListener('slide-type', () => requestAnimationFrame(scale));
            form.closest('details')?.addEventListener('toggle', () => requestAnimationFrame(scale));
            scale();
        });
    })();

    // big videos: show the upload progress (80 MB takes a while on a shop connection)
    document.querySelectorAll('[data-upload]').forEach((form) => form.addEventListener('submit', (e) => {
        const file = form.querySelector('[type="file"]').files[0];
        if (!file || !window.FormData) {
            return;
        }
        e.preventDefault();
        const out = form.querySelector('[data-upload-progress]');
        const button = form.querySelector('[data-upload-button]');
        button.disabled = true;
        out.hidden = false;
        out.classList.remove('hidden');
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = (ev) => { if (ev.lengthComputable) { out.textContent = @js(__('Uploading…')) + ' ' + Math.round(ev.loaded * 100 / ev.total) + '%'; } };
        xhr.onload = () => {
            let body = {};
            try { body = JSON.parse(xhr.responseText); } catch (err) { /* not JSON: a server error page */ }
            if (xhr.status >= 200 && xhr.status < 300 && body.redirect) {
                const target = new URL(body.redirect, location.href);
                if (target.pathname === location.pathname) {
                    location.hash = target.hash;
                    location.reload();
                } else {
                    location.href = target.href;
                }
                return;
            }
            button.disabled = false;
            out.textContent = (body.errors && Object.values(body.errors)[0]?.[0]) || body.message || @js(__('The upload stopped. Check the connection and try again.'));
        };
        xhr.onerror = () => { button.disabled = false; out.textContent = @js(__('The upload stopped. Check the connection and try again.')); };
        xhr.send(new FormData(form));
    }));
</script>
