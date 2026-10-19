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

@php
    $pickModules = $canPickModules && $moduleShortcuts === null;
    $suggestionsHas = fn (array $sug) => \App\Services\Entitlements::current()->allows($sug['feature']);
@endphp
@if ($settingUp || $pickModules || $moduleShortcuts !== null || $suggestions)
    {{--
        "Your next step": one card instead of three. While the setup checklist (App\Services\Onboarding, owners and
        managers) is open it shows the next unfinished steps, the progress and the whole list folded; picking the
        modules is the first step until it is done. After setup, the picked modules and the suggestions for the place.
    --}}
    <section class="card mt-4" @if ($settingUp) data-onboarding data-percent="{{ $onboarding['percent'] }}" @endif data-next-step-card>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold">{{ __('Your next step') }}</h2>
                <p class="text-sm text-navy-500">
                    @if ($settingUp)
                        {{ __(':done of :total steps done', ['done' => $onboarding['done'], 'total' => $onboarding['total']]) }} ·
                    @elseif ($suggestions)
                        {{ __('Recommended for your :type', ['type' => \App\Support\BusinessType::label($businessType)]) }} ·
                    @endif
                    <a href="{{ route('dash.help') }}" class="font-semibold text-brand-700 underline">{{ __('Dashboard tutorial') }}</a>
                </p>
            </div>
            @if ($settingUp)
                <form method="post" action="{{ route('dash.onboarding.dismiss') }}">
                    @csrf<button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Hide this checklist') }}</button>
                </form>
            @endif
        </div>

        @if ($settingUp)
            <div class="mt-3 flex items-center gap-3">
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-navy-100" role="progressbar" aria-valuenow="{{ $onboarding['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Setup progress') }}">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $onboarding['percent'] }}%"></div>
                </div>
                <span class="text-sm font-extrabold text-navy-700" dir="ltr">{{ $onboarding['percent'] }}%</span>
            </div>
        @endif

        @if ($pickModules || $nextSteps)
            <ol class="mt-4 space-y-2">
                @if ($pickModules)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-brand-200 bg-brand-50/60 p-3 sm:flex-nowrap" data-next-step="modules">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-bold text-white">🧩</span>
                        <div class="min-w-0 flex-1 basis-48">
                            <div class="font-bold">{{ __('Pick your modules') }}</div>
                            <div class="text-xs text-navy-600">{{ __('Tell us what you use, and we put it first in your dashboard.') }}</div>
                        </div>
                        <a href="{{ route('dash.modules') }}" data-modules-invite class="btn-primary ms-11 min-h-9 shrink-0 px-4 text-xs sm:ms-0">{{ __('Pick') }}</a>
                    </li>
                @endif
                @foreach ($nextSteps as $step)
                    <li @class(['flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border p-3 sm:flex-nowrap', 'border-brand-200 bg-brand-50/60' => ! $pickModules && $loop->first, 'border-navy-100' => $pickModules || ! $loop->first]) data-next-step="{{ $step['key'] }}">
                        <span @class(['grid size-8 shrink-0 place-items-center rounded-full text-sm font-bold', 'bg-brand-600 text-white' => ! $pickModules && $loop->first, 'bg-navy-100 text-navy-600' => $pickModules || ! $loop->first])>{{ $loop->iteration + ($pickModules ? 1 : 0) }}</span>
                        <div class="min-w-0 flex-1 basis-48">
                            <div class="font-bold">{{ $step['title'] }}</div>
                            <div class="text-xs text-navy-600">{{ $step['text'] }}</div>
                        </div>
                        <div class="ms-11 flex shrink-0 items-center gap-2 sm:ms-0">
                            @if ($step['url'])<a href="{{ $step['url'] }}" class="btn-primary min-h-9 px-4 text-xs">{{ __('Go') }}</a>@endif
                            <form method="post" action="{{ route('dash.onboarding.skip') }}">
                                @csrf<input type="hidden" name="step" value="{{ $step['key'] }}"><button class="btn-ghost min-h-9 px-3 text-xs">{{ __('Skip') }}</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($settingUp)
            {{-- the whole checklist, folded: done, skipped and still to do --}}
            <details class="mt-3 group/all" data-onboarding-all>
                <summary class="flex min-h-10 cursor-pointer list-none items-center gap-1 text-sm font-semibold text-navy-600 hover:text-navy-900 [&::-webkit-details-marker]:hidden">
                    {{ __('All setup steps (:n)', ['n' => $onboarding['total']]) }}
                    <svg class="size-4 transition group-open/all:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <ul class="mt-1 divide-y divide-navy-50">
                    @foreach ($onboarding['steps'] as $step)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5 sm:flex-nowrap" data-onboarding-step="{{ $step['key'] }}" data-state="{{ $step['done'] ? 'done' : ($step['skipped'] ? 'skipped' : 'todo') }}">
                            <span @class(['grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold', 'bg-emerald-500 text-white' => $step['done'], 'bg-navy-100 text-navy-500' => ! $step['done']])>{{ $step['done'] ? '✓' : ($step['skipped'] ? '–' : $loop->iteration) }}</span>
                            <div class="min-w-0 flex-1 basis-48">
                                <div @class(['text-sm font-semibold', 'text-navy-400 line-through' => $step['done'] || $step['skipped']])>{{ $step['title'] }}</div>
                            </div>
                            @if (! $step['done'] && ! $step['skipped'])
                                <div class="ms-9 flex shrink-0 items-center gap-2 sm:ms-0">
                                    @if ($step['url'])<a href="{{ $step['url'] }}" class="btn-ghost min-h-9 px-3 text-xs">{{ __('Go') }}</a>@endif
                                    <form method="post" action="{{ route('dash.onboarding.skip') }}">
                                        @csrf<input type="hidden" name="step" value="{{ $step['key'] }}"><button class="min-h-9 px-2 text-xs text-navy-500 underline">{{ __('Skip') }}</button>
                                    </form>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if ($suggestions)
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-home-suggestions>
                @foreach ($suggestions as $sug)
                    <div class="rounded-xl border border-navy-100 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <b>{{ $sug['title'] }}</b>
                            @if ($suggestionsHas($sug))<span class="badge bg-emerald-100 text-emerald-800">{{ __('Included') }}</span>@endif
                        </div>
                        <p class="mt-1 text-sm text-navy-500">{{ $sug['text'] }}</p>
                        @unless ($suggestionsHas($sug))<a href="{{ route('dash.billing') }}" class="mt-2 inline-block text-sm font-semibold text-brand-700">{{ __('Add') }} ←</a>@endunless
                    </div>
                @endforeach
            </div>
        @endif

        @if ($moduleShortcuts !== null)
            {{-- the modules the owner picked (App\Services\ModuleChoice), one tap away --}}
            <div class="mt-4 border-t border-navy-100 pt-3" data-home-modules>
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-navy-700">{{ __('Your modules') }}</h3>
                    @if ($canPickModules)<a href="{{ route('dash.modules') }}" class="text-sm font-semibold text-brand-700 underline">{{ __('Change your modules') }}</a>@endif
                </div>
                @if ($moduleShortcuts === [])
                    <p class="muted">{{ __('The modules you picked open from the menu on the side.') }}</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($moduleShortcuts as $shortcut)
                            <a href="{{ $shortcut['url'] }}" data-home-module="{{ $shortcut['module']['slug'] }}" @class(['flex min-h-10 items-center gap-2 rounded-full border px-3 py-1 text-sm font-semibold transition',
                                'border-navy-100 text-navy-800 hover:border-brand-500 hover:bg-brand-50/60' => ! $shortcut['locked'],
                                'border-dashed border-navy-200 text-navy-500 hover:border-brand-500' => $shortcut['locked']])>
                                <x-landing.icon :name="$shortcut['module']['icon']" :class="$shortcut['locked'] ? 'size-4 shrink-0 text-navy-300' : 'size-4 shrink-0 text-brand-600'" />
                                {{ $shortcut['module']['name'] }}
                                @if ($shortcut['locked'])<span class="text-xs font-semibold text-brand-700">· {{ __('Upgrade') }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
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
@endsection
