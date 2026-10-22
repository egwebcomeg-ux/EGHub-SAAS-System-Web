@extends('layouts.base', ['viteEntries' => array_merge(['resources/js/notifications.js', 'resources/js/dash-ui.js'], $viteEntries ?? [])])

@php
    $user = auth()->user();
    $sub = tenant()->subscription()->with('plan')->first();
    // the apps (route, label, icon, rules) live once in App\Support\DashboardApps: the home grid reads the same list.
    // The home page passes its copy along so the list is worked out once per request.
    $apps = $dashboardApps ?? \App\Support\DashboardApps::forUser($user);
    $groups = $apps['groups'];
    // the modules the owner picked come first; the rest wait under "more modules" (focus only, every link still works)
    $more = $apps['more'];
    $inMore = collect($more)->contains(fn ($i) => request()->routeIs($i['route']) || request()->routeIs($i['route'].'.*'));
    $canPickModules = \App\Services\ModuleChoice::canChange($user);
    $nav = array_merge(...array_map(fn ($g) => $g['apps'], $groups));
@endphp

@section('body')
@php
    $navLink = 'relative flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition lg:min-h-10';
    $navOn = 'bg-white/10 text-white before:absolute before:inset-y-2 before:start-0 before:w-1 before:rounded-full before:bg-brand-600';
    // pages that live inside another entry: shifts in the POS area, stations under the kitchen display
    $within = ['dash.menu' => ['dash.items.*'], 'dash.pos' => ['dash.shifts', 'dash.shifts.*', 'dash.terminals', 'dash.terminals.*', 'dash.zreport', 'dash.zreport.*'], 'dash.kitchen' => ['dash.stations', 'dash.stations.*'],
        'dash.coupons' => ['dash.vouchers', 'dash.vouchers.*'], 'dash.devices' => ['dash.tv', 'dash.tv.*', 'dash.kiosks', 'dash.kiosks.*']];
    $isActive = fn (string $route) => request()->routeIs($route) || ($route !== 'dash.home' && request()->routeIs($route.'.*')) || request()->routeIs(...($within[$route] ?? ['-']));
    $currentLabel = collect(array_merge($nav, $more))->first(fn ($i) => $isActive($i['route']))['label'] ?? null;
