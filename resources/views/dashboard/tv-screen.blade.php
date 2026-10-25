@extends('layouts.dashboard')
@section('title', $screen->name . ' · ' . __('TV screens'))

@section('content')
@php
    $status = $screen->status();
    $info = $screen->device_info ?? [];
    $orientations = ['landscape' => __('Landscape (wide)'), 'portrait' => __('Portrait, turned right'), 'portrait2' => __('Portrait, turned left')];
    $languages = ['' => __('The menu\'s default language'), 'ar' => __('Arabic'), 'en' => __('English'), 'both' => __('Arabic and English')];
    $summary = function (\App\Models\TvSlide $s) {
        return match ($s->type) {
            'menu' => match ($s->layout) { 'grid' => __('Photo cards'), 'featured' => __('One featured item') . ($s->item ? ': ' . $s->item->name : ''), default => __('List in columns') }
                . ' · ' . ($s->category_ids ? trans_choice(':count category|:count categories', count($s->category_ids)) : __('All categories'))
                . ($s->sideMedia ? ' · ' . __('split screen') : ''),
            'image', 'video' => $s->media?->name ?: __('Missing file'),
            'offer' => $s->offer ? $s->offer->title : __('Any offer that is running'),
            'ready' => $s->branch ? tr($s->branch->name, $s->branch->name_en) : __('The screen\'s branch'),
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
@endphp
<x-dash.page-header :title="$screen->name" :help="__('What this screen plays, in order. Prices and sold-out items follow the menu by themselves.')">
    <x-slot:actions>
        <a href="{{ route('dash.devices', ['tab' => 'tv']) }}" class="btn-ghost">{{ __('All screens') }}</a>
        <a href="{{ $screen->url() }}" target="_blank" rel="noopener" class="btn-ghost" data-preview>{{ __('Preview') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        {{-- the playlist --}}
        <section class="card space-y-4" id="playlist">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title">{{ __('Playlist') }}</h2>
                <span class="text-sm text-navy-500">{{ trans_choice(':count slide|:count slides', $slides->count()) }}</span>
            </div>
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
                                    <span class="text-xs text-navy-500" dir="ltr">{{ $slide->type === 'video' && ! $slide->duration ? __('to the end') : $slide->duration . 's' }}</span>
                                    @if ($h = $hoursText($slide))<span class="badge bg-amber-50 text-amber-800">{{ $h }}</span>@endif
                                    @unless ($slide->is_active)<span class="badge bg-navy-100 text-navy-600">{{ __('Hidden') }}</span>@endunless
                                </div>
                                <p class="mt-1 truncate text-sm font-semibold">{{ $summary($slide) }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <form method="post" action="{{ route('dash.tv.slides.move', [$screen, $slide]) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn-ghost min-h-10 min-w-10 px-2" @disabled($i === 0) aria-label="{{ __('Move up') }}" title="{{ __('Move up') }}">↑</button></form>
                                <form method="post" action="{{ route('dash.tv.slides.move', [$screen, $slide]) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn-ghost min-h-10 min-w-10 px-2" @disabled($i === $slides->count() - 1) aria-label="{{ __('Move down') }}" title="{{ __('Move down') }}">↓</button></form>
                            </div>
                        </div>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('Edit') }}</summary>
                            <form method="post" action="{{ route('dash.tv.slides.update', [$screen, $slide]) }}" class="mt-3 grid gap-3 sm:grid-cols-2" data-slide-form="{{ $slide->type }}">
                                @csrf
                                @include('dashboard.partials.tv-slide-fields', ['slide' => $slide])
                                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                                    <button class="btn-primary">{{ __('Save') }}</button>
                                </div>
                            </form>
                            <form method="post" action="{{ route('dash.tv.slides.delete', [$screen, $slide]) }}" class="mt-2" onsubmit="return confirm(@js(__('Delete this slide?')))">@csrf<button class="min-h-10 px-1 text-sm text-red-600">{{ __('Delete slide') }}</button></form>
                        </details>
                    </li>
                @endforeach
            </ol>

            <details class="rounded-xl border border-dashed border-brand-300 p-3" id="add-slide" @if ($slides->isEmpty() || $errors->any()) open @endif>
                <summary class="cursor-pointer font-bold text-brand-700">＋ {{ __('Add slide') }}</summary>
                <form method="post" action="{{ route('dash.tv.slides.store', $screen) }}" class="mt-3 grid gap-3 sm:grid-cols-2" data-slide-form>
                    @csrf
                    @include('dashboard.partials.tv-slide-fields', ['slide' => null])
                    <div class="sm:col-span-2"><button class="btn-primary">{{ __('Add slide') }}</button></div>
                </form>
            </details>
        </section>

        {{-- the screen library --}}
        <section class="card space-y-3" id="media">
            <h2 class="section-title">{{ __('Pictures and videos') }}</h2>
            <p class="text-sm text-navy-500">{{ __('Pictures (JPG, PNG, WebP up to 5 MB) and videos (MP4 or WebM up to 80 MB, played muted). The screen downloads them first, so they keep playing without internet.') }}</p>
            <form method="post" action="{{ route('dash.tv.media.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2" data-upload>
                @csrf
                <input type="hidden" name="screen" value="{{ $screen->id }}">
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
    </div>

    <div class="space-y-6">
        {{-- the device --}}
        <section class="card space-y-3" id="device" data-status="{{ $status }}">
            <h2 class="section-title">{{ __('The TV') }}</h2>
            @include('dashboard.partials.tv-status', ['screen' => $screen])
            @if ($screen->isPaired())
                <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-navy-600">
                    @if ($screen->paired_at)<dt>{{ __('Paired') }}</dt><dd>{{ local_time($screen->paired_at) }}</dd>@endif
                    @if (! empty($info['device']))<dt>{{ __('Device') }}</dt><dd dir="ltr" class="text-start">{{ $info['device'] }}{{ ! empty($info['model']) ? ' · ' . $info['model'] : '' }}</dd>@endif
                    @if (! empty($info['screen']))<dt>{{ __('Resolution') }}</dt><dd dir="ltr" class="text-start">{{ $info['screen'] }}</dd>@endif
                    @if (! empty($info['app']))<dt>{{ __('Player version') }}</dt><dd dir="ltr" class="text-start">{{ $info['app'] }}</dd>@endif
                </dl>
                <div class="flex flex-wrap gap-2">
                    <form method="post" action="{{ route('dash.tv.reload', $screen) }}">@csrf<button class="btn-ghost min-h-10 px-3">{{ __('Refresh the screen') }}</button></form>
                    <a href="{{ route('dash.tv.pair', ['screen' => $screen->id]) }}" class="btn-ghost min-h-10 px-3">{{ __('Pair another TV') }}</a>
                    <form method="post" action="{{ route('dash.tv.unpair', $screen) }}" onsubmit="return confirm(@js(__('Unpair this TV? It goes back to showing a code.')))">@csrf<button class="min-h-10 px-2 text-sm text-red-600">{{ __('Unpair') }}</button></form>
                </div>
            @else
                <ol class="list-decimal space-y-1 ps-5 text-sm text-navy-700">
                    <li>{!! __('On the TV, open the browser (or the EgHub app) and go to :url', ['url' => '<b dir="ltr">'.e(preg_replace('#^https?://#', '', platform_url(null, 'tv'))).'</b>']) !!}</li>
                    <li>{{ __('A 6-digit code shows on the TV.') }}</li>
                    <li>{{ __('Type it here, or scan the QR on the TV with your phone.') }}</li>
                </ol>
                <a href="{{ route('dash.tv.pair', ['screen' => $screen->id]) }}" class="btn-primary w-full justify-center" data-pair-link>{{ __('Pair a TV') }}</a>
            @endif
            @include('dashboard.partials.tv-limit', ['limit' => $limit, 'paired' => $paired])
        </section>

        {{-- the look --}}
        <section class="card" id="settings">
            <h2 class="section-title mb-3">{{ __('Screen settings') }}</h2>
            <form method="post" action="{{ route('dash.tv.settings', $screen) }}" class="grid gap-3">
                @csrf
                <div><label class="field-label">{{ __('Screen name') }}</label><input class="field" name="name" value="{{ $screen->name }}" required maxlength="60"></div>
                <div>
                    <label class="field-label">{{ __('How the TV hangs') }}</label>
                    <select class="field" name="orientation">@foreach ($orientations as $k => $v)<option value="{{ $k }}" @selected(($screen->orientation ?: 'landscape') === $k)>{{ $v }}</option>@endforeach</select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="field-label">{{ __('Colors') }}</label>
                        <select class="field" name="theme"><option value="dark" @selected($screen->theme !== 'light')>{{ __('Dark') }}</option><option value="light" @selected($screen->theme === 'light')>{{ __('Light') }}</option></select>
                    </div>
                    <div>
                        <label class="field-label">{{ __('Language') }}</label>
                        <select class="field" name="language">@foreach ($languages as $k => $v)<option value="{{ $k }}" @selected((string) $screen->language === $k)>{{ $v }}</option>@endforeach</select>
                    </div>
                </div>
                @if ($branches->count() > 1)
                    <div>
                        <label class="field-label">{{ __('Branch prices') }}</label>
                        <select class="field" name="branch_id"><option value="">{{ __('Main branch') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($screen->branch_id === $b->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach</select>
                    </div>
                @endif
                <div>
                    <label class="field-label">{{ __('Ticker at the bottom (optional)') }}</label>
                    <input class="field" name="ticker_text" value="{{ $screen->ticker_text }}" maxlength="300" placeholder="{{ __('e.g. Free Wi-Fi · Order from your table with the QR') }}">
                </div>
                @include('dashboard.partials.tv-hours', ['model' => $screen, 'title' => __('Screen hours (optional)')])
                <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($screen->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                <button class="btn-primary">{{ __('Save') }}</button>
            </form>
        </section>
    </div>
</div>

<script>
    // each slide form shows only the fields of its type
    document.querySelectorAll('[data-slide-form]').forEach((form) => {
        const sync = () => {
            const type = form.dataset.slideForm || form.querySelector('[name="type"]:checked')?.value;
            form.querySelectorAll('[data-for]').forEach((el) => {
                const on = el.dataset.for === type;
                el.hidden = !on;
                el.querySelectorAll('input, select').forEach((f) => { f.disabled = !on; });
            });
        };
        form.querySelectorAll('[name="type"]').forEach((r) => r.addEventListener('change', sync));
        sync();
    });
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
@endsection
