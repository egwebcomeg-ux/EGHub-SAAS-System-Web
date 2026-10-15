@extends('layouts.base')
@section('title', __('Gift card') . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'bg-navy-50 text-navy-900')

@php
    $primary = is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#E8642C';
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $status = $card->statusKey();
@endphp

@push('head')
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $primary }}">
<style>:root { --menu-primary: {{ $primary }}; }</style>
@endpush

@section('body')
<main class="mx-auto max-w-md px-4 py-6">
    <div class="relative overflow-hidden rounded-3xl p-6 text-white shadow-lg" style="background: linear-gradient(135deg, var(--menu-primary), #141f38)">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-11 rounded-xl bg-white object-contain p-1">@endif
                <div class="font-extrabold">{{ $brand }}</div>
            </div>
            <span class="text-3xl" aria-hidden="true">🎁</span>
        </div>
        <div class="mt-8 text-sm text-white/80">{{ __('Gift card') }}</div>
        <div class="text-4xl font-black" data-amount>{{ money($card->amount) }} <span class="text-xl font-bold">{{ tr($settings['currency'], $settings['currency_en']) }}</span></div>
        @if ($card->message)<p class="mt-3 text-lg italic">“{{ $card->message }}”</p>@endif
        <div class="mt-6 flex items-end justify-between gap-3 text-sm">
            <span dir="ltr" class="font-mono font-bold tracking-widest">{{ $card->formattedCode() }}</span>
            @if ($card->expires_at && $status === 'active')<span class="text-white/80">{{ __('Valid until :date', ['date' => local_time($card->expires_at, 'Y/m/d')]) }}</span>@endif
        </div>
    </div>

    <div class="card mt-5">
        <x-flash />
        @if ($status === 'active')
            <h1 class="section-title">{{ __('Add it to my balance') }}</h1>
            <p class="mt-1 mb-4 text-sm text-navy-600">{{ __('Enter your mobile number: the amount goes to your wallet at :brand and you pay with it on your next order, in the shop or online.', ['brand' => $brand]) }}</p>
            <form method="post" action="{{ route('menu.gift.redeem', $card->code) }}" class="space-y-3">
                @csrf
                <input class="field" name="phone" value="{{ old('phone', $card->recipient_phone) }}" required inputmode="tel" dir="ltr" placeholder="{{ __('Mobile number') }} 01xxxxxxxxx" autocomplete="tel">
                <input class="field" name="name" value="{{ old('name') }}" maxlength="100" placeholder="{{ __('Your name (optional)') }}" autocomplete="name">
                <button class="m-primary min-h-12 w-full rounded-2xl font-bold text-white" style="background: var(--menu-primary)">{{ __('Add it to my balance') }}</button>
            </form>
        @elseif (! session('ok'))
            <p class="text-center font-bold" data-gift-status>
                @if ($status === 'redeemed')
                    {{ __('This gift card was already added to a wallet.') }}
                @elseif ($status === 'expired')
                    {{ __('This gift card has expired.') }}
                @else
                    {{ __('This gift card is no longer valid.') }}
                @endif
            </p>
        @endif
    </div>

    <a href="{{ route('menu.show') }}" class="btn-ghost mt-4 w-full">{{ __('Open the menu') }}</a>
</main>
@endsection
