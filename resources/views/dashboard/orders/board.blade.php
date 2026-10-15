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
    ];
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ __('Orders') }}</h1>
    <div class="flex items-center gap-3 text-sm">
        <span class="flex items-center gap-2 text-navy-500"><span class="size-2 animate-pulse rounded-full bg-emerald-500" data-live-dot></span> {{ __('Live') }}</span>
        <button type="button" class="btn-ghost min-h-9 px-3" data-sound>🔔 {{ __('Enable sound') }}</button>
    </div>
</div>

<div class="mb-4 hidden flex-wrap gap-2" data-calls></div>
<div class="mb-4 hidden flex-wrap gap-2" data-reservations></div>

<div id="board" class="grid gap-4 lg:grid-cols-3"
     data-feed="{{ route('dash.orders.feed') }}"
     data-status-url="{{ url('/orders/__ID__/status') }}"
     data-call-url="{{ url('/orders/calls/__ID__') }}"
     data-reservation-url="{{ url('/reservations/__ID__/status') }}"
     data-poll="{{ $poll }}"
     data-can-manage="{{ auth()->user()->can('orders.manage') ? 1 : 0 }}"
     data-i18n='{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}'>
    @foreach (['new' => __('New'), 'preparing' => __('Preparing'), 'ready' => __('Ready')] as $status => $label)
        <section class="rounded-2xl bg-white p-3 shadow-sm ring-1 ring-navy-100">
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
