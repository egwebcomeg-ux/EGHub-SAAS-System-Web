@extends('layouts.base')
@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
@endphp
@section('title', __('My account') . ' | ' . $brand)
@section('body_class', 'm-surface-2 m-text')

@push('head')
@include('menu.partials.theme')
@endpush

@section('body')
<main class="mx-auto max-w-md px-4 pb-10 pt-6" data-account-sign-in-page>
    <header class="mb-5 flex items-center gap-3">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 shrink-0 rounded-2xl bg-white object-contain p-1 shadow-sm">@endif
        <div class="min-w-0">
            <div class="truncate text-sm font-bold m-muted">{{ $brand }}</div>
            <h1 class="text-2xl font-black">{{ __('My account') }}</h1>
        </div>
    </header>
    <p class="mb-4 text-sm m-muted">{{ __('Your orders, order again in one tap, your addresses and your points, on any phone.') }}</p>
    @include('menu.partials.account-sign-in')
    <a href="{{ route('menu.show') }}" class="btn-ghost mt-5 w-full min-h-12">{{ __('Back to the menu') }}</a>
    @include('menu.partials.powered-by', ['class' => 'mt-6 m-muted'])
</main>
@endsection
