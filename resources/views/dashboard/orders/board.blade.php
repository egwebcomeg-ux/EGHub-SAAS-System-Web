@extends('layouts.dashboard', ['viteEntries' => ['resources/js/board.js']])
@section('title', __('Orders'))

@section('content')
@php
    $i18n = [
        'new' => __('New'), 'preparing' => __('Preparing'), 'ready' => __('Ready'), 'on_way' => __('On the way'),
        'served' => __('Served'), 'cancelled' => __('Cancelled'), 'table' => __('Table :name', ['name' => '__N__']),
        'cancel' => __('Cancel'), 'cancelReason' => __('Reason for cancelling?'), 'empty' => __('No orders'),
        'currency' => __('EGP'),
        'handled' => __('Done'), 'minutes' => __(':n min', ['n' => '__N__']),
        'booking' => __('Booking'), 'guests' => __(':n guests', ['n' => '__N__']), 'confirm' => __('Confirm'), 'seat' => __('Seated'),
        'noShow' => __('No-show'), 'sure' => __('Are you sure?'),
        'newOrdersTitle' => __('(:n) new orders', ['n' => '__N__']),
        'alarmOn' => __('Alarm on'), 'alarmOff' => __('Alarm muted'),
        'busyUntil' => __('Busy until :time', ['time' => '__T__']), 'busyOff' => __('Taking online orders'),
        'customTime' => __('Until when? (HH:MM)'),
        'foodReady' => __('Food ready'), 'drinksReady' => __('Drinks ready'),
        'partReady' => __('ready'), 'partWaiting' => __('not yet'), 'noMatch' => __('No orders match this filter'),
        'wasRef' => __('was :ref', ['ref' => '__R__']),
        'driver' => __('Driver'), 'chooseDriver' => __('Choose a driver…'), 'noDriver' => __('No driver'),
        'pager' => \App\Services\Orders\Pager::staffTexts(),
    ];
    $canManage = auth()->user()->can('orders.manage');
    // quick filters: [key, label]; the counts come live from the feed
    $chips = [
        ['all', __('All')], ['kitchen', __('In the kitchen')], ['ready', __('Ready')],
        ['dine_in', __('Dine-in')], ['takeaway', __('Takeaway')], ['delivery', __('Delivery')],
        ['food', '🍽 '.__('Food')], ['drinks', '☕ '.__('Drinks')],
    ];
@endphp
<div class="dash-head">
    <div class="min-w-0">
        <h1 class="page-title">{{ __('Orders') }}</h1>
        <p class="dash-help">{{ __('New orders appear here by themselves; move each one along until it is served.') }}</p>
    </div>
    <div class="dash-head-actions text-sm">
        <span class="flex items-center gap-2 text-navy-500"><span class="size-2 animate-pulse rounded-full bg-emerald-500" data-live-dot></span> {{ __('Live') }}</span>
        <button type="button" class="btn-ghost min-h-9 px-3" data-sound>🔔 {{ __('Enable sound') }}</button>
        <button type="button" class="btn-ghost min-h-9 px-3" data-alarm-mute title="{{ __('The alarm repeats while a new online or QR order waits to be accepted.') }}">🚨 {{ __('Alarm on') }}</button>
        @if ($kb = \App\Services\KnowledgeBase::canOpen(auth()->user()))
            <button type="button" class="btn-ghost min-h-9 px-3" data-kb-open title="F1">📖 {{ __('Knowledge base') }}</button>
        @endif
    </div>
</div>
@if ($kb)
    @include('dashboard.partials.kb-drawer')
@endif
@include('dashboard.orders.partials.tabs')

{{-- browsers only play sound after the first tap on the page --}}
<div class="mb-4 hidden items-center gap-3 rounded-2xl bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" data-audio-hint>
    <span>👆 {{ __('Tap anywhere on this page once so the browser allows the new-order alarm.') }}</span>
</div>

