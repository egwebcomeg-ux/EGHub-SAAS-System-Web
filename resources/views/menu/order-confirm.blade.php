@extends('layouts.base')
@section('title', __('Confirm your order') . ' #' . $order->number . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@php
    $primary = is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#F26522';
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $currency = tr($settings['currency'], $settings['currency_en']);
    $address = (array) ($order->delivery_address ?? []);
    $open = $state === 'pending' || $state === 'no_reply';
    $closed = in_array($order->status, ['served', 'cancelled'], true);
@endphp

@push('head')
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $primary }}">
@include('menu.partials.theme')
@endpush

@section('body')
{{-- The guest's answer to "confirm your cash-on-delivery order": the same three choices as the WhatsApp buttons. --}}
<main class="mx-auto max-w-md px-4 py-6" data-order-confirm="{{ $order->number }}">
    <div class="rounded-3xl bg-white p-5 text-navy-900 shadow-lg ring-1 ring-black/5">
        <div class="flex items-center gap-3">
            @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 rounded-xl object-contain">@endif
            <div class="min-w-0">
                <div class="truncate text-lg font-extrabold">{{ $brand }}</div>
                <div class="text-sm text-navy-500">{{ tr($order->branch?->name, $order->branch?->name_en) }}</div>
            </div>
        </div>

        <div class="mt-4 border-b border-dashed border-navy-200 pb-3">
            <div class="text-sm text-navy-500">{{ __('Your order') }}</div>
            <div class="text-3xl font-extrabold">#{{ $order->number }}</div>
        </div>
        <ul class="divide-y divide-navy-50">
            @foreach ($order->items as $i)
                <li class="flex justify-between gap-3 py-2">
                    <div><b>{{ $i->quantity }}×</b> {{ $i->name }}</div>
                    <div class="shrink-0 font-semibold">{{ money($i->line_total) }}</div>
                </li>
            @endforeach
        </ul>
        <dl class="mt-2 space-y-1 border-t border-dashed border-navy-200 pt-3 text-sm">
            <div class="flex justify-between text-lg font-extrabold"><dt>💰 {{ __('Cash on delivery') }}</dt><dd>{{ money($order->total) }} {{ $currency }}</dd></div>
            @if ($address)
                <div class="text-navy-600">📍 {{ trim(($address['zone'] ?? '').' - '.($address['text'] ?? ''), ' -') }}</div>
            @endif
        </dl>

        @if ($answer)
            <p class="mt-4 rounded-2xl bg-emerald-50 p-3 text-center font-semibold text-emerald-800" role="status" data-confirm-answer>{{ $answer }}</p>
        @elseif ($closed || $state === null)
            <p class="mt-4 rounded-2xl bg-navy-50 p-3 text-center font-semibold text-navy-700">{{ __('This order does not need a confirmation any more.') }}</p>
        @elseif (! $open)
            <p class="mt-4 rounded-2xl bg-navy-50 p-3 text-center font-semibold text-navy-700" data-confirm-state>{{ __('We have your answer:') }} {{ \App\Services\Orders\OrderConfirmation::label($state) }}</p>
        @endif

        @if ($open && ! $closed && ! $answer)
            <p class="mt-4 text-center text-sm text-navy-600">{{ __('We are preparing it now. All good?') }}</p>
            <form method="post" class="mt-3 grid gap-2">
                @csrf
                <button name="action" value="confirm" class="m-primary flex min-h-12 items-center justify-center rounded-2xl text-lg font-bold">✅ {{ __('Confirm order') }}</button>
                <div class="grid grid-cols-2 gap-2">
                    <button name="action" value="edit" class="min-h-12 rounded-2xl bg-navy-50 font-bold text-navy-800 ring-1 ring-navy-100">✏️ {{ __('Edit order') }}</button>
                    <button name="action" value="cancel" class="min-h-12 rounded-2xl bg-white font-bold text-red-700 ring-1 ring-red-200">✋ {{ __('Cancel order') }}</button>
                </div>
            </form>
        @endif
    </div>
    <a href="{{ tenant()->url('o/'.$order->tracking_token) }}" class="mt-4 flex min-h-12 items-center justify-center rounded-2xl bg-white font-bold text-navy-800 ring-1 ring-navy-100">{{ __('Follow your order') }}</a>
</main>
@endsection
