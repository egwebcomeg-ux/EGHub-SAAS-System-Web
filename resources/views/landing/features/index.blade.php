@extends('layouts.landing')
@section('title', __('Features') . ' · EgMenu')
@section('description', __('Every EgMenu module with a real screen and a short animation of how it works: QR menu, ordering, loyalty, printed menu, POS, kitchen display and more.'))

@php
    $pricing = ['plans' => $plans, 'addons' => $addons, 'matrix' => $matrix];
    $intro = [
        'menu' => __('Put the menu online, on paper and on the TVs, and keep every copy up to date from one place.'),
        'orders' => __('Orders from the table and from your own link, with no commission, on one live board.'),
        'customers' => __('Bring guests back with a stamp card, and fill the quiet hours with offers.'),
        'operations' => __('For busier places. Add only the ones you need, on top of a paid plan.'),
        'management' => __('Numbers you can read, more branches, and your own domain.'),
    ];
@endphp

@section('page')
<section data-hero class="border-b border-navy-100 bg-[var(--lp-paper)]">
    <div class="mx-auto max-w-7xl px-4 pt-12 pb-10 sm:px-6 lg:pt-16">
        <p class="lp-eyebrow">{{ __('Features') }}</p>
        <h1 class="mt-3 max-w-3xl text-[2rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.25]">{{ __('Everything a cafe or a restaurant needs, one module at a time') }}</h1>
        <p class="lp-lead mt-4 max-w-2xl">{{ __('Start with the free menu. Each module below has its own page with a real screen and a short animation of how it works.') }}</p>
        <nav class="mt-8 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]" aria-label="{{ __('Groups') }}">
            @foreach (\App\Support\SiteModules::groups() as $g => [$label, $icon])
                <a href="#{{ $g }}" class="flex min-h-11 shrink-0 items-center gap-2 rounded-full border border-navy-200 bg-white px-4 text-sm font-bold text-navy-800 hover:border-navy-900"><x-landing.icon :name="$icon" class="size-4 text-brand-600" />{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</section>

@foreach (\App\Support\SiteModules::grouped() as $g => $modules)
    <section id="{{ $g }}" @class(['py-14 lg:py-20', 'border-t border-navy-100' => !$loop->first, 'bg-[var(--lp-paper)]' => $g === 'operations'])>
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="grid gap-3 lg:grid-cols-[1fr_2fr] lg:items-end">
                <h2 class="lp-h2 flex items-center gap-3">{{ \App\Support\SiteModules::groups()[$g][0] }}
                    @if ($g === 'operations')<span class="rounded-full bg-brand-600 px-3 py-1 text-sm font-bold text-white">{{ __('Add-ons') }}</span>@endif
                </h2>
                <p class="text-navy-600 lg:text-end">{{ $intro[$g] }}</p>
            </div>
            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($modules as $m)
                    <x-landing.module-card :m="$m" :note="\App\Support\SiteModules::where($m, $pricing)" data-reveal style="--d: {{ $loop->index * 60 }}ms" />
                @endforeach
            </div>
        </div>
    </section>
@endforeach

<section class="px-4 pb-16 sm:px-6">
    <div class="mx-auto flex max-w-7xl flex-col items-start justify-between gap-6 rounded-3xl bg-navy-900 p-8 text-white sm:p-12 lg:flex-row lg:items-center">
        <div>
            <h2 class="text-2xl font-black sm:text-3xl">{{ __('Start with the free menu today') }}</h2>
            <p class="mt-2 text-white/70">{{ __('No card, no contract. Try every paid feature for :days days.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('register') }}" class="btn-primary min-h-12 px-6 text-base">{{ __('Start free') }}</a>
            <a href="{{ route('pricing') }}" class="btn min-h-12 border border-white/25 px-6 text-base text-white hover:bg-white/10">{{ __('See prices') }}</a>
        </div>
    </div>
</section>
@endsection
