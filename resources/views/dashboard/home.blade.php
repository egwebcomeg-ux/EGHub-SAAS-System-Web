@extends('layouts.dashboard', ['viteEntries' => ['resources/js/dash-home.js']])
@section('title', __('Apps') . ' · ' . tenant()->name)

@section('content')
@php
    $pickModules = $canPickModules && ! $home['picked'];
    $suggestionsHas = fn (array $sug) => \App\Services\Entitlements::current()->allows($sug['feature']);
    $showNextStep = $settingUp || $pickModules || $suggestions;
    // one hue per app group (tokens in resources/css/app.css)
    $hues = \App\Support\DashboardApps::HUES;
    $tileGrid = 'grid grid-cols-3 gap-2 sm:grid-cols-4 sm:gap-3 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8';
@endphp
<x-dash.page-header :title="__('Welcome, :name', ['name' => \Illuminate\Support\Str::of(auth()->user()->name)->before(' ')])" :help="__('Every part of your dashboard is an app here. Tap one to open it.')">
    <x-slot:actions>
        <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-primary">{{ __('Open menu') }}</a>
        <a href="{{ route('dash.help') }}" class="btn-ghost" data-help-link>📖 {{ __('Dashboard tutorial') }}</a>
    </x-slot:actions>
    {{-- the menu's addresses, folded into one line: the owner copies them from here to share --}}
    <details class="group/links mt-2" data-home-links>
        <summary class="flex min-h-9 cursor-pointer list-none items-center gap-1 text-sm font-semibold text-brand-700 [&::-webkit-details-marker]:hidden">
            🔗 {{ __('Your menu link') }}
            <svg class="size-4 transition group-open/links:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="mt-1 max-w-3xl">@include('dashboard.partials.menu-links')</div>
    </details>
</x-dash.page-header>

@if ($showNextStep && $settingUp)
    {{-- a new account: the setup checklist first, the apps under it --}}
    <div class="mb-6 mt-2" data-home-next-first>
        @include('dashboard.partials.home-next-step', ['nextStepFirst' => true])
    </div>
@endif

{{--
    The apps: one tile per area the user may open (App\Support\DashboardApps::home), the picked modules' apps under
    their group, the rest folded under "more apps". The search box filters them on the page (resources/js/dash-home.js).
--}}
<section class="mt-2" data-home-apps @if ($home['picked']) data-home-modules @endif>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-baseline gap-3">
            <h2 class="section-title">{{ __('Apps') }}</h2>
            @if ($canPickModules && $home['picked'])
                <a href="{{ route('dash.modules') }}" class="text-sm font-semibold text-brand-700 underline">{{ __('Change your modules') }}</a>
            @endif
        </div>
        <label class="relative block w-full sm:w-72">
            <span class="sr-only">{{ __('Search apps') }}</span>
            <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-navy-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 21l-4.3-4.3M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z"/></svg>
            <input type="search" class="field ps-10 pe-10" placeholder="{{ __('Search apps') }}" autocomplete="off" data-apps-search>
            <kbd class="pointer-events-none absolute end-3 top-1/2 hidden -translate-y-1/2 rounded-md border border-navy-200 bg-navy-50 px-1.5 font-sans text-[11px] font-bold text-navy-500 lg:block" aria-hidden="true">/</kbd>
        </label>
    </div>

    @if ($pickModules)
        {{-- nothing picked yet: the invitation sits with the apps, in the shape of a tile --}}
        <a href="{{ route('dash.modules') }}" data-modules-invite class="mb-4 flex items-center gap-4 rounded-2xl border border-dashed border-brand-300 bg-brand-50/60 p-4 transition hover:border-brand-500 hover:bg-brand-50">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-white text-2xl shadow-sm ring-1 ring-brand-200">🧩</span>
            <span class="min-w-0 flex-1">
                <span class="block font-bold text-navy-900">{{ __('Pick your modules') }}</span>
                <span class="block text-xs text-navy-600">{{ __('Tell us what you use, and we put it first in your dashboard.') }}</span>
            </span>
            <span class="btn-primary min-h-9 shrink-0 px-4 text-xs">{{ __('Pick') }}</span>
        </a>
    @endif

    @foreach ($home['groups'] as $group)
        <section class="mb-5" data-apps-group="{{ $group['key'] }}">
            <h3 class="mb-2 flex items-center gap-2 text-xs font-bold tracking-wide text-navy-500">
                <span class="size-2 rounded-full {{ $hues[$group['key']] ?? 'bg-app-management' }}" aria-hidden="true"></span>{{ $group['title'] }}
            </h3>
            <div class="{{ $tileGrid }}">
                @foreach ($group['apps'] as $app)
                    <x-dash.app-tile :app="$app" :badge="$badges[$app['route'] ?? ''] ?? 0" :hue="$hues[$group['key']] ?? 'bg-app-management'" />
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($home['more'] !== [])
        <details class="group/apps-more rounded-2xl border border-dashed border-navy-200 bg-white/60 p-3" data-apps-more>
            <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 text-sm font-bold text-navy-600 hover:text-navy-900 [&::-webkit-details-marker]:hidden">
                <svg class="size-5 text-navy-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \App\Support\DashboardApps::APPS_ICON }}"/></svg>
                {{ __('More apps') }}
                <span class="rounded-full bg-navy-100 px-2 text-xs text-navy-700">{{ count($home['more']) }}</span>
                <svg class="ms-auto size-4 transition group-open/apps-more:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="{{ $tileGrid }} mt-3">
                @foreach ($home['more'] as $app)
                    <x-dash.app-tile :app="$app" :badge="$badges[$app['route'] ?? ''] ?? 0" :hue="$hues[$app['group']] ?? 'bg-app-management'" />
                @endforeach
            </div>
        </details>
    @endif

    <p class="empty-state" data-apps-empty hidden>{{ __('No app goes by that name.') }}</p>
</section>

{{-- under the apps (Odoo-style: the apps come first): the next step once setup is done, today's numbers and the plan --}}
<div class="mt-6 grid gap-4 xl:grid-cols-3 xl:items-start" data-home-top>
    @if ($showNextStep && ! $settingUp)
        @include('dashboard.partials.home-next-step', ['nextStepFirst' => false])
    @endif

    {{-- today's numbers and the plan with its limits: one card, side by side when it has the row to itself --}}
    @php($dayAlone = ! $showNextStep || $settingUp)
    <section @class(['card', 'xl:col-span-3' => $dayAlone]) data-home-day>
        <div @class(['grid gap-5', 'md:grid-cols-2' => $dayAlone])>
            <div data-home-stats>
                <h2 class="text-base font-bold">{{ __('Today') }}</h2>
                <div class="mt-2 grid grid-cols-3 gap-2">
                    @foreach ([
                        ['orders_today', __('Orders today'), $stats['orders_today']],
                        ['customers', __('Customers'), $stats['customers']],
                        ['items', __('Menu items'), $stats['items']],
                    ] as [$key, $label, $value])
                        <div class="rounded-xl bg-navy-50 px-3 py-2" data-home-stat="{{ $key }}">
                            <div class="text-xl font-black tabular-nums text-navy-900">{{ $value }}</div>
                            <div class="text-xs font-semibold text-navy-500">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div data-home-plan>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-bold">{{ __('Your plan') }}</h2>
                        @if ($subscription)
                            <p class="truncate text-xs text-navy-500">
                                {{ $subscription->plan->displayName() }} · {{ $subscription->statusLabel() }}
                                @if ($subscription->endsAt()) · {{ __('until') }} {{ local_time($subscription->endsAt(), 'Y/m/d') }} @endif
                            </p>
                        @endif
                    </div>
                    @can('billing.manage')<a href="{{ route('dash.billing') }}" class="btn-ghost min-h-9 shrink-0 px-3 text-xs">{{ __('Subscription') }}</a>@endcan
                </div>
                <div class="mt-2 space-y-2">
                    @foreach ($limits as $l)
                        <x-usage-bar :label="$l['label']" :used="$l['used']" :limit="$l['limit']" />
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</div>
@if ($menuSetupUrl)
    <a href="{{ $menuSetupUrl }}" target="_blank" rel="noopener" data-menu-setup-tip class="mt-4 flex items-center justify-between gap-3 rounded-2xl border border-dashed border-brand-200 bg-brand-50/70 px-4 py-3 text-sm font-semibold text-navy-800">
        <span>✍️ {{ __('No time to enter the menu? Our team does it for you.') }}</span><span class="shrink-0 text-brand-700 underline">{{ __('Menu setup service') }}</span>
    </a>
@endif

@endsection
