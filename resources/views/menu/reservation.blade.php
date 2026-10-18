@extends('layouts.base')
@section('title', __('Your booking') . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@php
    $r = $reservation;
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $tone = match ($r->status) {
        'confirmed', 'seated' => 'bg-emerald-50 text-emerald-800',
        'pending' => 'bg-amber-50 text-amber-800',
        default => 'bg-red-50 text-red-700',
    };
@endphp

@push('head')
@include('menu.partials.reserve-head', ['settings' => $settings])
@endpush

@section('body')
<header class="m-secondary">
    <div class="mx-auto flex max-w-xl items-center gap-3 px-4 py-4">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 shrink-0 rounded-xl bg-white object-contain p-1">@endif
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-xl font-black">{{ __('Your booking') }}</h1>
            <p class="truncate text-sm text-white/75">{{ $brand }}</p>
        </div>
    </div>
</header>

<main class="mx-auto max-w-xl space-y-4 px-4 py-5">
    <x-flash />

    <section class="card space-y-4 text-center">
        <span class="inline-block rounded-full px-4 py-1.5 text-sm font-extrabold {{ $tone }}">{{ $r->statusLabel() }}</span>
        <div>
            <div class="m-primary-text text-4xl font-black" dir="ltr">{{ local_time($r->starts_at, 'h:i A') }}</div>
            <div class="mt-1 text-lg font-bold">{{ $r->starts_at->locale(app()->getLocale())->translatedFormat('l j/n/Y') }}</div>
        </div>
        <dl class="grid grid-cols-2 gap-3 text-start text-sm">
            <div class="rounded-xl m-surface-2 p-3"><dt class="m-muted">{{ __('Guests') }}</dt><dd class="text-lg font-extrabold">{{ $r->party_size }}</dd></div>
            <div class="rounded-xl m-surface-2 p-3"><dt class="m-muted">{{ __('Name') }}</dt><dd class="truncate text-lg font-extrabold">{{ $r->name }}</dd></div>
            <div class="col-span-2 rounded-xl m-surface-2 p-3"><dt class="m-muted">{{ __('Branch') }}</dt>
                <dd class="font-bold">{{ tr($r->branch?->name, $r->branch?->name_en) }}@if ($r->branch?->address) · {{ $r->branch->address }}@endif</dd></div>
            @if ($r->notes)
                <div class="col-span-2 rounded-xl m-surface-2 p-3"><dt class="m-muted">{{ __('Notes') }}</dt><dd>{{ $r->notes }}</dd></div>
            @endif
        </dl>
        @if ($r->status === 'pending')
            <p class="text-sm m-muted">{{ __('The restaurant will confirm your booking soon. Keep this page to check it.') }}</p>
        @endif
        @if ($r->branch?->phone)
            <a href="tel:{{ $r->branch->phone }}" class="btn-ghost w-full">{{ __('Call the restaurant') }} <span dir="ltr">{{ $r->branch->phone }}</span></a>
        @endif
    </section>

    @if ($r->guestCanCancel())
        <form method="post" action="{{ route('menu.reserve.cancel', $r->token) }}" onsubmit="return confirm(@js(__('Cancel this booking?')))">
            @csrf
            <button class="w-full rounded-2xl border border-red-200 m-surface py-3 font-bold text-red-600">{{ __('Cancel my booking') }}</button>
        </form>
    @endif
    <a href="{{ route('menu.show') }}" class="btn-ghost w-full">{{ __('Open the menu') }}</a>
</main>
@endsection