@endphp
<div class="dash-app flex min-h-screen">
    {{-- Sidebar: sticky on desktop, an off-canvas drawer under 1024px (resources/js/dash-ui.js). --}}
    <div class="dash-nav-backdrop fixed inset-0 z-40 bg-navy-900/50 backdrop-blur-[2px] lg:hidden" data-dash-nav-backdrop aria-hidden="true"></div>
    <aside id="dash-nav" class="dash-nav fixed inset-y-0 start-0 z-50 flex w-72 max-w-[85vw] flex-col bg-navy-800 text-white shadow-2xl lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:w-64 lg:max-w-none lg:shadow-none"
           data-dash-nav aria-label="{{ __('Dashboard') }}">
        <div class="flex items-center justify-between gap-2 px-5 py-4 lg:py-5">
            <a href="{{ route('dash.home') }}" class="rounded-lg"><x-logo :light="true" /></a>
            <button type="button" class="grid size-11 place-items-center rounded-xl text-white/70 hover:bg-white/10 hover:text-white lg:hidden" data-dash-nav-close aria-label="{{ __('Close') }}">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <nav class="flex-1 space-y-4 overflow-y-auto overscroll-contain px-3 pb-4 [scrollbar-color:rgba(255,255,255,.15)_transparent] [scrollbar-width:thin]">
            @foreach ($groups as ['title' => $title, 'apps' => $items])
                <div class="space-y-0.5">
                    @if ($title)<div class="px-3 pb-1 text-[11px] font-bold tracking-wide text-white/40">{{ $title }}</div>@endif
                    @foreach ($items as ['route' => $route, 'label' => $label, 'icon' => $icon])
                        @php($active = $isActive($route))
                        <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif @class([$navLink, $navOn => $active, 'text-white/70 hover:bg-white/5 hover:text-white' => ! $active])>
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                            <span class="truncate">{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
            @if ($more !== [])
                <details class="group/more" data-nav-more @if ($inMore) open @endif>
                    <summary class="{{ $navLink }} cursor-pointer list-none text-white/60 hover:bg-white/5 hover:text-white [&::-webkit-details-marker]:hidden">
                        <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg>
                        {{ __('More modules') }}
                        <span class="ms-auto rounded-full bg-white/10 px-2 text-xs">{{ count($more) }}</span>
                        <svg class="size-4 shrink-0 transition group-open/more:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <div class="ms-5 mt-0.5 space-y-0.5 border-s border-white/10 ps-2">
                        @foreach ($more as ['route' => $route, 'label' => $label, 'icon' => $icon])
                            @php($active = request()->routeIs($route) || request()->routeIs($route.'.*'))
                            <a href="{{ route($route) }}" data-nav-more-item="{{ $route }}" @if ($active) aria-current="page" @endif @class([$navLink, $navOn => $active, 'text-white/60 hover:bg-white/5 hover:text-white' => ! $active])>
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                                <span class="truncate">{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                </details>
            @endif
            @if ($canPickModules)
                <a href="{{ route('dash.modules') }}" data-nav-modules @if (request()->routeIs('dash.modules')) aria-current="page" @endif @class([$navLink, $navOn => request()->routeIs('dash.modules'), 'text-white/50 hover:bg-white/5 hover:text-white' => ! request()->routeIs('dash.modules')])>
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3 8-8M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>
                    {{ __('Your modules') }}
                </a>
            @endif
        </nav>
        {{-- account: on phones the language and log out live here; the bottom keeps clear of the home indicator --}}
        <div class="border-t border-white/10 px-4 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <a href="{{ route('dash.security') }}" class="flex min-h-11 items-center gap-3 rounded-xl px-2 hover:bg-white/5" title="{{ __('Account security') }}">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white/10 text-sm font-bold">{{ mb_substr($user->name, 0, 1) }}</span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-semibold">{{ $user->name }}</span>
                    <span class="block truncate text-xs text-white/50">{{ $user->roleName() }}</span>
                </span>
            </a>
            <div class="mt-2 grid grid-cols-2 gap-2 lg:hidden">
                <form method="post" action="{{ route('dash.locale') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button class="flex min-h-11 w-full items-center justify-center rounded-xl bg-white/10 px-3 text-sm font-semibold hover:bg-white/15">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</button>
                </form>
                <form method="post" action="{{ route('dash.logout') }}">
                    @csrf
                    <button class="flex min-h-11 w-full items-center justify-center rounded-xl bg-white/10 px-3 text-sm font-semibold hover:bg-white/15">{{ __('Log out') }}</button>
                </form>
            </div>
            <div class="mt-2 hidden px-2 text-xs text-white/40 lg:block">EgMenu · by EgWeb</div>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 flex min-h-14 items-center justify-between gap-2 border-b border-navy-100 bg-white/95 px-3 py-2 backdrop-blur sm:px-6 lg:static lg:min-h-16 lg:bg-white lg:py-3 lg:backdrop-blur-none">
            <div class="flex min-w-0 items-center gap-2">
                <button type="button" class="grid size-11 shrink-0 place-items-center rounded-xl text-navy-800 hover:bg-navy-50 lg:hidden" data-dash-nav-toggle aria-controls="dash-nav" aria-expanded="false" aria-label="{{ __('Main menu') }}">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <a href="{{ route('dash.home') }}" @class(['grid size-11 shrink-0 place-items-center rounded-xl lg:hidden', 'bg-brand-50 text-brand-700' => request()->routeIs('dash.home'), 'text-navy-800 hover:bg-navy-50' => ! request()->routeIs('dash.home')])
                   data-header-apps aria-label="{{ __('Apps') }}" title="{{ __('Apps') }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \App\Support\DashboardApps::APPS_ICON }}"/></svg>
                </a>
                <div class="min-w-0 leading-tight">
                    <div class="truncate font-bold">{{ tenant()->name }}</div>
                    <div class="truncate text-xs text-navy-500">
                        <span class="lg:hidden">{{ $currentLabel ?? $user->roleName() }}</span>
                        <span class="hidden lg:inline"><a href="{{ route('dash.security') }}" class="hover:underline" title="{{ __('Account security') }}">{{ $user->name }}</a> · {{ $user->roleName() }}</span>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if (\App\Http\Controllers\Dashboard\NotificationsController::visibleFor($user))
                    {{-- the bell: new / ready orders, waiter calls, bookings, low stock (polled, see notifications.js) --}}
                    <div class="relative" data-notifications data-feed="{{ route('dash.notifications') }}" data-seen="{{ route('dash.notifications.seen') }}"
                         data-empty="{{ __('Nothing new in the last hours.') }}">
                        <button type="button" class="btn-ghost relative min-h-10 px-2.5" data-bell aria-haspopup="true" aria-expanded="false" title="{{ __('Notifications') }}">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                            <span class="sr-only">{{ __('Notifications') }}</span>
                            <span class="absolute -end-1 -top-1 hidden min-w-5 rounded-full bg-brand-600 px-1 text-center text-[11px] font-bold leading-5 text-white" data-bell-count></span>
                        </button>
                        <div class="fixed inset-x-3 top-16 z-40 mt-0 hidden overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-navy-100 sm:absolute sm:inset-x-auto sm:end-0 sm:top-auto sm:mt-2 sm:w-[22rem]" data-bell-panel>
                            <div class="flex items-center justify-between border-b border-navy-100 px-4 py-2.5">
                                <span class="font-bold">{{ __('Notifications') }}</span>
                                <span class="text-xs text-navy-500">{{ __('Last :n hours', ['n' => \App\Http\Controllers\Dashboard\NotificationsController::WINDOW_HOURS]) }}</span>
                            </div>
                            <ul class="max-h-[min(60dvh,32rem)] divide-y divide-navy-50 overflow-y-auto overscroll-contain" data-bell-list></ul>
                        </div>
                    </div>
                @endif
                @if ($sub)
                    <span class="badge hidden sm:inline-flex {{ $sub->status === 'trialing' ? 'bg-brand-100 text-brand-700' : 'bg-navy-100 text-navy-700' }}">
                        {{ $sub->plan->displayName() }}@if ($sub->status === 'trialing') · {{ __(':days days left', ['days' => $sub->daysLeft()]) }}@endif
                    </span>
                @endif
                <form method="post" action="{{ route('dash.locale') }}" class="hidden lg:block">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button class="btn-ghost min-h-10 px-3">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</button>
                </form>
                <form method="post" action="{{ route('dash.logout') }}" class="hidden lg:block">
                    @csrf
                    <button class="btn-ghost min-h-10 px-3">{{ __('Log out') }}</button>
                </form>
            </div>
        </header>
        <main class="mx-auto w-full max-w-[100rem] flex-1 px-4 py-5 sm:px-6 sm:py-6 lg:px-8">
            @if ($sub && $sub->needsAttention() && $user->can('billing.manage') && !request()->routeIs('dash.billing*'))
                <div @class(['mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm font-semibold',
                    'bg-red-50 text-red-800' => $sub->status === 'past_due', 'bg-amber-50 text-amber-800' => $sub->status !== 'past_due'])>
                    <span>
                        @if ($sub->status === 'past_due')
                            {{ __('Your subscription ended. Pay before :date to keep your menu online.', ['date' => local_time($sub->grace_ends_at, 'Y/m/d')]) }}
                        @elseif ($sub->status === 'trialing')
                            {{ __('Your free trial ends in :days days. Choose a plan to keep your menu online.', ['days' => $sub->daysLeft()]) }}
                        @else
                            {{ __('Your subscription renews in :days days.', ['days' => $sub->daysLeft()]) }}
                        @endif
                    </span>
                    <a href="{{ route('dash.billing') }}" class="btn-primary min-h-9 px-3">{{ __('Choose a plan') }}</a>
                </div>
            @endif
            @include('dashboard.partials.whatsapp-banner')
            @include('dashboard.partials.phone-banner')
            <x-flash :toast="true" />
            @yield('content')
        </main>
    </div>
</div>
@endsection
