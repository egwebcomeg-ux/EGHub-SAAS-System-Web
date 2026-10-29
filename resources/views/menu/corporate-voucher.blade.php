@extends('layouts.base')
@section('title', __('Corporate voucher') . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@php
    $primary = is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#F26522';
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $currency = tr($settings['currency'], $settings['currency_en']);
    $state = $voucher->statusKey();
    $contract = $voucher->contract;
    $usable = $state === 'active' && $contract->status === 'active';
    $mine = $me && $voucher->customer_id === $me->id;
@endphp

@push('head')
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $primary }}">
@include('menu.partials.theme')
@endpush

@section('body')
{{-- One voucher only: its value, balance and expiry. Never a list, never another guest's data. --}}
<main class="mx-auto max-w-md px-4 py-6">
    <div class="relative overflow-hidden rounded-3xl p-6 text-white shadow-lg" style="background: linear-gradient(135deg, var(--menu-accent), #061a2e)" data-corporate-card>
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-11 rounded-xl bg-white object-contain p-1">@endif
                <div class="truncate font-extrabold">{{ $brand }}</div>
            </div>
            <span class="text-3xl" aria-hidden="true">🏢</span>
        </div>
        <div class="mt-6 text-sm text-white/80">{{ __('Corporate voucher') }} · {{ $contract->name }}</div>
        <div class="text-4xl font-black" data-balance>{{ money($voucher->balance) }} <span class="text-xl font-bold">{{ $currency }}</span></div>
        @if ($voucher->balance != $voucher->value)<div class="text-sm text-white/80">{{ __('Balance left of :value', ['value' => money($voucher->value)]) }}</div>@endif
        <div class="mt-6 flex flex-wrap items-end justify-between gap-3 text-sm">
            <span dir="ltr" class="font-mono font-bold tracking-widest">{{ $voucher->serial }}</span>
            <span class="text-white/90" data-expiry>{{ $state === 'expired' ? __('Expired on :date.', ['date' => local_time($voucher->expires_on, 'Y/m/d')]) : __('Valid until :date', ['date' => local_time($voucher->expires_on, 'Y/m/d')]) }}</span>
        </div>
    </div>

    <div class="card mt-5 space-y-3">
        <x-flash />
        <div class="flex flex-wrap gap-2 text-sm">
            <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $usable, 'bg-navy-100 text-navy-600' => ! $usable]) data-voucher-state="{{ $state }}">{{ $voucher->statusLabel() }}</span>
            <span class="badge m-surface-2 m-muted">{{ $voucher->modeLabel() }}</span>
            @if ($contract->windowLabel() !== '')<span class="badge m-surface-2 m-muted">{{ $contract->windowLabel() }}</span>@endif
            @if ($contract->min_order)<span class="badge m-surface-2 m-muted">{{ __('Minimum order :amount', ['amount' => money($contract->min_order)]) }}</span>@endif
        </div>
        <p class="text-sm m-muted">{{ __('Pay with it at the counter: the cashier scans the code. Whatever the voucher doesn\'t cover you pay as usual.') }}</p>

        @if ($voucher->customer_id)
            <div class="rounded-2xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-900 ring-1 ring-emerald-200" data-linked>
                🔒 {{ __('Linked to the mobile :phone: it works only with that number.', ['phone' => \App\Models\CorporateVoucher::maskPhone($voucher->customer?->phone)]) }}
                @if ($mine)<a href="{{ route('menu.card', $me->card_token) }}" class="mt-2 block underline">{{ __('Open my vouchers') }}</a>@endif
            </div>
        @elseif ($usable)
            <h1 class="section-title">{{ __('Keep it on your mobile') }}</h1>
            <p class="text-sm m-muted">{{ __('Linked to your mobile, a lost paper can\'t be used by anyone else: at the counter you just give your number.') }}</p>
            @if ($voucher->pending_phone)
                <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800" data-pending>{{ __('Waiting for the cashier to confirm the link to :phone.', ['phone' => \App\Models\CorporateVoucher::maskPhone($voucher->pending_phone)]) }}</p>
            @endif
            @if ($me)
                <form method="post" action="{{ route('menu.voucher.link', $voucher->code) }}">
                    @csrf
                    <button class="m-primary min-h-12 w-full rounded-2xl font-bold" data-link-mine>{{ __('Link to my mobile :phone', ['phone' => \App\Models\CorporateVoucher::maskPhone($me->phone)]) }}</button>
                </form>
            @else
                <form method="post" action="{{ route('menu.voucher.link', $voucher->code) }}" class="space-y-3">
                    @csrf
                    <input class="field" name="phone" value="{{ old('phone') }}" required inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx" aria-label="{{ __('Mobile number') }}" autocomplete="tel">
                    <button class="m-primary min-h-12 w-full rounded-2xl font-bold" data-link-request>{{ __('Ask to link it to my mobile') }}</button>
                    <p class="text-xs m-muted">{{ __('The cashier confirms the link with you on your next visit.') }}</p>
                </form>
            @endif
        @endif
    </div>

    <a href="{{ route('menu.show') }}" class="btn-ghost mt-4 w-full">{{ __('Open the menu') }}</a>
</main>
@endsection
