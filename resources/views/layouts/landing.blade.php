@extends('layouts.base')
@section('body_class', 'bg-white text-navy-900')

@php
    $viteEntries = ['resources/css/landing.css', 'resources/js/landing.js'];
    // footer contact block: sales and support WhatsApp numbers and the public email, each shown only when set
    $contacts = [];
    foreach (['sales_whatsapp' => __('Sales'), 'support_whatsapp' => __('Support')] as $contactKey => $contactLabel) {
        $contactNumber = trim((string) \App\Models\PlatformSetting::get($contactKey));
        if ($contactNumber !== '') {
            $contacts[] = ['label' => $contactLabel, 'text' => $contactNumber, 'href' => 'https://wa.me/' . whatsapp_number($contactNumber), 'icon' => 'chat'];
        }
    }
    $contactEmail = trim((string) (\App\Models\PlatformSetting::get('contact_email') ?: \App\Models\PlatformSetting::get('support_email')));
    if ($contactEmail !== '') {
        $contacts[] = ['label' => __('Email'), 'text' => $contactEmail, 'href' => 'mailto:' . $contactEmail, 'icon' => null];
    }
    $socialLinks = \App\Support\SocialLinks::all();
    $other = app()->getLocale() === 'ar' ? 'en' : 'ar';
    $home = route('landing');
    $siteGroups = \App\Support\SiteModules::groups();
    $siteModules = \App\Support\SiteModules::grouped();
    $description = __('A free QR menu for cafes and restaurants in Egypt, with table and online ordering without commission, a loyalty card and a print-ready menu when you need them.');
    // every marketing page exists in Arabic (the plain address) and English (?lang=en): each is its own canonical,
    // linked by hreflang. Pages with their own canonical (blog articles) are left as they are.
    $pageUrl = url()->current();
    $defaultCanonical = $pageUrl . (request()->query('lang') === 'en' ? '?lang=en' : '');
    $hreflang = $__env->hasSection('canonical') ? [] : ['ar' => $pageUrl, 'en' => $pageUrl . '?lang=en', 'x-default' => $pageUrl];
@endphp

@push('head')
    <script>document.documentElement.classList.add('js')</script>
    <meta name="description" content="@yield('description', $description)">
    <meta name="theme-color" content="#ffffff">
    <link rel="apple-touch-icon" href="{{ versioned_asset('icons/egmenu-192.png') }}">
    <meta name="apple-mobile-web-app-title" content="EgHub">
    <link rel="canonical" href="@yield('canonical', $defaultCanonical)">
    @foreach ($hreflang as $hreflangCode => $hreflangUrl)
    <link rel="alternate" hreflang="{{ $hreflangCode }}" href="{{ $hreflangUrl }}">
    @endforeach
    <link rel="alternate" type="application/rss+xml" title="EgHub" href="{{ route('blog.rss') }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:site_name" content="EgHub">
    <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_EG' : 'en_US' }}">
    <meta property="og:title" content="@yield('title', 'EgHub')">
    <meta property="og:description" content="@yield('description', $description)">
    <meta property="og:url" content="@yield('canonical', $defaultCanonical)">
    @include('landing.partials.share-image')
    <link rel="preload" as="image" href="{{ versioned_asset('img/site/orders-sm.webp') }}" media="(min-width: 1024px)">
@endpush

@push('scripts')
    @include('partials.platform-pixel')
@endpush

@section('body')
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">{{ __('Skip to content') }}</a>