{{-- busy mode: pauses online / takeaway / delivery ordering; table orders go on --}}
<section class="card mb-4 flex flex-wrap items-center justify-between gap-3" data-busy-box data-busy-url="{{ route('dash.orders.busy') }}"
    data-can-manage="{{ $canManage ? 1 : 0 }}">
    <div class="flex items-center gap-3">
        <span class="grid size-10 place-items-center rounded-xl text-xl" data-busy-icon>🟢</span>
        <div>
            <div class="font-bold" data-busy-label>{{ $busy ? __('Busy until :time', ['time' => $busy['until']]) : __('Taking online orders') }}</div>
            <div class="text-xs text-navy-500">{{ __('Busy mode pauses online, pickup and delivery orders from the menu link; table orders keep coming.') }}</div>
        </div>
    </div>
    @if ($canManage)
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap gap-2" data-busy-presets>
                <button type="button" class="btn-ghost min-h-10 px-3" data-busy="minutes" data-value="30">{{ __("We're busy now") }} · 30 {{ __('min') }}</button>
                <button type="button" class="btn-ghost min-h-10 px-3" data-busy="minutes" data-value="60">1 {{ __('hour') }}</button>
                <button type="button" class="btn-ghost min-h-10 px-3" data-busy="time" data-value="21:00">{{ __('Until 21:00') }}</button>
                <button type="button" class="btn-ghost min-h-10 px-3" data-busy="custom">{{ __('Custom…') }}</button>
            </div>
            <button type="button" class="hidden min-h-10 rounded-xl bg-emerald-600 px-4 font-bold text-white" data-busy="off">{{ __("We're back") }}</button>
            <input class="field hidden max-w-xs" maxlength="200" data-busy-message placeholder="{{ __('Message for guests (optional)') }}">
        </div>
    @endif
</section>

<div class="mb-4 hidden flex-wrap gap-2" data-calls></div>
<div class="mb-4 hidden flex-wrap gap-2" data-reservations></div>

{{-- quick filters with live counts (remembered on this device) --}}
<div class="mb-3 max-w-sm">
    <input type="search" class="field" data-board-search maxlength="24" placeholder="{{ __('Search: order number or OFF-K1-3') }}" aria-label="{{ __('Search orders') }}">
</div>
<div class="-mx-1 mb-4 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:none] sm:flex-wrap sm:overflow-visible" data-chips role="toolbar" aria-label="{{ __('Filter orders') }}">
    @foreach ($chips as [$key, $label])
        <button type="button" data-chip="{{ $key }}" aria-pressed="false"
            class="group flex min-h-10 shrink-0 items-center gap-2 rounded-full bg-white px-4 text-sm font-bold text-navy-700 shadow-sm ring-1 ring-navy-100 transition hover:ring-brand-200 aria-pressed:bg-navy-800 aria-pressed:text-white aria-pressed:ring-navy-800">
            <span>{{ $label }}</span>
            <span class="min-w-6 rounded-full bg-navy-100 px-1.5 text-center text-xs tabular-nums text-navy-700 group-aria-pressed:bg-brand-600 group-aria-pressed:text-white" data-chip-count="{{ $key }}">0</span>
        </button>
    @endforeach
</div>

<div id="board" class="grid gap-4 lg:grid-cols-3"
     data-feed="{{ route('dash.orders.feed') }}"
     data-status-url="{{ url('/orders/__ID__/status') }}"
     data-part-url="{{ url('/orders/__ID__/part-ready') }}"
     data-driver-url="{{ url('/orders/__ID__/driver') }}"
     data-call-url="{{ url('/orders/calls/__ID__') }}"
     data-reservation-url="{{ url('/reservations/__ID__/status') }}"
     data-poll="{{ $poll }}"
     data-can-manage="{{ $canManage ? 1 : 0 }}"
     data-busy='{{ json_encode($busy, JSON_UNESCAPED_UNICODE) }}'
     data-i18n='{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}'>
    @foreach (['new' => __('New'), 'preparing' => __('Preparing'), 'ready' => __('Ready')] as $status => $label)
        <section class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-navy-100" data-section="{{ $status }}">
            <h2 class="mb-3 flex items-center justify-between px-1 font-bold">
                <span>{{ $label }}</span>
                <span class="badge bg-navy-100 text-navy-700" data-count="{{ $status }}">0</span>
            </h2>
            <div class="space-y-3" data-column="{{ $status }}"></div>
        </section>
    @endforeach
</div>

<details class="card mt-4">
    <summary class="cursor-pointer font-bold">{{ __('Finished (last 3 hours)') }}</summary>
    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-column="done"></div>
</details>
@endsection
