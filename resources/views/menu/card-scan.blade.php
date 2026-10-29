@extends('layouts.base')
@section('title', __('Loyalty card') . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@php
    $primary = is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#F26522';
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
@endphp

@push('head')
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $primary }}">
@include('menu.partials.theme')
@endpush

@section('body')
{{-- What a card's QR opens: the code for the counter, nothing of the guest's (no name, no balance, no account). --}}
<main class="mx-auto max-w-md px-4 py-10 text-center" data-card-scan>
    @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="mx-auto mb-3 size-16 rounded-2xl bg-white object-contain p-1">@endif
    <h1 class="text-xl font-extrabold">{{ $brand }}</h1>
    <p class="mt-2 text-sm opacity-70">{{ __('Show this code at the counter to collect your stamps and rewards.') }}</p>
    <div class="mt-6 rounded-2xl bg-white p-5 font-mono text-2xl font-extrabold tracking-widest text-navy-900 shadow-sm" dir="ltr" data-scan-code>{{ trim(chunk_split(strtoupper($code), 4, ' ')) }}</div>
</main>
@endsection
