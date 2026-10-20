@extends('layouts.dashboard', ['viteEntries' => ['resources/js/drivers.js']])
@section('title', __('Drivers'))

@section('content')
<x-dash.page-header :title="__('Drivers')" :help="__('Where each driver is, what they carry and the cash they hold. Give the delivery orders waiting to a driver; add drivers from Users & roles with the «Delivery driver» role.')" />

@include('partials.leaflet')
@php
    $data = [
        'poll' => $poll,
        'center' => $center,
        'canAssign' => $canAssign,
        'canSettle' => $canSettle,
        'urls' => [
            'feed' => route('dash.drivers.feed'),
            'assign' => url('/orders/__ID__/driver'),
            'settle' => url('/drivers/__ID__/settle'),
        ],
        'i18n' => [
            'noDrivers' => __('No active drivers yet. Add one from Users & roles with the «Delivery driver» role.'),
            'noWaiting' => __('Every delivery order has a driver.'),
            'free' => __('Not carrying orders now'), 'lastSeen' => __('Seen :time (:n min ago)', ['time' => '__T__', 'n' => '__N__']),
            'neverSeen' => __('No location yet'), 'deliveredToday' => __('Delivered today: :n', ['n' => '__N__']),
            'holding' => __('Holds :amount (:n orders)', ['amount' => '__A__', 'n' => '__N__']),
            'collectedToday' => __('Collected today: :amount', ['amount' => '__A__']),
            'settle' => __('Settled'), 'settleSure' => __('Did :name hand in :amount?', ['name' => '__D__', 'amount' => '__A__']),
            'chooseDriver' => __('Choose a driver…'), 'noDriver' => __('No driver'), 'failed' => __('Delivery failed'),
            'currency' => __('EGP'),
        ],
    ];
@endphp

<section class="card mb-4 p-3">
    <div id="drivers-map" class="h-[360px] overflow-hidden rounded-xl border border-navy-100" dir="ltr"></div>
    <p class="mt-2 flex items-center gap-2 px-1 text-xs text-navy-500">
        <span class="size-2 animate-pulse rounded-full bg-emerald-500" data-live-dot></span>
        {{ __('Updates by itself every :n seconds. A driver shares the location only while an order is out for delivery.', ['n' => $poll]) }}
    </p>
</section>

<div class="grid gap-4 lg:grid-cols-2">
    <section class="card">
        <h2 class="section-title mb-3">{{ __('Drivers now') }}</h2>
        <div class="space-y-3" data-drivers></div>
    </section>
    <section class="card">
        <h2 class="section-title mb-3">{{ __('Waiting for a driver') }}</h2>
        <div class="space-y-3" data-waiting></div>
    </section>
</div>

<script type="application/json" id="drivers-data">@json($data)</script>
@endsection
