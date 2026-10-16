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
    ];
    $canManage = auth()->user()->can('orders.manage');
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ __('Orders') }}</h1>
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="flex items-center gap-2 text-navy-500"><span class="size-2 animate-pulse rounded-full bg-emerald-500" data-live-dot></span> {{ __('Live') }}</span>
        <button type="button" class="btn-ghost min-h-9 px-3" data-sound>🔔 {{ __('Enable sound') }}</button>
        <button type="button" class="btn-ghost min-h-9 px-3" data-alarm-mute title="{{ __('The alarm repeats while a new online or QR order waits to be accepted.') }}">🚨 {{ __('Alarm on') }}</button>
    </div>
</div>

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

<div id="board" class="grid gap-4 lg:grid-cols-3"
     data-feed="{{ route('dash.orders.feed') }}"
     data-status-url="{{ url('/orders/__ID__/status') }}"
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
