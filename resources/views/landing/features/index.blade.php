@extends('layouts.landing')
@section('title', __('Features') . ' · EgMenu')
@section('description', __('Every EgMenu module with a real screen and a short animation of how it works: QR menu, ordering, reservations, loyalty, WhatsApp, POS, kiosk, kitchen display and more.'))

@php
    $pricing = ['plans' => $plans, 'addons' => $addons, 'matrix' => $matrix];
    $intro = [
        'menu' => __('Put the menu online in your guests’ languages, on paper and on the TVs, and keep every copy up to date from one place.'),
        'orders' => __('Orders from the table and from your own link with no commission, and table bookings, on one live board.'),
        'customers' => __('Bring guests back with a stamp card, offers, WhatsApp messages and a prepaid balance.'),
        'operations' => __('For busier places and chains: a cashier, a self-order tablet, kitchen screens and stock, in the Enterprise plan set up with you.'),
        'management' => __('Numbers you can read, more branches, your own domain and a place in the directory.'),
    ];
@endphp

@section('page')
<section data-hero class="lp-hero lp-glow">
    <div class="mx-auto max-w-7xl px-4 pt-10 pb-10 text-center sm:px-6 lg:pt-14 lg:pb-14">
        <p class="lp-eyebrow" data-reveal>{{ __('Features') }}</p>
        <h1 class="mx-auto mt-4 max-w-3xl text-[2rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.25]" data-reveal style="--d: 70ms">{{ __('Everything a cafe or a restaurant needs, one module at a time') }}</h1>
        <p class="lp-lead mx-auto mt-4 max-w-2xl" data-reveal style="--d: 140ms">{{ __('Start with the free menu. Each module below has its own page with a real screen and a short animation of how it works.') }}</p>
        <nav class="-mx-4 mt-8 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:justify-center sm:px-0" aria-label="{{ __('Groups') }}" data-reveal style="--d: 210ms">
            @foreach (\App\Support\SiteModules::groups() as $g => [$label, $icon])
                <a href="#{{ $g }}" class="flex min-h-11 shrink-0 items-center gap-2 rounded-full border border-navy-200 bg-white px-4 text-sm font-bold text-navy-800 hover:border-navy-600 hover:text-navy-600"><x-landing.icon :name="$icon" class="size-4 text-brand-600" />{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</section>

@foreach (\App\Support\SiteModules::grouped() as $g => $modules)
    <section id="{{ $g }}" @class(['py-16 lg:py-24', 'lp-band' => $g === 'operations'])>
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center" data-reveal>
                <h2 class="lp-h2 flex flex-wrap items-center justify-center gap-3">{{ \App\Support\SiteModules::groups()[$g][0] }}
                    @if ($g === 'operations')<span class="lp-eyebrow">{{ __('Add-ons') }}</span>@endif
                </h2>
                <p class="mt-3 leading-relaxed text-navy-600">{{ $intro[$g] }}</p>
            </div>
            <div id="group-{{ $g }}" data-more @class(['mt-10 grid gap-4 sm:grid-cols-2 lg:gap-5', 'lg:grid-cols-3 xl:grid-cols-5' => count($modules) === 5, 'lg:grid-cols-3' => count($modules) === 3, 'lg:grid-cols-4' => !in_array(count($modules), [3, 5], true)])>
                @foreach ($modules as $m)
                    <x-landing.module-card :m="$m" :note="\App\Support\SiteModules::where($m, $pricing)" :compact="true" data-reveal style="--d: {{ ($loop->index % 5) * 60 }}ms" :data-more-item="$loop->index >= 3 ? '' : null" />
                @endforeach
            </div>
            @if (count($modules) > 3)<x-landing.more :for="'group-' . $g" />@endif
        </div>
    </section>
@endforeach

<section class="lp-band lp-glow mb-8 py-16 lg:py-20">
    <div class="mx-auto flex max-w-3xl flex-col items-center gap-6 px-4 text-center sm:px-6" data-reveal>
        <div>
            <h2 class="lp-h2">{{ __('Start with the free menu today') }}</h2>
            <p class="lp-lead mt-2">{{ __('No card, no contract. Try every paid feature for :days days.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</p>
        </div>
        <div class="flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-primary min-h-12 rounded-full px-7 text-base">{{ __('Start free') }}</a>
            <a href="{{ route('pricing') }}" class="btn-ghost min-h-12 rounded-full px-6 text-base">{{ __('See prices') }}</a>
        </div>
    </div>
</section>
@endsection
