@extends('layouts.base')
@section('body_class', 'bg-white text-navy-900')

@php
    $viteEntries = ['resources/css/landing.css', 'resources/js/landing.js'];
    $whatsapp = preg_replace('/\D+/', '', (string) \App\Models\PlatformSetting::get('support_whatsapp'));
    $whatsappUrl = $whatsapp ? 'https://wa.me/' . (str_starts_with($whatsapp, '0') ? '2' . $whatsapp : $whatsapp) : null;
    $supportEmail = \App\Models\PlatformSetting::get('support_email');
    $other = app()->getLocale() === 'ar' ? 'en' : 'ar';
    $home = route('landing');
    $nav = [
        ['#for-you', __('Cafe or restaurant?')],
        ['#features', __('Features')],
        ['#print', __('Printed menu')],
        ['#pricing', __('Pricing')],
        ['#faq', __('FAQ')],
    ];
@endphp

@push('head')
    <meta name="description" content="@yield('description', __('QR menu, table ordering, online ordering with no commission, a digital loyalty card and a print-ready menu for cafes and restaurants in Egypt. :days days free.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]))">
    <meta name="theme-color" content="#1E2D4F">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="EgMenu">
    <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_EG' : 'en_US' }}">
    <meta property="og:title" content="@yield('title', 'EgMenu')">
    <meta property="og:description" content="@yield('description', __('QR menu, table ordering, online ordering with no commission, a digital loyalty card and a print-ready menu for cafes and restaurants in Egypt. :days days free.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]))">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('og-image.png') }}">
@endpush

@section('body')
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">{{ __('Skip to content') }}</a>

<header data-header class="lp-header sticky top-0 z-40 border-b border-transparent bg-white/85 backdrop-blur-md">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 lp-header-row">
        <a href="{{ $home }}" aria-label="EgMenu" class="shrink-0"><x-logo :size="34" class="lp-header-logo" /></a>
        <nav class="hidden items-center gap-1 text-sm font-semibold text-navy-600 lg:flex" aria-label="{{ __('Main') }}">
            @foreach ($nav as [$href, $label])
                <a href="{{ $home }}{{ $href }}" data-nav="{{ ltrim($href, '#') }}" class="rounded-lg px-3 py-2 transition hover:bg-navy-50 hover:text-navy-900">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="flex items-center gap-1.5 text-sm font-semibold sm:gap-2">
            <a href="?lang={{ $other }}" class="grid min-h-10 place-items-center rounded-lg px-2.5 text-navy-600 hover:bg-navy-50" lang="{{ $other }}">{{ $other === 'en' ? 'EN' : 'عربي' }}</a>
            <a href="{{ platform_url('app', '/login') }}" class="btn-ghost hidden min-h-10 xl:inline-flex">{{ __('Restaurant login') }}</a>
            <a href="{{ route('register') }}" class="btn-primary min-h-10 shadow-sm shadow-brand-600/30">{{ __('Start free') }}</a>
            <button type="button" data-menu-toggle class="grid size-10 place-items-center rounded-lg text-navy-800 hover:bg-navy-50 lg:hidden" aria-expanded="false" aria-controls="mobile-nav" aria-label="{{ __('Menu') }}">
                <x-landing.icon name="menu" class="size-6" />
            </button>
        </div>
    </div>
    <nav id="mobile-nav" data-mobile-nav hidden class="border-t border-navy-100 bg-white px-4 pb-4 lg:hidden" aria-label="{{ __('Main') }}">
        <div class="mx-auto grid max-w-7xl gap-1 pt-2">
            @foreach ($nav as [$href, $label])
                <a href="{{ $home }}{{ $href }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold text-navy-800 hover:bg-navy-50">{{ $label }}</a>
            @endforeach
            <a href="{{ platform_url('app', '/login') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold text-navy-500 hover:bg-navy-50">{{ __('Restaurant login') }}</a>
        </div>
    </nav>
</header>

<main id="main">@yield('page')</main>

<footer class="relative overflow-hidden bg-navy-900 text-white/70">
    <div class="pointer-events-none absolute -top-40 start-1/2 size-96 rounded-full bg-brand-600/10 blur-3xl" aria-hidden="true"></div>
    <div class="relative mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-12">
        <div class="lg:col-span-5">
            <x-logo :size="38" :light="true" />
            <p class="mt-4 max-w-sm text-sm leading-relaxed">{{ __('A digital QR menu, table and online ordering, a loyalty card and a printed menu for restaurants and cafes in Egypt.') }}</p>
            <div class="mt-5 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full border border-white/15 px-3 py-1">{{ __('Made in Egypt') }}</span>
                <span class="rounded-full border border-white/15 px-3 py-1">InstaPay · Vodafone Cash</span>
                <span class="rounded-full border border-white/15 px-3 py-1">{{ __('No commission') }}</span>
            </div>
        </div>
        <div class="space-y-2.5 text-sm lg:col-span-2">
            <div class="mb-3 font-bold text-white">{{ __('Product') }}</div>
            <a href="{{ $home }}#features" class="block hover:text-white">{{ __('Features') }}</a>
            <a href="{{ $home }}#print" class="block hover:text-white">{{ __('Printed menu') }}</a>
            <a href="{{ $home }}#pricing" class="block hover:text-white">{{ __('Pricing') }}</a>
            <a href="{{ $home }}#faq" class="block hover:text-white">{{ __('FAQ') }}</a>
        </div>
        <div class="space-y-2.5 text-sm lg:col-span-2">
            <div class="mb-3 font-bold text-white">{{ __('Account') }}</div>
            <a href="{{ route('register') }}" class="block hover:text-white">{{ __('Start free') }}</a>
            <a href="{{ platform_url('app', '/login') }}" class="block hover:text-white">{{ __('Restaurant login') }}</a>
        </div>
        <div class="space-y-2.5 text-sm lg:col-span-3">
            <div class="mb-3 font-bold text-white">{{ __('Contact & legal') }}</div>
            @if ($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="block hover:text-white">{{ __('WhatsApp') }}</a>@endif
            @if ($supportEmail)<a href="mailto:{{ $supportEmail }}" class="block hover:text-white" dir="ltr">{{ $supportEmail }}</a>@endif
            <a href="{{ route('terms') }}" class="block hover:text-white">{{ __('Terms of service') }}</a>
            <a href="{{ route('privacy') }}" class="block hover:text-white">{{ __('Privacy policy') }}</a>
        </div>
    </div>
    <div class="relative border-t border-white/10">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-5 text-xs">
            <span>© {{ date('Y') }} EgMenu · {{ __('by') }} <a href="https://egweb.com.eg" target="_blank" rel="noopener" class="font-semibold text-white/90 hover:text-white">EgWeb</a></span>
            <a href="?lang={{ $other }}" class="hover:text-white" lang="{{ $other }}">{{ $other === 'en' ? 'English' : 'عربي' }}</a>
        </div>
    </div>
</footer>
@endsection
