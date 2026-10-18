@extends('layouts.base')
@section('title', __('Links of :name', ['name' => $profile->name()]) . ' | EgMenu')
@section('body_class', 'm-text')

@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $name = $profile->name();
    $tagline = trim(tr($settings['tagline'], $settings['tagline_en']));
    $socials = array_filter(['instagram' => $settings['instagram'], 'facebook' => $settings['facebook'], 'tiktok' => $settings['tiktok']]);
    $socialLabels = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'];
    $withHours = $branches->filter(fn ($b) => trim((string) $b->working_hours) !== '');
@endphp

@push('head')
<meta name="description" content="{{ $profile->description() }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="EgMenu">
<meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_EG' : 'en_US' }}">
<meta property="og:title" content="{{ __('Links of :name', ['name' => $name]) }}">
<meta property="og:description" content="{{ $profile->description() }}">
@if (request()->attributes->get('canonical'))
    <meta property="og:url" content="{{ request()->attributes->get('canonical') }}">
@endif
<meta property="og:image" content="{{ $profile->shareImage() }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="{{ $color('color_secondary', '#0A2540') }}">
@include('menu.partials.pixels')
@if ($settings['font'] !== 'Cairo')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ urlencode($settings['font']) }}:wght@400;600;700;800&display=swap">
@endif
@include('menu.partials.theme', ['theme' => $theme])
<style>
    body { font-family: '{{ $settings['font'] }}', 'Cairo', system-ui, sans-serif; }
    .l-btn { display: flex; align-items: center; gap: .75rem; min-height: 3.5rem; padding: 0 1.25rem; border-radius: 1.25rem; font-weight: 800; font-size: 1.05rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
    .l-btn.m-card { border: 1px solid var(--menu-border); background: var(--menu-surface); color: var(--menu-text); }
    .l-btn span:first-child { font-size: 1.4rem; width: 2rem; text-align: center; }
</style>
@endpush

@section('body')
<header class="m-secondary">
    @if ($settings['cover'])
        <img src="/storage/{{ $settings['cover'] }}" alt="" class="h-36 w-full object-cover opacity-90">
    @endif
    <div class="mx-auto flex max-w-md flex-col items-center px-4 pb-6 pt-4 text-center">
        @if ($settings['logo'])
            <img src="/storage/{{ $settings['logo'] }}" alt="{{ __('Logo of :name', ['name' => $name]) }}" class="size-24 rounded-full bg-white object-contain p-1 shadow-lg {{ $settings['cover'] ? '-mt-16' : '' }}">
        @endif
        <h1 class="mt-3 text-2xl font-black">{{ $name }}</h1>
        @if ($tagline)<p class="mt-1 text-sm text-white/80">{{ $tagline }}</p>@endif
        @include('menu.partials.lang-switch', ['class' => 'mt-3'])
    </div>
</header>

<main class="mx-auto max-w-md space-y-3 px-4 py-6" data-links>
    <a href="{{ route('menu.show') }}" class="l-btn m-primary" data-link="menu"><span aria-hidden="true">📖</span><span>{{ __('The menu') }}</span></a>
    @if ($online)
        <a href="{{ route('menu.show') }}#order" class="l-btn m-card" data-link="order"><span aria-hidden="true">🛵</span><span>{{ __('Order online') }}</span></a>
    @endif
    @if ($reservations)
        <a href="{{ route('menu.reserve') }}" class="l-btn m-card" data-link="reserve"><span aria-hidden="true">🪑</span><span>{{ __('Book a table') }}</span></a>
    @endif
    @if ($whatsapp)
        <a href="https://wa.me/{{ whatsapp_number($whatsapp) }}" target="_blank" rel="noopener" class="l-btn m-card" data-link="whatsapp"><span aria-hidden="true">💬</span><span>{{ __('WhatsApp') }}</span></a>
    @endif
    @foreach ($socials as $key => $url)
        <a href="{{ $url }}" target="_blank" rel="noopener" class="l-btn m-card" data-link="{{ $key }}"><span aria-hidden="true">{{ ['instagram' => '📸', 'facebook' => '👍', 'tiktok' => '🎵'][$key] }}</span><span>{{ $socialLabels[$key] }}</span></a>
    @endforeach
    @foreach ($branches as $b)
        @if ($maps[$b->id])
            <a href="{{ $maps[$b->id] }}" target="_blank" rel="noopener" class="l-btn m-card" data-link="map"><span aria-hidden="true">📍</span>
                <span class="min-w-0 flex-1 text-start">{{ $branches->count() > 1 ? __('Location: :branch', ['branch' => tr($b->name, $b->name_en)]) : __('Location on the map') }}
                    @if ($b->address)<span class="block truncate text-sm font-normal opacity-70">{{ $b->address }}</span>@endif</span></a>
        @endif
    @endforeach

    @if ($withHours->isNotEmpty())
        <section class="m-card rounded-3xl border m-border m-surface p-4 shadow-sm" data-hours>
            <h2 class="mb-2 flex items-center gap-2 font-extrabold"><span aria-hidden="true">🕒</span> {{ __('Opening hours') }}</h2>
            <dl class="space-y-1 text-sm">
                @foreach ($withHours as $b)
                    <div class="flex justify-between gap-3">
                        @if ($branches->count() > 1)<dt class="m-muted">{{ tr($b->name, $b->name_en) }}</dt>@endif
                        <dd class="font-semibold">{{ $b->working_hours }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    <footer class="space-y-2 pt-4 text-center text-sm m-muted">
        <a href="{{ route('menu.about') }}" class="m-primary-text font-semibold underline underline-offset-2">{{ __('About the place') }}</a>
        @unless (\App\Services\Entitlements::current()->allows('hide_badge'))
            <div><a href="{{ platform_url(null) }}" class="inline-flex items-center gap-1 text-xs">{{ __('Powered by') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" /></a></div>
        @endunless
    </footer>
</main>
@endsection
