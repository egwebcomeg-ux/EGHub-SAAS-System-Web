@extends('layouts.base', ['viteEntries' => ['resources/js/pager.js']])
@section('title', __('Order #:n', ['n' => $state['number']]) . ' · ' . __('Guest pager'))

@push('head')
<meta name="robots" content="noindex">
@include('menu.partials.theme')
<style>
    .pg-number { font-size: 4.5rem; line-height: 1; font-weight: 900; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .pg-step { display: flex; align-items: center; gap: .75rem; min-height: 2.5rem; }
    .pg-dot { display: grid; place-items: center; flex: none; width: 1.6rem; height: 1.6rem; border-radius: 9999px; border: 2px solid var(--menu-border); background: var(--menu-surface); color: transparent; font-size: .8rem; font-weight: 900; }
    .pg-step.is-done .pg-dot { background: var(--menu-accent); border-color: var(--menu-accent); color: var(--menu-accent-text); }
    .pg-step.is-current .pg-dot { background: var(--menu-surface); color: var(--menu-accent); animation: pg-pulse 2s ease-out infinite; }
    .pg-step-label { font-weight: 600; color: var(--menu-muted); }
    .pg-step.is-done .pg-step-label { color: var(--menu-text); }
    .pg-step.is-current .pg-step-label { font-weight: 800; }
    .pg-live { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; color: var(--menu-muted); }
    .pg-live::before { content: ''; width: .5rem; height: .5rem; border-radius: 9999px; background: #10B981; animation: pg-blink 2s ease-in-out infinite; }
    .pg-live.is-off::before { background: #EF4444; animation: none; }
    .pg-alert { position: fixed; inset: 0; z-index: 60; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1rem; padding: 1.5rem; text-align: center;
        background: var(--menu-accent); color: var(--menu-accent-text); }
    .pg-alert[hidden] { display: none; }
    .pg-alert-flash { animation: pg-flash 1s steps(2, jump-none) infinite; }
    .pg-alert .pg-number { font-size: 6.5rem; }
    .pg-bell { font-size: 4rem; line-height: 1; display: inline-block; animation: pg-ring 1s ease-in-out infinite; transform-origin: 50% 10%; }
    .pg-big-btn { display: flex; align-items: center; justify-content: center; gap: .5rem; width: 100%; max-width: 22rem; min-height: 4rem; border-radius: 1.25rem; font-size: 1.35rem; font-weight: 900;
        background: #fff; color: #0A2540; box-shadow: 0 10px 30px -10px rgba(0,0,0,.45); }
    .pg-link-btn { min-height: 2.75rem; padding: 0 1rem; border-radius: .875rem; font-weight: 700; background: rgba(255,255,255,.18); color: inherit; }
    .pg-star { font-size: 2.6rem; line-height: 1; color: var(--menu-border); min-width: 2.75rem; min-height: 2.75rem; }
    .pg-star.is-on { color: #F59E0B; }
    @keyframes pg-pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--menu-accent) 45%, transparent); } 70%, 100% { box-shadow: 0 0 0 .7rem transparent; } }
    @keyframes pg-blink { 50% { opacity: .35; } }
    @keyframes pg-flash { 0% { filter: none; } 100% { filter: brightness(1.25) saturate(1.2); } }
    @keyframes pg-ring { 0%, 100% { transform: rotate(0); } 20% { transform: rotate(14deg); } 40% { transform: rotate(-12deg); } 60% { transform: rotate(8deg); } 80% { transform: rotate(-4deg); } }
    @media (prefers-reduced-motion: reduce) {
        .pg-step.is-current .pg-dot, .pg-live::before, .pg-alert-flash, .pg-bell { animation: none; }
    }
</style>
@endpush

@section('body')
@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $i18n = [
        'ready' => __('Your order is ready!'), 'come' => __('Come and pick it up from the counter'),
        'tapToHear' => __('Tap anywhere to hear the alarm'), 'soundOn' => __('Sound is on ✓'), 'soundTest' => __('Test the sound'),
        'soundFail' => __('Your phone blocked the sound. Turn the volume up and tap “Test the sound” again.'),
        'awake' => __('The screen stays on while you wait.'), 'notifyOn' => __('Notifications are on ✓'),
        'offline' => __('No connection, trying again…'), 'live' => __('Updates by itself'),
        'titleReady' => __('🔔 Your order is ready!'), 'number' => __('Order #:n', ['n' => '__N__']),
        'sent' => __('Thank you for your feedback!'), 'tooMany' => __('Thank you! You already sent your rating.'),
    ];
@endphp
<main class="mx-auto flex min-h-dvh max-w-md flex-col px-4 pb-8 pt-5" id="pager"
      data-state='{{ json_encode($state, JSON_UNESCAPED_UNICODE) }}'
      data-key="{{ $key }}"
      data-state-url="{{ route('menu.pager.state', $key) }}"
      data-picked-url="{{ route('menu.pager.picked', $key) }}"
      data-rate-url="{{ route('menu.pager.rate', $key) }}"
      data-poll="{{ \App\Services\Orders\Pager::POLL_SECONDS }}"
      data-i18n='{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}'>
    <header class="flex items-center justify-center gap-3 text-center">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-11 shrink-0 rounded-xl bg-white object-contain p-1">@endif
        <div class="min-w-0">
            <div class="truncate text-base font-extrabold">{{ $brand }}</div>
            @if ($branch && \App\Models\Branch::query()->where('is_active', true)->count() > 1)<div class="truncate text-xs m-muted">{{ tr($branch->name, $branch->name_en) }}</div>@endif
        </div>
    </header>

    <section class="card mt-5 text-center" aria-live="polite">
        <div class="text-sm font-bold m-muted">{{ __('Order number') }}</div>
        <h1 class="pg-number mt-1" dir="ltr">#{{ $state['number'] }}</h1>
        <h2 class="mt-4 text-xl font-extrabold" data-headline>{{ $state['headline'] }}</h2>
        <p class="mt-1 text-sm m-muted" data-hint @if ($state['hint'] === '') hidden @endif>{{ $state['hint'] }}</p>

        <ol class="mx-auto mt-4 w-fit space-y-1 text-start" data-steps>
            @foreach ($state['steps'] as $step)
                <li class="pg-step {{ $step['done'] ? 'is-done' : '' }} {{ $step['current'] ? 'is-current' : '' }}" data-step="{{ $step['key'] }}">
                    <span class="pg-dot">✓</span><span class="pg-step-label">{{ $step['label'] }}</span>
                </li>
            @endforeach
        </ol>
        <div class="mt-4" data-live-box @if ($state['done']) hidden @endif><span class="pg-live" data-live>{{ __('Updates by itself') }}</span></div>
    </section>

    @if ($trackingUrl ?? null)
        <a href="{{ $trackingUrl }}" class="mt-3 block text-center text-sm font-semibold underline underline-offset-2 m-muted" data-tracking-link>{{ __('Order details and receipt') }}</a>
    @endif

    {{-- while waiting: unlock the sound (phones only play it after a tap), optional notifications, the honest note --}}
    <section class="mt-4 space-y-3" data-waiting @if ($state['done']) hidden @endif>
        <button type="button" class="btn-primary min-h-14 w-full text-lg" data-test-sound>🔔 {{ __('Test the sound') }}</button>
        <p class="hidden rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800" data-sound-fail></p>
        <button type="button" class="btn-ghost hidden min-h-12 w-full border" data-notify>🔔 {{ __('Also notify me (optional)') }}</button>
        <div class="rounded-2xl m-surface-2 p-4 text-sm leading-relaxed">
            <p class="font-bold">{{ __('So you hear the call') }}</p>
            <ul class="mt-2 list-disc space-y-1 ps-5 m-muted">
                <li>{{ __('Turn the volume up and tap “Test the sound” once.') }}</li>
                <li>{{ __('Leave the page open (you can lock the screen on most Android phones; on iPhone keep it on screen).') }}</li>
                <li>{{ __('No app and no account: it works in your browser.') }}</li>
            </ul>
            <p class="mt-2 hidden text-xs font-semibold text-emerald-700" data-awake></p>
        </div>
    </section>

    {{-- after the pickup: one tap; 4-5 → the Google review link, 1-3 → a private note to the restaurant --}}
    @if ($rating)
        <section class="card mt-4 text-center" data-rate @if (! $state['picked_up']) hidden @endif>
            <h2 class="text-lg font-extrabold">{{ __('How was it?') }}</h2>
            <div class="mt-2 flex justify-center gap-1" dir="ltr" data-stars>
                @for ($i = 1; $i <= 5; $i++)
                    <button type="button" class="pg-star" data-star="{{ $i }}" aria-label="{{ $i }} / 5">★</button>
                @endfor
            </div>
            <div class="mt-3 hidden space-y-2" data-rate-note>
                <label class="block text-sm font-bold" for="pg-note">{{ __('What went wrong? Only the restaurant reads this.') }}</label>
                <textarea id="pg-note" class="field min-h-24 py-2" maxlength="1000" data-comment></textarea>
                <button type="button" class="btn-primary min-h-12 w-full" data-send-note>{{ __('Send to the restaurant') }}</button>
            </div>
            <div class="mt-3 hidden space-y-3" data-rate-done>
                <p class="font-semibold text-emerald-700" data-rate-thanks>{{ __('Thank you for your feedback!') }}</p>
                <a href="#" target="_blank" rel="noopener" class="btn-ghost hidden min-h-12 w-full border" data-google>⭐ {{ __('Rate us on Google too') }}</a>
            </div>
        </section>
    @endif

    <p class="mt-auto pt-6 text-center text-xs m-muted">{{ __('No app needed · nothing personal is kept') }}</p>
    @include('menu.partials.powered-by', ['class' => 'mt-3 m-muted'])
</main>

{{-- the call: full screen, the restaurant's colour, a loud chime that repeats and the phone vibrating --}}
<div class="pg-alert" role="alertdialog" aria-modal="true" aria-labelledby="pg-alert-title" data-alert hidden>
    <span class="pg-bell" aria-hidden="true">🔔</span>
    <h2 id="pg-alert-title" class="text-3xl font-black">{{ __('Your order is ready!') }}</h2>
    <div class="pg-number" dir="ltr">#{{ $state['number'] }}</div>
    <p class="text-lg font-bold opacity-90">{{ __('Come and pick it up from the counter') }}</p>
    <p class="hidden rounded-xl bg-black/20 px-4 py-2 text-base font-bold" data-tap-hint>{{ __('Tap anywhere to hear the alarm') }}</p>
    <button type="button" class="pg-big-btn mt-2" data-picked>✓ {{ __('I picked it up') }}</button>
    <button type="button" class="pg-link-btn" data-silence>🔕 {{ __('Stop the sound, I am on my way') }}</button>
</div>
@endsection
