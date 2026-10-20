@extends('layouts.base', ['viteEntries' => ['resources/js/driver.js']])
@section('title', __('My deliveries'))
@section('body_class', 'bg-navy-50 text-navy-900')

@section('body')
@php
    $user = auth()->user();
    $data = [
        'orders' => $orders,
        'ping' => $ping,
        'currency' => $currency,
        'urls' => [
            'orders' => route('dash.driver.orders'),
            'location' => route('dash.driver.location'),
            'pickedUp' => url('/driver/orders/__ID__/picked-up'),
            'delivered' => url('/driver/orders/__ID__/delivered'),
            'failed' => url('/driver/orders/__ID__/failed'),
        ],
        'i18n' => [
            'empty' => __('No orders with you now. New ones show up here by themselves.'),
            'call' => __('Call'), 'navigate' => __('Navigate'), 'items' => __(':n items', ['n' => '__N__']),
            'collect' => __('Collect'), 'pickedUp' => __('I picked it up'), 'delivered' => __('Delivered'),
            'failed' => __('Did not deliver'), 'notReady' => __('Waiting for the kitchen'),
            'sure' => __('Are you sure?'), 'deliveredSure' => __('Delivered and collected :amount?', ['amount' => '__A__']),
            'pickedAt' => __('Picked up :time', ['time' => '__T__']), 'notes' => __('Notes'),
            'geoIdle' => __('Your location is shared only while an order is out for delivery with you.'),
            'geoAsk' => __('Allow location so the guest can follow you on the map.'),
            'geoOn' => __('Sharing your location with the guest'),
            'geoSent' => __('last sent :time', ['time' => '__T__']),
            'geoDenied' => __('Location is blocked. Allow it for this site in the browser settings, then reopen the page.'),
            'geoError' => __('Could not get your location. Check that GPS is on.'),
            'geoUnsupported' => __('This browser cannot share the location.'),
            'geoPaused' => __('Location paused while this page is in the background.'),
            'offline' => __('No connection. Retrying…'),
        ],
    ];
@endphp
<div class="mx-auto flex min-h-screen max-w-xl flex-col" id="driver-app">
    <header class="sticky top-0 z-20 flex items-center justify-between gap-3 bg-navy-800 px-4 py-3 text-white shadow">
        <div class="min-w-0 leading-tight">
            <div class="truncate text-lg font-black">{{ __('My deliveries') }}</div>
            <div class="truncate text-xs text-white/70">{{ $user->name }} · {{ tenant()->name }}</div>
        </div>
        <form method="post" action="{{ route('dash.logout') }}">
            @csrf
            <button class="min-h-11 rounded-xl bg-white/10 px-4 text-sm font-semibold hover:bg-white/20">{{ __('Log out') }}</button>
        </form>
    </header>

    {{-- where the location stands: idle / asking / sharing (with the last time sent) / blocked --}}
    <div class="sticky top-[4.25rem] z-10 flex min-h-12 items-center gap-3 border-b border-navy-100 bg-white px-4 py-2 text-sm font-semibold" role="status" aria-live="polite" data-geo>
        <span class="size-3 shrink-0 rounded-full bg-navy-200" data-geo-dot></span>
        <span class="min-w-0 flex-1" data-geo-text>{{ __('Your location is shared only while an order is out for delivery with you.') }}</span>
    </div>

    <main class="flex-1 space-y-4 px-4 py-4" data-driver-orders aria-live="polite"></main>

    <p class="hidden px-4 pb-4 text-center text-sm font-semibold text-red-700" data-offline>{{ __('No connection. Retrying…') }}</p>
</div>

{{-- why the delivery did not happen --}}
<dialog class="w-[min(28rem,calc(100vw-2rem))] rounded-2xl p-0 backdrop:bg-navy-900/60" data-fail-dialog>
    <form method="dialog" class="space-y-3 p-5" data-fail-form>
        <h2 class="text-lg font-black">{{ __('Why was it not delivered?') }}</h2>
        <div class="grid gap-2">
            @foreach ([__('The customer does not answer'), __('Wrong or incomplete address'), __('The customer refused the order'), __('The customer was not there')] as $reason)
                <label class="flex min-h-12 items-center gap-3 rounded-xl border border-navy-100 px-3 font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                    <input type="radio" name="preset" value="{{ $reason }}" class="size-5 accent-brand-600"> {{ $reason }}
                </label>
            @endforeach
        </div>
        <textarea class="field min-h-20 py-2" name="reason" maxlength="200" placeholder="{{ __('Other reason or details') }}"></textarea>
        <p class="hidden text-sm font-semibold text-red-700" data-fail-error>{{ __('Write why the delivery failed.') }}</p>
        <div class="grid grid-cols-2 gap-2">
            <button type="button" class="btn-ghost min-h-12" data-fail-cancel>{{ __('Back') }}</button>
            <button type="submit" class="btn-danger min-h-12" value="send">{{ __('Send') }}</button>
        </div>
    </form>
</dialog>

<script type="application/json" id="driver-data">@json($data)</script>
@endsection
