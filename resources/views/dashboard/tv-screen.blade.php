@extends('layouts.dashboard')
@section('title', $screen->name . ' · ' . __('TV screens'))

@section('content')
@php
    $info = $screen->device_info ?? [];
    $languages = ['' => __('The menu\'s default language'), 'ar' => __('Arabic'), 'en' => __('English'), 'both' => __('Arabic and English')];
    $follows = $screen->followsGroup();
@endphp
<x-dash.page-header :title="$screen->name" :help="__('What this screen plays, in order. Prices and sold-out items follow the menu by themselves.')">
    <x-slot:actions>
        <a href="{{ route('dash.devices', ['tab' => 'tv']) }}" class="btn-ghost">{{ __('All screens') }}</a>
        <a href="{{ route('dash.tv.alerts') }}" class="btn-ghost" data-urgent-link>{{ __('Urgent message') }}</a>
        <a href="{{ $screen->url() }}" target="_blank" rel="noopener" class="btn-ghost" data-preview>{{ __('Preview') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        @if ($follows)
            {{-- the screen plays its group's playlist: edit it there, or give the screen its own --}}
            <section class="card space-y-3 border-sky-200 bg-sky-50" id="playlist" data-follows-group="{{ $screen->group->id }}">
                <h2 class="section-title">{{ __('Playlist') }}</h2>
                <p class="text-sm text-navy-700">{!! __('This screen plays the playlist of the group :group, with its own branch prices and look. Edit the group\'s playlist to change every screen of the group at once.', ['group' => '<b>'.e($screen->group->name).'</b>']) !!}</p>
                <div class="flex flex-wrap gap-2">
                    @if (! auth()->user()->branch_id)
                        <a href="{{ route('dash.tv.groups.show', $screen->group) }}" class="btn-primary">{{ __('Edit the group playlist') }}</a>
                    @endif
                    <a href="#settings" class="btn-ghost">{{ __('Give this screen its own playlist') }}</a>
                </div>
            </section>
        @else
            @if ($screen->tv_group_id && $screen->group)
                <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900" data-own-in-group>{{ __('This screen is in the group :group but plays its own playlist below.', ['group' => $screen->group->name]) }}</p>
            @endif
            @include('dashboard.partials.tv-playlist', ['owner' => $screen])
        @endif
    </div>

    <div class="space-y-6">
        {{-- the device --}}
        <section class="card space-y-3" id="device" data-status="{{ $screen->status() }}">
            <h2 class="section-title">{{ __('The TV') }}</h2>
            <div data-tv-live="{{ $screen->id }}">
                @include('dashboard.partials.tv-now', ['screen' => $screen, 'nowSlides' => null])
            </div>
            @include('dashboard.partials.tv-device-line', ['screen' => $screen])
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
                @if ($groups->isNotEmpty())
                    <div class="rounded-xl border border-navy-100 p-3" data-group-fields>
                        <label class="field-label">{{ __('Group') }}</label>
                        <select class="field" name="tv_group_id">
                            <option value="">{{ __('No group') }}</option>
                            @foreach ($groups as $g)<option value="{{ $g->id }}" @selected($screen->tv_group_id === $g->id)>{{ $g->name }}</option>@endforeach
                        </select>
                        <div class="mt-2 grid gap-1 text-sm">
                            <label class="flex min-h-9 items-center gap-2"><input type="radio" name="playlist" value="group" @checked(! $screen->own_playlist) class="size-4 accent-brand-600"> {{ __('Play the group\'s playlist') }}</label>
                            <label class="flex min-h-9 items-center gap-2"><input type="radio" name="playlist" value="own" @checked($screen->own_playlist) class="size-4 accent-brand-600"> {{ __('Play its own playlist') }}</label>
                        </div>
                    </div>
                @endif
                <details class="rounded-xl border border-navy-100 p-3" id="shape" open>
                    <summary class="cursor-pointer text-sm font-bold text-navy-800">{{ __('Screen size and orientation') }}: <span class="font-semibold text-navy-500">{{ \App\Support\ScreenShape::summary($screen) }}</span></summary>
                    <div class="mt-3">@include('dashboard.partials.tv-shape-fields', ['screen' => $screen])</div>
                </details>
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
                @if ($branches->count() > 1 && ! auth()->user()->branch_id)
                    <div>
                        <label class="field-label">{{ __('Branch prices') }}</label>
                        <select class="field" name="branch_id"><option value="">{{ __('Main branch') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($screen->branch_id === $b->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach</select>
                    </div>
                @else
                    <input type="hidden" name="branch_id" value="{{ $screen->branch_id }}">
                @endif
                <div>
                    <label class="field-label">{{ __('Ticker at the bottom (optional)') }}</label>
                    <input class="field" name="ticker_text" value="{{ $screen->ticker_text }}" maxlength="300" placeholder="{{ __('e.g. Free Wi-Fi · Order from your table with the QR') }}">
                </div>
                <label class="flex min-h-10 items-start gap-2 text-sm" data-qr-toggle>
                    <input type="checkbox" name="show_qr" value="1" @checked($screen->show_qr) class="mt-0.5 size-4 accent-brand-600">
                    <span>{{ __('«Scan to order» QR in the corner') }}<span class="block text-xs text-navy-500">{{ __('Opens your online menu (this screen\'s branch). Shown at the top of menu, offer and ready-order slides and over pictures and videos.') }}</span></span>
                </label>
                @include('dashboard.partials.tv-hours', ['model' => $screen, 'title' => __('Screen hours (optional)')])
                <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($screen->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                <button class="btn-primary">{{ __('Save') }}</button>
            </form>
        </section>
    </div>
</div>
@include('dashboard.partials.tv-live-refresh')
@endsection
