@extends('layouts.base')
@section('title', 'EgMenu — ' . __('Digital QR menu for restaurants and cafes'))
@section('body_class', 'bg-white text-navy-900')

@section('body')
<header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5">
    <x-logo :size="36" />
    <div class="flex items-center gap-3 text-sm font-semibold">
        <a href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</a>
        <a href="{{ platform_url('app', '/login') }}" class="btn-ghost">{{ __('Restaurant login') }}</a>
        <a href="{{ route('register') }}" class="btn-primary">{{ __('Start free') }}</a>
    </div>
</header>

<section class="mx-auto max-w-6xl px-4 py-12 text-center">
    <h1 class="mx-auto max-w-3xl text-4xl font-extrabold leading-tight sm:text-5xl">{{ __('Digital QR menu for restaurants and cafes') }}</h1>
    <p class="mx-auto mt-4 max-w-2xl text-lg text-navy-500">{{ __('Table ordering, kitchen display, POS and a loyalty card — in one simple monthly subscription.') }}</p>
    <a href="{{ route('register') }}" class="btn-primary mt-6 min-h-12 px-6 text-base">{{ __('Start your :days-day free trial', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</a>
</section>

<section class="mx-auto grid max-w-7xl gap-4 px-4 pb-16 md:grid-cols-2 xl:grid-cols-4">
    @foreach ($plans as $p)
        <div @class(['card relative flex flex-col', 'ring-2 ring-brand-500' => $p->is_featured])>
            @if ($p->is_featured)<span class="badge absolute -top-3 start-5 bg-brand-600 text-white">{{ __('Most popular') }}</span>@endif
            <h2 class="text-xl font-extrabold">{{ $p->displayName() }}</h2>
            <p class="mt-1 min-h-10 text-sm text-navy-500">{{ tr($p->tagline, $p->tagline_en) }}</p>
            <div class="my-4">
                @if ($p->original_price)<span class="me-2 text-navy-500 line-through">{{ money($p->original_price) }}</span>@endif
                <span class="text-4xl font-extrabold">{{ money($p->monthly_price) }}</span>
                <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span>
            </div>
            <div class="flex-1">@include('partials.plan-features', ['plan' => $p])</div>
            <a href="{{ route('register') }}" class="{{ $p->is_featured ? 'btn-primary' : 'btn-ghost' }} mt-6 w-full">{{ __('Start free') }}</a>
        </div>
    @endforeach
</section>

@if ($addons->isNotEmpty())
<section class="mx-auto max-w-6xl px-4 pb-16">
    <h2 class="text-center text-2xl font-extrabold">{{ __('Add-ons for bigger restaurants') }}</h2>
    <p class="mx-auto mt-2 mb-6 max-w-2xl text-center text-navy-500">{{ __('Start with the menu. When you grow, add only what you need, on top of any plan.') }}</p>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($addons as $addon)
            <div class="card">
                <h3 class="font-bold">{{ $addon->displayName() }}</h3>
                <p class="mt-1 min-h-14 text-sm text-navy-500">{{ tr($addon->description, $addon->description_en) }}</p>
                <div class="mt-3"><b class="text-xl">+{{ money($addon->monthly_price) }}</b> <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span></div>
            </div>
        @endforeach
    </div>
</section>
@endif

<footer class="border-t border-navy-100 py-6 text-center text-sm text-navy-500">© {{ date('Y') }} EgMenu · {{ __('by') }} EgWeb</footer>
@endsection
