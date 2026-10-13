@extends('layouts.base')
@section('body_class', 'bg-white text-navy-900')

@php
    $whatsapp = preg_replace('/\D+/', '', (string) \App\Models\PlatformSetting::get('support_whatsapp'));
    $supportEmail = \App\Models\PlatformSetting::get('support_email');
    $other = app()->getLocale() === 'ar' ? 'en' : 'ar';
@endphp

@section('body')
<header class="sticky top-0 z-30 border-b border-navy-100/70 bg-white/90 backdrop-blur">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3">
        <a href="{{ route('landing') }}" aria-label="EgMenu"><x-logo :size="34" /></a>
        <nav class="hidden items-center gap-6 text-sm font-semibold text-navy-600 md:flex">
            <a href="{{ route('landing') }}#features" class="hover:text-navy-900">{{ __('Features') }}</a>
            <a href="{{ route('landing') }}#pricing" class="hover:text-navy-900">{{ __('Pricing') }}</a>
            <a href="{{ route('landing') }}#faq" class="hover:text-navy-900">{{ __('FAQ') }}</a>
        </nav>
        <div class="flex items-center gap-2 text-sm font-semibold">
            <a href="?lang={{ $other }}" class="px-2">{{ $other === 'en' ? 'English' : 'عربي' }}</a>
            <a href="{{ platform_url('app', '/login') }}" class="btn-ghost hidden min-h-10 sm:inline-flex">{{ __('Restaurant login') }}</a>
            <a href="{{ route('register') }}" class="btn-primary min-h-10">{{ __('Start free') }}</a>
        </div>
    </div>
</header>

<main>@yield('page')</main>

<footer class="bg-navy-900 text-white/70">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <x-logo :size="34" :light="true" />
            <p class="mt-3 max-w-sm text-sm">{{ __('A digital QR menu, table ordering and a loyalty card for restaurants and cafes in Egypt.') }}</p>
        </div>
        <div class="space-y-2 text-sm">
            <div class="font-bold text-white">EgMenu</div>
            <a href="{{ route('landing') }}#pricing" class="block hover:text-white">{{ __('Pricing') }}</a>
            <a href="{{ route('register') }}" class="block hover:text-white">{{ __('Start free') }}</a>
            <a href="{{ platform_url('app', '/login') }}" class="block hover:text-white">{{ __('Restaurant login') }}</a>
        </div>
        <div class="space-y-2 text-sm">
            <div class="font-bold text-white">{{ __('Contact & legal') }}</div>
            @if ($whatsapp)<a href="https://wa.me/{{ str_starts_with($whatsapp, '0') ? '2' . $whatsapp : $whatsapp }}" target="_blank" rel="noopener" class="block hover:text-white">{{ __('WhatsApp') }}</a>@endif
            @if ($supportEmail)<a href="mailto:{{ $supportEmail }}" class="block hover:text-white" dir="ltr">{{ $supportEmail }}</a>@endif
            <a href="{{ route('terms') }}" class="block hover:text-white">{{ __('Terms of service') }}</a>
            <a href="{{ route('privacy') }}" class="block hover:text-white">{{ __('Privacy policy') }}</a>
        </div>
    </div>
    <div class="border-t border-white/10 py-5 text-center text-xs">© {{ date('Y') }} EgMenu · {{ __('by') }} EgWeb</div>
</footer>
@endsection
