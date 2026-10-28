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
            'trail' => url('/drivers/orders/__ID__/trail'),
        ],
        'i18n' => [
            'noDrivers' => __('No active drivers yet. Add one from Users & roles with the «Delivery driver» role.'),
            'noWaiting' => __('Every delivery order has a driver.'),
            'free' => __('Not carrying orders now'), 'lastSeen' => __('Seen :time (:n min ago)', ['time' => '__T__', 'n' => '__N__']),
            'neverSeen' => __('No location yet'), 'deliveredToday' => __('Delivered today: :n', ['n' => '__N__']),
            'failedToday' => __('Failed: :n', ['n' => '__N__']), 'avgMinutes' => __('About :n min to the door', ['n' => '__N__']),
            'sinceAssigned' => __('with them :n min', ['n' => '__N__']),
            'holding' => __('Holds :amount (:n orders)', ['amount' => '__A__', 'n' => '__N__']),
            'collectedToday' => __('Collected today: :amount', ['amount' => '__A__']),
            'otherToday' => __('by card / InstaPay / wallet: :amount', ['amount' => '__A__']),
            'settle' => __('Settle the cash'), 'settleConfirm' => __('Tap again: received :amount', ['amount' => '__A__']), 'settleSure' => __('Did :name hand in :amount?', ['name' => '__D__', 'amount' => '__A__']),
            'chooseDriver' => __('Choose a driver…'), 'noDriver' => __('No driver'), 'failed' => __('Delivery failed'),
            'chooseTrail' => __('Delivered today: choose an order to see its trail…'), 'noTrail' => __('No positions were stored for this order.'),
            'trailOf' => __('Trail of #:n · :driver · :from → :to', ['n' => '__N__', 'driver' => '__D__', 'from' => '__F__', 'to' => '__T__']),
            'pickedUp' => __('Picked up'), 'delivered' => __('Delivered'), 'destination' => __('The address'),
            'minutes' => __(':n min', ['n' => '__N__']),
            'methods' => ['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')],
            'currency' => __('EGP'),
        ],
    ];
@endphp

<section class="card mb-4 p-3">
    <div id="drivers-map" class="h-[360px] overflow-hidden rounded-xl border border-navy-100" dir="ltr"></div>
    <div class="mt-2 flex flex-wrap items-center gap-2 px-1">
        <p class="flex min-w-0 flex-1 items-center gap-2 text-xs text-navy-500">
            <span class="size-2 animate-pulse rounded-full bg-emerald-500" data-live-dot></span>
            {{ __('Updates by itself every :n seconds. A driver shares the location only while an order is out for delivery.', ['n' => $poll]) }}
        </p>
        {{-- the trail of a delivered order of today, drawn on the same map --}}
        <div class="flex flex-wrap items-center gap-2">
            <select class="field min-h-9 w-auto max-w-xs text-sm" data-trail-pick aria-label="{{ __('Show the trail of an order') }}">
                <option value="">{{ __('Delivered today: choose an order to see its trail…') }}</option>
            </select>
            <button type="button" class="btn-ghost hidden min-h-9 px-3 text-sm" data-trail-clear>{{ __('Hide the trail') }}</button>
        </div>
    </div>
    <p class="mt-1 hidden px-1 text-xs font-semibold text-navy-700" data-trail-info></p>
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

{{-- the last 7 days per driver (today included): a plain table, the live numbers above are today's --}}
<section class="card mt-4 overflow-x-auto">
    <h2 class="section-title mb-3">{{ __('Last 7 days') }}</h2>
    @if ($week === [])
        <p class="py-4 text-center text-sm text-navy-500">{{ __('No active drivers yet. Add one from Users & roles with the «Delivery driver» role.') }}</p>
    @else
        <table class="w-full text-sm">
            <thead class="text-xs text-navy-500">
                <tr>
                    <th class="py-1 text-start font-semibold">{{ __('Driver') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Delivered') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Failed') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Minutes to the door') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Cash at the door') }}</th>
                    @if ($canSettle)<th class="py-1 text-end font-semibold">{{ __('Card / InstaPay / wallet') }}</th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($week as $r)
                    <tr class="border-t border-navy-50">
                        <td class="py-1.5 font-semibold">{{ $r['name'] }}</td>
                        <td class="py-1.5 text-end" dir="ltr">{{ $r['delivered'] }}</td>
                        <td class="py-1.5 text-end {{ $r['failed'] ? 'text-red-700' : '' }}" dir="ltr">{{ $r['failed'] }}</td>
                        <td class="py-1.5 text-end" dir="ltr">{{ $r['avg_minutes'] ?? '—' }}</td>
                        <td class="py-1.5 text-end" dir="ltr">{{ money($r['cash']) }}</td>
                        @if ($canSettle)<td class="py-1.5 text-end" dir="ltr">{{ money($r['other']) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<script type="application/json" id="drivers-data">@json($data)</script>
@endsection
