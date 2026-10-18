@extends('layouts.dashboard')
@section('title', __('Home') . ' · ' . tenant()->name)

@section('content')
<x-dash.page-header :title="__('Welcome, :name', ['name' => \Illuminate\Support\Str::of(auth()->user()->name)->before(' ')])" :help="__('Your day at a glance: orders, the menu and what is left to set up.')">
    <x-slot:actions>
        <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-primary">{{ __('Open menu') }}</a>
        @can('orders.view')<a href="{{ route('dash.orders') }}" class="btn-ghost">{{ __('Orders') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>
@if ($lowStock)
    <a href="{{ route('dash.inventory') }}" class="mb-4 flex items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
        <span>⚠ {{ __(':n ingredients are running low.', ['n' => $lowStock]) }}</span><span class="shrink-0 underline">{{ __('Inventory') }}</span>
    </a>
@endif
<div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    @foreach ([
        [__('Categories'), $stats['categories']],
        [__('Menu items'), $stats['items']],
        [__('Orders today'), $stats['orders_today']],
        [__('Customers'), $stats['customers']],
    ] as [$label, $value])
        <div class="stat-card">
            <div class="stat-label">{{ $label }}</div>
            <div class="stat-value">{{ $value }}</div>
        </div>
    @endforeach
</div>

@if ($moduleShortcuts !== null)
    {{-- The modules the owner picked, first thing on the home page (App\Services\ModuleChoice). --}}
    <section class="card mt-4" data-home-modules>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-bold">{{ __('Your modules') }}</h2>
            @if ($canPickModules)<a href="{{ route('dash.modules') }}" class="text-sm font-semibold text-brand-700 underline">{{ __('Change your modules') }}</a>@endif
        </div>
        @if ($moduleShortcuts === [])
            <p class="muted">{{ __('The modules you picked open from the menu on the side.') }}</p>
        @else
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                @foreach ($moduleShortcuts as $shortcut)
                    <a href="{{ $shortcut['url'] }}" data-home-module="{{ $shortcut['module']['slug'] }}" @class(['flex min-h-11 items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold transition',
                        'border-navy-100 text-navy-800 hover:border-brand-500 hover:bg-brand-50/60' => ! $shortcut['locked'],
                        'border-dashed border-navy-200 text-navy-500 hover:border-brand-500' => $shortcut['locked']])>
                        <x-landing.icon :name="$shortcut['module']['icon']" :class="$shortcut['locked'] ? 'size-5 shrink-0 text-navy-300' : 'size-5 shrink-0 text-brand-600'" />
                        <span class="min-w-0 leading-snug">
                            {{ $shortcut['module']['name'] }}
                            @if ($shortcut['locked'])<span class="block text-xs font-semibold text-brand-700">{{ __('Upgrade') }}</span>@endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@elseif ($canPickModules)
    <a href="{{ route('dash.modules') }}" data-modules-invite class="mt-4 flex items-center justify-between gap-3 rounded-2xl border border-dashed border-navy-200 bg-navy-50/70 px-4 py-3 text-sm font-semibold text-navy-800">
        <span>🧩 {{ __('Tell us what you use, and we put it first in your dashboard.') }}</span><span class="shrink-0 text-brand-700 underline">{{ __('Pick your modules') }}</span>
    </a>
@endif

@if ($onboarding && ! $onboarding['hidden'])
    {{-- Onboarding checklist (owners and managers): computed from real data, hidden at 100% or when put away. --}}
    <section class="card mt-4" data-onboarding data-percent="{{ $onboarding['percent'] }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold">{{ __('Get your menu ready') }}</h2>
                <p class="text-sm text-navy-500">
                    {{ __(':done of :total steps done', ['done' => $onboarding['done'], 'total' => $onboarding['total']]) }}
                    · <a href="{{ route('dash.help') }}" class="font-semibold text-brand-700 underline">{{ __('Dashboard tutorial') }}</a>
                </p>
            </div>
            <form method="post" action="{{ route('dash.onboarding.dismiss') }}">
                @csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Hide this checklist') }}</button>
            </form>
        </div>
        <div class="mt-3 flex items-center gap-3">
            <div class="h-2 flex-1 overflow-hidden rounded-full bg-navy-100" role="progressbar" aria-valuenow="{{ $onboarding['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $onboarding['percent'] }}%"></div>
            </div>
            <span class="text-sm font-extrabold text-navy-700" dir="ltr">{{ $onboarding['percent'] }}%</span>
        </div>
        <ul class="mt-3 divide-y divide-navy-50">
            @foreach ($onboarding['steps'] as $step)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5 sm:flex-nowrap" data-onboarding-step="{{ $step['key'] }}" data-state="{{ $step['done'] ? 'done' : ($step['skipped'] ? 'skipped' : 'todo') }}">
                    <span @class(['grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold', 'bg-emerald-500 text-white' => $step['done'], 'bg-navy-100 text-navy-500' => ! $step['done']])>{{ $step['done'] ? '✓' : ($step['skipped'] ? '–' : $loop->iteration) }}</span>
                    <div class="min-w-0 flex-1 basis-48">
                        <div @class(['text-sm font-semibold', 'text-navy-400 line-through' => $step['done'] || $step['skipped']])>{{ $step['title'] }}</div>
                        @if (! $step['done'] && ! $step['skipped'])<div class="text-xs text-navy-500">{{ $step['text'] }}</div>@endif
                    </div>
                    @if (! $step['done'] && ! $step['skipped'])
                        <div class="ms-9 flex shrink-0 items-center gap-2 sm:ms-0">
                            @if ($step['url'])<a href="{{ $step['url'] }}" class="btn-primary min-h-9 px-4 text-xs">{{ __('Go') }}</a>@endif
                            <form method="post" action="{{ route('dash.onboarding.skip') }}">
                                @csrf<input type="hidden" name="step" value="{{ $step['key'] }}"><button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Skip') }}</button>
                            </form>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
@if ($menuSetupUrl)
    <a href="{{ $menuSetupUrl }}" target="_blank" rel="noopener" data-menu-setup-tip class="mt-4 flex items-center justify-between gap-3 rounded-2xl border border-dashed border-brand-200 bg-brand-50/70 px-4 py-3 text-sm font-semibold text-navy-800">
        <span>✍️ {{ __('No time to enter the menu? Our team does it for you.') }}</span><span class="shrink-0 text-brand-700 underline">{{ __('Menu setup service') }}</span>
    </a>
@endif

<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <h2 class="mb-1 text-lg font-bold">{{ __('Your menu link') }}</h2>
        <p class="mb-4 text-sm text-navy-500">{{ __('Share it on social media, or print a QR card for every table from Tables & QR.') }}</p>
        @include('dashboard.partials.menu-links')
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-primary">{{ __('Open menu') }}</a>
            @can('tables.manage')<a href="{{ route('dash.tables') }}" class="btn-ghost">{{ __('Tables & QR') }}</a>@endcan
            <a href="{{ route('dash.help') }}" class="btn-ghost" data-help-link>📖 {{ __('Dashboard tutorial') }}</a>
        </div>
    </div>

    <div class="card">
        <h2 class="mb-1 text-lg font-bold">{{ __('Your plan') }}</h2>
        @if ($subscription)
            <p class="mb-4 text-sm text-navy-500">
                {{ $subscription->plan->displayName() }} · {{ $subscription->statusLabel() }}
                @if ($subscription->endsAt()) · {{ __('until') }} {{ local_time($subscription->endsAt(), 'Y/m/d') }} @endif
            </p>
        @endif
        <div class="space-y-3">
            @foreach ($limits as $l)
                <x-usage-bar :label="$l['label']" :used="$l['used']" :limit="$l['limit']" />
            @endforeach
        </div>
    </div>
</div>
@if ($suggestions)
    <section class="card mt-4">
        <h2 class="mb-3 text-lg font-bold">{{ __('Recommended for your :type', ['type' => \App\Support\BusinessType::label($businessType)]) }}</h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($suggestions as $sug)
                @php
                    $has = \App\Services\Entitlements::current()->allows($sug['feature']);
                @endphp
                <div class="rounded-xl border border-navy-100 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <b>{{ $sug['title'] }}</b>
                        @if ($has)<span class="badge bg-emerald-100 text-emerald-800">{{ __('Included') }}</span>@endif
                    </div>
                    <p class="mt-1 text-sm text-navy-500">{{ $sug['text'] }}</p>
                    @unless ($has)<a href="{{ route('dash.billing') }}" class="mt-2 inline-block text-sm font-semibold text-brand-700">{{ __('Add') }} ←</a>@endunless
                </div>
            @endforeach
        </div>
    </section>
@endif
@endsection
