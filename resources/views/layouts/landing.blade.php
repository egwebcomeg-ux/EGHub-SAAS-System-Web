@extends('layouts.base')
@section('body_class', 'bg-white text-navy-900')

@php
    $viteEntries = ['resources/css/landing.css', 'resources/js/landing.js'];
    $whatsapp = preg_replace('/\D+/', '', (string) \App\Models\PlatformSetting::get('support_whatsapp'));
    $whatsappUrl = $whatsapp ? 'https://wa.me/' . (str_starts_with($whatsapp, '0') ? '2' . $whatsapp : $whatsapp) : null;
    $supportEmail = \App\Models\PlatformSetting::get('support_email');
    $other = app()->getLocale() === 'ar' ? 'en' : 'ar';
    $home = route('landing');
    $siteGroups = \App\Support\SiteModules::groups();
    $siteModules = \App\Support\SiteModules::grouped();
    $description = __('A free QR menu for cafes and restaurants in Egypt, with table and online ordering without commission, a loyalty card and a print-ready menu when you need them.');
@endphp

@push('head')
    <script>document.documentElement.classList.add('js')</script>
    <meta name="description" content="@yield('description', $description)">
    <meta name="theme-color" content="#ffffff">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="EgMenu">
    <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_EG' : 'en_US' }}">
    <meta property="og:title" content="@yield('title', 'EgMenu')">
    <meta property="og:description" content="@yield('description', $description)">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('og-image.png') }}">
    <link rel="preload" as="image" href="{{ asset('img/site/orders-sm.webp') }}" media="(min-width: 1024px)">
@endpush

@section('body')
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">{{ __('Skip to content') }}</a>