<header data-header class="lp-header sticky top-0 z-40">
    <div class="lp-header-row mx-auto flex max-w-7xl items-center gap-2 ps-4 pe-2 sm:ps-6">
        <a href="{{ $home }}" aria-label="EgHub" class="shrink-0"><x-logo :size="32" /></a>

        <nav class="ms-6 hidden items-center gap-1 text-[0.95rem] font-bold text-navy-700 lg:flex" aria-label="{{ __('Main') }}">
            <button type="button" data-mega-toggle aria-expanded="false" aria-controls="mega" class="flex min-h-11 items-center gap-1 rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900">
                {{ __('Features') }}
                <svg class="lp-chev size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>
            </button>
            <a href="{{ route('pricing') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900">{{ __('Pricing') }}</a>
            <a href="{{ route('services') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900">{{ __('Services') }}</a>
            <a href="{{ route('blog') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900">{{ __('Blog') }}</a>
            <a href="{{ route('tools.qr') }}" data-nav-qr @if (request()->routeIs('tools.qr')) aria-current="page" @endif class="flex min-h-11 items-center gap-1.5 rounded-lg px-3 whitespace-nowrap hover:bg-navy-50 hover:text-navy-900 aria-[current=page]:text-brand-700">{{ __('QR generator') }}<span class="rounded-full bg-emerald-50 px-1.5 py-0.5 text-[11px] leading-none font-extrabold text-emerald-700">{{ __('Free') }}</span></a>
            <a href="{{ $home }}#faq" class="hidden min-h-11 items-center rounded-lg px-3 hover:bg-navy-50 hover:text-navy-900 xl:flex">{{ __('FAQ') }}</a>
        </nav>

        <div class="ms-auto flex items-center gap-1 text-sm font-bold">
            <a href="?lang={{ $other }}" class="hidden min-h-11 items-center rounded-lg px-2.5 text-navy-600 hover:bg-navy-50 sm:flex" lang="{{ $other }}">{{ $other === 'en' ? 'English' : 'عربي' }}</a>
            <a href="{{ platform_url('app', '/login') }}" class="hidden min-h-11 items-center rounded-lg px-3 text-navy-700 hover:bg-navy-50 lg:flex">{{ __('Log in') }}</a>
            <a href="{{ route('register') }}" class="btn-primary min-h-11 rounded-full px-5">{{ __('Start free') }}</a>
            <button type="button" data-menu-toggle class="grid size-11 place-items-center rounded-full text-navy-800 hover:bg-navy-50 lg:hidden" aria-expanded="false" aria-controls="mobile-nav" aria-label="{{ __('Menu') }}">
                <x-landing.icon name="menu" class="size-6" data-menu-icon="open" />
                <x-landing.icon name="x" class="size-6" data-menu-icon="close" hidden />
            </button>
        </div>
        <span class="lp-progress" aria-hidden="true"><i data-read-progress></i></span>
    </div>

    {{-- desktop mega menu --}}
    <div id="mega" data-mega class="lp-mega absolute inset-x-5 top-full mx-auto mt-2 hidden max-w-7xl overflow-hidden rounded-[28px] border border-navy-100 bg-white shadow-[0_30px_60px_-34px_rgba(19, 29, 61, .5)] lg:block">
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
                <span class="text-navy-500">{{ __('The menu is free. Every module is sold on its own on top of any plan — or take a plan and save.') }}</span>
                <a href="{{ route('features') }}" class="font-extrabold text-brand-700 hover:text-brand-600">{{ __('All features') }} <span aria-hidden="true" class="rtl:inline-block rtl:rotate-180">→</span></a>
            </div>
        </div>
    </div>

    {{-- mobile drawer --}}
    <nav id="mobile-nav" data-mobile-nav hidden class="absolute inset-x-3 top-full mt-2 max-h-[calc(100dvh-var(--lp-hdr)-20px)] overflow-y-auto overscroll-contain rounded-[28px] border border-navy-100 bg-white px-4 pt-2 pb-6 shadow-[0_30px_60px_-30px_rgba(19, 29, 61, .55)] sm:inset-x-5 lg:hidden" aria-label="{{ __('Main') }}">
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
        <a href="{{ route('services') }}" class="flex min-h-13 items-center border-b border-navy-100 px-1 text-base font-extrabold text-navy-900">{{ __('Services') }}</a>
        <a href="{{ route('blog') }}" class="flex min-h-13 items-center border-b border-navy-100 px-1 text-base font-extrabold text-navy-900">{{ __('Blog') }}</a>
        <a href="{{ route('tools.qr') }}" data-nav-qr class="flex min-h-13 items-center gap-2 border-b border-navy-100 px-1 text-base font-extrabold text-navy-900">{{ __('QR generator') }}<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-extrabold text-emerald-700">{{ __('Free') }}</span></a>
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
    'billedTotal' => __('That is :month EGP a month, you save :saved EGP'),
    'periods' => collect(\App\Models\Plan::CYCLES)->mapWithKeys(fn ($m) => [$m => cycle_label($m)]),
], JSON_UNESCAPED_UNICODE) }}">@yield('page')</main>

