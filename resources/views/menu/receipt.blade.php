@extends('layouts.base')
@section('title', __('Receipt') . ' #' . $order->number . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@php
    $primary = is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#F26522';
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $currency = tr($settings['currency'], $settings['currency_en']);
    $label = fn (string $m) => \App\Billing\PaymentMethods::label($m);
@endphp

@push('head')
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $primary }}">
@include('menu.partials.theme')
@endpush

@section('body')
{{-- One receipt, read only: what was bought and paid. Never the guest's name, phone or address. --}}
<main class="mx-auto max-w-md px-4 py-6" data-public-receipt="{{ $order->number }}">
    <div class="rounded-3xl bg-white p-5 text-navy-900 shadow-lg ring-1 ring-black/5">
        <div class="flex items-center gap-3">
            @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 rounded-xl object-contain">@endif
            <div class="min-w-0">
                <div class="truncate text-lg font-extrabold">{{ $brand }}</div>
                <div class="text-sm text-navy-500">{{ tr($order->branch?->name, $order->branch?->name_en) }}</div>
            </div>
        </div>
        <div class="mt-4 flex items-end justify-between border-b border-dashed border-navy-200 pb-3">
            <div>
                <div class="text-sm text-navy-500">{{ __('Receipt') }}</div>
                <div class="text-3xl font-extrabold">#{{ $order->number }}</div>
                @if ($order->offline_ref)<div class="text-xs text-navy-500">({{ __('was :ref', ['ref' => $order->offline_ref]) }})</div>@endif
            </div>
            <div class="text-end text-sm text-navy-500">
                {{ local_time($order->created_at) }}
                <div>{{ \App\Models\Order::TYPES[$order->type][app()->getLocale() === 'en' ? 1 : 0] ?? '' }}</div>
            </div>
        </div>
        <ul class="divide-y divide-navy-50">
            @foreach ($order->items as $i)
                <li class="flex justify-between gap-3 py-2">
                    <div><b>{{ $i->quantity }}×</b> {{ $i->name }}
                        @if ($i->options)<div class="text-xs text-navy-500">{{ collect($i->options)->pluck('name')->implode('، ') }}</div>@endif</div>
                    <div class="shrink-0 font-semibold">{{ money($i->line_total) }}</div>
                </li>
            @endforeach
        </ul>
        <dl class="mt-2 space-y-1 border-t border-dashed border-navy-200 pt-3 text-sm">
            @if ($order->discount > 0 || $order->service > 0 || $order->tax > 0 || $order->delivery_fee > 0)
                <div class="flex justify-between"><dt>{{ __('Subtotal') }}</dt><dd>{{ money($order->subtotal) }}</dd></div>
            @endif
            @if ($order->discount > 0)<div class="flex justify-between text-emerald-700"><dt>{{ __('Discount') }}</dt><dd>−{{ money($order->discount) }}</dd></div>@endif
            @if ($order->service > 0)<div class="flex justify-between"><dt>{{ __('Service') }}</dt><dd>{{ money($order->service) }}</dd></div>@endif
            @if ($order->tax > 0)<div class="flex justify-between"><dt>{{ __('Tax') }}</dt><dd>{{ money($order->tax) }}</dd></div>@endif
            @if ($order->delivery_fee > 0)<div class="flex justify-between"><dt>{{ __('Delivery fee') }}</dt><dd>{{ money($order->delivery_fee) }}</dd></div>@endif
            <div class="flex justify-between text-lg font-extrabold"><dt>{{ __('Total') }}</dt><dd>{{ money($order->total) }} {{ $currency }}</dd></div>
            @if ($order->payment_status === 'paid')
                @foreach (\App\Services\Orders\ShiftService::split($order) as $method => $amount)
                    <div class="flex justify-between text-navy-600"><dt>{{ __('Paid by :method', ['method' => $label($method)]) }}</dt><dd>{{ money($amount) }}</dd></div>
                @endforeach
            @else
                <div class="text-navy-600">{{ __('Unpaid') }}</div>
            @endif
            @if ($order->refunded_amount > 0)
                <div class="flex justify-between font-semibold text-red-700" data-public-refund><dt>{{ __('Refunded') }}</dt><dd>−{{ money($order->refunded_amount) }}</dd></div>
            @endif
        </dl>
        <p class="mt-4 text-center text-sm font-semibold">{{ __('Thank you for your visit!') }}</p>
    </div>
    <a href="{{ tenant()->url() }}" class="m-primary mt-4 flex min-h-12 items-center justify-center rounded-2xl font-bold">{{ __('Open the menu') }}</a>
</main>
@endsection