<header data-header class="lp-header sticky top-0 z-40 border-b border-transparent bg-white/95 backdrop-blur">
    <div class="lp-header-row mx-auto flex max-w-7xl items-center gap-3 px-4 sm:px-6">
        <a href="{{ $home }}" aria-label="EgMenu" class="shrink-0"><x-logo :size="32" /></a>

        <nav class="ms-6 hidden items-center gap-1 text-[0.95rem] font-bold text-navy-700 lg:flex" aria-label="{{ __('Main') }}">
            <button type="button" data-mega-toggle aria-expanded="false" aria-controls="mega" class="flex min-h-11 items-center gap-1 rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900">
                {{ __('Features') }}
                <svg class="lp-chev size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>
            </button>
            <a href="{{ route('pricing') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900">{{ __('Pricing') }}</a>
            <a href="{{ $home }}#faq" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900">{{ __('FAQ') }}</a>
        </nav>

        <div class="ms-auto flex items-center gap-1 text-sm font-bold">
            <a href="?lang={{ $other }}" class="hidden min-h-11 items-center rounded-lg px-2.5 text-navy-600 hover:bg-navy-50 sm:flex" lang="{{ $other }}">{{ $other === 'en' ? 'English' : 'عربي' }}</a>
            <a href="{{ platform_url('app', '/login') }}" class="hidden min-h-11 items-center rounded-lg px-3 text-navy-700 hover:bg-navy-50 lg:flex">{{ __('Log in') }}</a>
            <a href="{{ route('register') }}" class="btn-primary min-h-11 px-4">{{ __('Start free') }}</a>
            <button type="button" data-menu-toggle class="grid size-11 place-items-center rounded-lg text-navy-800 hover:bg-navy-50 lg:hidden" aria-expanded="false" aria-controls="mobile-nav" aria-label="{{ __('Menu') }}">
                <x-landing.icon name="menu" class="size-6" data-menu-icon="open" />
                <x-landing.icon name="x" class="size-6" data-menu-icon="close" hidden />
            </button>
        </div>
    </div>

    {{-- desktop mega menu --}}
    <div id="mega" data-mega class="lp-mega absolute inset-x-0 top-full hidden border-y border-navy-100 bg-white shadow-[0_24px_40px_-30px_rgba(20,31,56,.45)] lg:block">
        <div class="mx-auto grid max-w-7xl grid-cols-5 gap-6 px-6 py-7">
            @foreach ($siteGroups as $g => [$groupLabel, $groupIcon])
                <div>
                    <div class="mb-2 flex items-center gap-2 text-xs font-extrabold text-navy-400">
                        {{ $groupLabel }}
                        @if ($g === 'operations')<span class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] text-brand-700">{{ __('Add-ons') }}</span>@endif
                    </div>
                    <ul class="space-y-0.5">
                        @foreach ($siteModules[$g] as $m)
                            <li>
                                <a href="{{ route('features.show', $m['slug']) }}" class="group flex gap-2.5 rounded-xl p-2 hover:bg-navy-50">
                                    <x-landing.icon :name="$m['icon']" class="mt-0.5 size-5 shrink-0 text-navy-400 group-hover:text-brand-600" />
                                    <span><span class="block text-sm font-extrabold text-navy-900">{{ $m['name'] }}</span><span class="block text-xs leading-snug font-medium text-navy-500">{{ $m['summary'] }}</span></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <div class="border-t border-navy-100 bg-navy-50/60">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-3 text-sm">
                <span class="text-navy-500">{{ __('The menu is free. Ordering, loyalty and the rest come with the paid plans; operations are add-ons.') }}</span>
                <a href="{{ route('features') }}" class="font-extrabold text-brand-700 hover:text-brand-600">{{ __('All features') }} <span aria-hidden="true" class="rtl:inline-block rtl:rotate-180">→</span></a>
            </div>
        </div>
    </div>

    {{-- mobile drawer --}}
    <nav id="mobile-nav" data-mobile-nav hidden class="h-[calc(100dvh-58px)] overflow-y-auto border-t border-navy-100 bg-white px-4 pt-2 pb-28 lg:hidden" aria-label="{{ __('Main') }}">
        <p class="px-1 pt-2 pb-1 text-xs font-extrabold text-navy-400">{{ __('Features') }}</p>
        @foreach ($siteGroups as $g => [$groupLabel, $groupIcon])
            <details class="lp-acc border-b border-navy-100" @if ($loop->first) open @endif>
                <summary class="flex min-h-13 cursor-pointer list-none items-center justify-between gap-3 px-1 text-base font-extrabold text-navy-900">
                    <span class="flex items-center gap-2.5"><x-landing.icon :name="$groupIcon" class="size-5 text-brand-600" />{{ $groupLabel }}
                        @if ($g === 'operations')<span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs text-brand-700">{{ __('Add-ons') }}</span>@endif
                    </span>
                    <svg class="lp-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>
                </summary>
                <ul class="pb-2">
                    @foreach ($siteModules[$g] as $m)
                        <li><a href="{{ route('features.show', $m['slug']) }}" class="flex min-h-12 items-center gap-3 rounded-xl px-2 text-[0.95rem] font-bold text-navy-800 active:bg-navy-50"><x-landing.icon :name="$m['icon']" class="size-5 text-navy-400" />{{ $m['name'] }}</a></li>
                    @endforeach
                </ul>
            </details>
        @endforeach
        <a href="{{ route('features') }}" class="flex min-h-13 items-center border-b border-navy-100 px-1 text-base font-extrabold text-navy-900">{{ __('All features') }}</a>
        <a href="{{ route('pricing') }}" class="flex min-h-13 items-center border-b border-navy-100 px-1 text-base font-extrabold text-navy-900">{{ __('Pricing') }}</a>
        <a href="{{ $home }}#faq" class="flex min-h-13 items-center border-b border-navy-100 px-1 text-base font-extrabold text-navy-900">{{ __('FAQ') }}</a>
        <a href="{{ platform_url('app', '/login') }}" class="flex min-h-13 items-center border-b border-navy-100 px-1 text-base font-bold text-navy-600">{{ __('Log in') }}</a>
        <a href="?lang={{ $other }}" class="flex min-h-13 items-center px-1 text-base font-bold text-navy-600" lang="{{ $other }}">{{ $other === 'en' ? 'English' : 'عربي' }}</a>
        <a href="{{ route('register') }}" class="btn-primary mt-4 min-h-13 w-full text-base">{{ __('Start free') }}</a>
        <p class="mt-2 text-center text-sm text-navy-500">{{ __('No card, no contract.') }}</p>
    </nav>