<footer class="bg-[#131D3D] text-white/70">
    <div class="mx-auto max-w-7xl px-4 pt-14 pb-10 sm:px-6">
        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,3.6fr)]">
            <div>
                <x-logo :size="34" :light="true" />
                <p class="mt-4 max-w-xs text-sm leading-relaxed">{{ __('A QR menu, ordering and a loyalty card for cafes and restaurants in Egypt. Made by EgWeb.') }}</p>
                <a href="{{ route('register') }}" class="btn-primary mt-5 min-h-11">{{ __('Start free') }}</a>
                @if ($contacts)
                    <ul data-footer-contacts class="mt-6 space-y-2.5 text-sm" aria-label="{{ __('Contact us') }}">
                        @foreach ($contacts as $contact)
                            <li class="min-w-0">
                                <a href="{{ $contact['href'] }}" @if ($contact['icon']) target="_blank" rel="noopener" @endif class="inline-flex max-w-full items-center gap-1.5 text-white/80 hover:text-white">
                                    @if ($contact['icon'])<x-landing.icon :name="$contact['icon']" class="size-4 shrink-0 text-emerald-400" />@endif
                                    <span class="shrink-0">{{ $contact['label'] }}:</span>
                                    <span class="truncate font-semibold" dir="ltr">{{ $contact['text'] }}</span>
                                    @if ($contact['icon'])<span class="shrink-0 text-xs font-medium text-white/50">({{ __('WhatsApp') }})</span>@endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($socialLinks)
                    <ul data-social-links class="mt-5 flex flex-wrap gap-2" aria-label="{{ __('Follow EgHub') }}">
                        @foreach ($socialLinks as $social)
                            <li><a href="{{ $social['url'] }}" target="_blank" rel="noopener me" aria-label="{{ $social['label'] }}" title="{{ $social['label'] }}" class="grid size-11 place-items-center rounded-full bg-white/10 text-white/80 transition hover:bg-brand-600 hover:text-white">
                                <svg aria-hidden="true" viewBox="0 0 24 24" class="size-5 fill-current"><path d="{{ $social['path'] }}"/></svg>
                            </a></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            {{-- phones: the module lists live in the menu drawer, so the footer keeps only the EgHub links --}}
            <div class="grid grid-cols-2 gap-x-6 gap-y-8 text-sm sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($siteGroups as $g => [$groupLabel, $groupIcon])
                    <div class="max-sm:hidden">
                        <div class="mb-3 font-extrabold text-white">{{ $groupLabel }}</div>
                        <ul class="space-y-2">
                            @foreach ($siteModules[$g] as $m)
                                <li><a href="{{ route('features.show', $m['slug']) }}" class="hover:text-white">{{ $m['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <div class="max-sm:col-span-2">
                    <div class="mb-3 font-extrabold text-white">EgHub</div>
                    <ul class="grid grid-cols-2 gap-x-6 gap-y-2 sm:block sm:space-y-2">
                        <li class="sm:hidden"><a href="{{ route('features') }}" class="hover:text-white">{{ __('All features') }}</a></li>
                        <li><a href="{{ route('pricing') }}" class="hover:text-white">{{ __('Pricing') }}</a></li>
                        <li><a href="{{ route('services') }}" class="hover:text-white">{{ __('Services') }}</a></li>
                        <li><a href="{{ route('blog') }}" class="hover:text-white">{{ __('Blog') }}</a></li>
                        {{-- free tools --}}
                        <li><a href="{{ route('tools.qr') }}" class="hover:text-white">{{ __('Free QR code generator') }}</a></li>
                        <li><a href="{{ route('directory') }}" class="hover:text-white">{{ __('Restaurant directory') }}</a></li>
                        <li><a href="{{ platform_url('app', '/login') }}" class="hover:text-white">{{ __('Log in') }}</a></li>
                        <li><a href="{{ route('terms') }}" class="hover:text-white">{{ __('Terms of service') }}</a></li>
                        <li><a href="{{ route('privacy') }}" class="hover:text-white">{{ __('Privacy policy') }}</a></li>
                        <li><a href="{{ route('refund') }}" class="hover:text-white">{{ __('Refund policy') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <div class="border-t border-white/10">
        {{-- the end side stays clear on desktop: the floating "Ask us" button sits in that corner --}}
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-3 px-4 py-5 pb-28 text-xs sm:px-6 lg:pe-56 lg:pb-6" data-footer-bottom>
            <span>© {{ date('Y') }} EgHub</span>
            <x-egweb-badge tone="dark" />
            @if ($legalLines = \App\Support\LegalDetails::lines())
                <span class="text-white/60" data-footer-legal>
                    @foreach ($legalLines as $line){{ $loop->first ? '' : ' · ' }}{{ $line['label'] }}: {{ $line['value'] }}@endforeach
                </span>
            @endif
            <a href="?lang={{ $other }}" lang="{{ $other }}" data-footer-lang
               class="inline-flex h-9 items-center gap-1.5 rounded-full border border-white/15 px-3.5 text-white/70 transition hover:border-white/30 hover:text-white">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>
                {{ $other === 'en' ? 'English' : 'عربي' }}
            </a>
        </div>
    </div>
</footer>

@php
    // "Ask us": the website assistant when a key is set, else the sales WhatsApp directly
    $asstOn = \App\Services\Assistant::enabled();
    $asstWaNumber = \App\Services\Assistant::salesWhatsApp();
    $askHref = $asstOn ? '#asst' : ($asstWaNumber !== '' ? 'https://wa.me/'.$asstWaNumber : null);
@endphp

{{-- mobile: one action always in reach --}}
<div data-bottom-bar class="lp-bottom fixed inset-x-3 z-30 rounded-2xl border border-navy-100 bg-white/95 p-2 shadow-[0_18px_40px_-20px_rgba(19, 29, 61, .45)] backdrop-blur sm:inset-x-5 lg:hidden">
    <div class="flex gap-2">
        <a href="{{ route('register') }}" class="btn-primary min-h-12 flex-1 text-base">{{ __('Start free') }}</a>
        <a href="{{ route('pricing') }}" class="btn-ghost min-h-12 px-5 text-base">{{ __('Pricing') }}</a>
        @if ($askHref)
            <a href="{{ $askHref }}" @if ($asstOn) data-assistant-open @else target="_blank" rel="noopener" @endif class="btn-ghost min-h-12 px-4 text-base" aria-label="{{ __('Ask us') }}">
                <x-landing.icon name="chat" class="size-5" />
            </a>
        @endif
    </div>
</div>

{{-- desktop: "Ask us" floating at the bottom corner --}}
@if ($askHref)
    <a href="{{ $askHref }}" @if ($asstOn) data-assistant-open @else target="_blank" rel="noopener" @endif
       class="lp-ask fixed bottom-6 end-6 z-30 hidden min-h-12 items-center gap-2 rounded-full bg-navy-900 px-5 font-extrabold text-white shadow-lg transition hover:bg-navy-800 lg:inline-flex">
        <x-landing.icon name="chat" class="size-5 text-brand-500" />{{ $asstOn ? __('Ask us') : __('Chat on WhatsApp') }}
    </a>
@endif
@if ($asstOn)
    @include('landing.partials.assistant')
@endif
@endsection