</header>

<main id="main" data-i18n="{{ json_encode([
    'billedMonthly' => __('Billed monthly'),
    'billedEvery' => __(':total EGP for :period, you save :saved EGP'),
    'periods' => collect(\App\Models\Plan::CYCLES)->mapWithKeys(fn ($m) => [$m => cycle_label($m)]),
], JSON_UNESCAPED_UNICODE) }}">@yield('page')</main>

<footer class="bg-[#0f1729] text-white/70">
    <div class="mx-auto max-w-7xl px-4 pt-14 pb-10 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-[1.3fr_3fr]">
            <div>
                <x-logo :size="34" :light="true" />
                <p class="mt-4 max-w-xs text-sm leading-relaxed">{{ __('A QR menu, ordering and a loyalty card for cafes and restaurants in Egypt. Made by EgWeb.') }}</p>
                <a href="{{ route('register') }}" class="btn-primary mt-5 min-h-11">{{ __('Start free') }}</a>
            </div>
            <div class="grid grid-cols-2 gap-8 text-sm sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($siteGroups as $g => [$groupLabel, $groupIcon])
                    <div>
                        <div class="mb-3 font-extrabold text-white">{{ $groupLabel }}</div>
                        <ul class="space-y-2">
                            @foreach ($siteModules[$g] as $m)
                                <li><a href="{{ route('features.show', $m['slug']) }}" class="hover:text-white">{{ $m['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <div>
                    <div class="mb-3 font-extrabold text-white">EgMenu</div>
                    <ul class="space-y-2">
                        <li><a href="{{ route('pricing') }}" class="hover:text-white">{{ __('Pricing') }}</a></li>
                        <li><a href="{{ platform_url('app', '/login') }}" class="hover:text-white">{{ __('Log in') }}</a></li>
                        @if ($whatsappUrl)<li><a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="hover:text-white">{{ __('WhatsApp') }}</a></li>@endif
                        @if ($supportEmail)<li><a href="mailto:{{ $supportEmail }}" class="break-all hover:text-white" dir="ltr">{{ $supportEmail }}</a></li>@endif
                        <li><a href="{{ route('terms') }}" class="hover:text-white">{{ __('Terms of service') }}</a></li>
                        <li><a href="{{ route('privacy') }}" class="hover:text-white">{{ __('Privacy policy') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-5 pb-24 text-xs sm:px-6 lg:pb-5">
            <span>© {{ date('Y') }} EgMenu · {{ __('by') }} <a href="https://egweb.com.eg" target="_blank" rel="noopener" class="font-bold text-white/90 hover:text-white">EgWeb</a></span>
            <a href="?lang={{ $other }}" class="hover:text-white" lang="{{ $other }}">{{ $other === 'en' ? 'English' : 'عربي' }}</a>
        </div>
    </div>
</footer>

{{-- mobile: one action always in reach --}}
<div data-bottom-bar class="lp-bottom fixed inset-x-0 bottom-0 z-30 border-t border-navy-100 bg-white/95 px-4 pt-3 pb-[max(.75rem,env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
    <div class="flex gap-2">
        <a href="{{ route('register') }}" class="btn-primary min-h-12 flex-1 text-base">{{ __('Start free') }}</a>
        <a href="{{ route('pricing') }}" class="btn-ghost min-h-12 px-5 text-base">{{ __('Pricing') }}</a>
    </div>
</div>
@endsection
