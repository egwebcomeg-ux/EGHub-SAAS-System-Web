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

{{-- the first page after a self sign-up tells EgHub's ad pixel about the new restaurant (once: a flash) --}}
@if (session('platform_signup'))
    @push('scripts')
        @include('partials.platform-pixel', ['event' => 'CompleteRegistration'])
    @endpush
@endif

@section('body')
@php
    $navLink = 'relative flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition lg:min-h-10';
    $navOn = 'bg-white/10 text-white before:absolute before:inset-y-2 before:start-0 before:w-1 before:rounded-full before:bg-brand-600';
    // a page that lives inside another app (shifts in the POS, stations under the kitchen…): DashboardApps::WITHIN
    $isActive = fn (string $route) => \App\Support\DashboardApps::isActive($route);
    $currentLabel = collect(array_merge($nav, $more))->first(fn ($i) => $isActive($i['route']))['label'] ?? null;
    // Odoo-style chrome on desktop: the home (apps grid) has no sidebar; inside an app a slim rail shows only the
    // apps of the current group, and the app header strip shows them as tabs. The rail opens to the full sidebar.
    $onHome = request()->routeIs('dash.home');
    $appGroup = \App\Support\DashboardApps::currentGroup($apps);
    $appHue = \App\Support\DashboardApps::HUES[$appGroup['key']] ?? 'bg-app-management';
    $settingsApp = collect(array_merge($nav, $more))->firstWhere('route', 'dash.settings');
    $railLink = 'relative grid size-11 place-items-center rounded-xl transition';
    $chevron = 'M9 18l6-6-6-6';
@endphp
<div class="dash-app flex min-h-screen" data-dash-app @if ($onHome) data-dash-home @endif>
    {{-- the choice "full sidebar instead of the rail" is remembered by the browser; set before paint so nothing jumps --}}
    @unless ($onHome)
        <script>try { if (localStorage.getItem('dash-nav-expanded') === '1') { document.currentScript.parentElement.setAttribute('data-nav-expanded', ''); } } catch (e) {}</script>
    @endunless
    {{-- Sidebar: an off-canvas drawer under 1024px (resources/js/dash-ui.js); on desktop hidden on the home page and,
         inside an app, folded into the slim rail until the rail is expanded. --}}
    <div class="dash-nav-backdrop fixed inset-0 z-40 bg-navy-900/50 backdrop-blur-[2px] lg:hidden" data-dash-nav-backdrop aria-hidden="true"></div>
    @unless ($onHome)
        {{-- the slim rail (desktop, inside an app): the apps grid, the apps of this group, then settings and the account --}}
        <aside class="dash-rail sticky top-0 z-20 hidden h-screen w-[72px] shrink-0 flex-col items-center bg-navy-800 py-3 text-white lg:flex"
               data-dash-rail aria-label="{{ $appGroup['title'] ?? __('Dashboard') }}">
            <a href="{{ route('dash.home') }}" class="{{ $railLink }} text-white/80 hover:bg-white/10 hover:text-white" data-rail-apps title="{{ __('Apps') }}" aria-label="{{ __('Apps') }}">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \App\Support\DashboardApps::APPS_ICON }}"/></svg>
            </a>
            <div class="my-2 h-px w-8 shrink-0 bg-white/10" aria-hidden="true"></div>
            <nav class="flex min-h-0 w-full flex-1 flex-col items-center gap-1 overflow-y-auto [scrollbar-width:none]" aria-label="{{ $appGroup['title'] ?? __('Dashboard') }}">
                @foreach ($appGroup['apps'] as ['route' => $route, 'label' => $label, 'icon' => $icon])
                    @continue($route === 'dash.settings')
                    @php($active = $isActive($route))
                    <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif data-rail-item="{{ $route }}" title="{{ $label }}" aria-label="{{ $label }}"
                       @class([$railLink, 'shrink-0', 'bg-white/10 text-white before:absolute before:inset-y-2.5 before:-start-3.5 before:w-1 before:rounded-full before:bg-brand-600' => $active, 'text-white/60 hover:bg-white/5 hover:text-white' => ! $active])>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    </a>
                @endforeach
            </nav>
            <div class="my-2 h-px w-8 shrink-0 bg-white/10" aria-hidden="true"></div>
            @if ($settingsApp)
                @php($active = $isActive('dash.settings'))
                <a href="{{ route('dash.settings') }}" @if ($active) aria-current="page" @endif data-rail-item="dash.settings" title="{{ $settingsApp['label'] }}" aria-label="{{ $settingsApp['label'] }}"
                   @class([$railLink, 'bg-white/10 text-white before:absolute before:inset-y-2.5 before:-start-3.5 before:w-1 before:rounded-full before:bg-brand-600' => $active, 'text-white/60 hover:bg-white/5 hover:text-white' => ! $active])>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $settingsApp['icon'] }}"/></svg>
                </a>
            @endif
            <a href="{{ route('dash.security') }}" class="{{ $railLink }} mt-1 hover:bg-white/5" title="{{ $user->name }} · {{ __('Account security') }}" aria-label="{{ __('Account security') }}">
                <span class="grid size-9 place-items-center rounded-full bg-white/10 text-sm font-bold">{{ mb_substr($user->name, 0, 1) }}</span>
            </a>
            <button type="button" class="{{ $railLink }} mt-1 text-white/50 hover:bg-white/10 hover:text-white" data-dash-rail-toggle aria-controls="dash-nav" aria-expanded="false" title="{{ __('Show the full menu') }}" aria-label="{{ __('Show the full menu') }}">
                <svg class="size-5 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chevron }}"/></svg>
            </button>
        </aside>
    @endunless
    <aside id="dash-nav" @class(['dash-nav fixed inset-y-0 start-0 z-50 flex w-72 max-w-[85vw] flex-col bg-navy-800 text-white shadow-2xl lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:w-64 lg:max-w-none lg:shrink-0 lg:shadow-none', 'lg:hidden' => $onHome])
           data-dash-nav aria-label="{{ __('Dashboard') }}">
        <div class="flex items-center justify-between gap-2 px-5 py-4 lg:py-5">
            <a href="{{ route('dash.home') }}" class="rounded-lg"><x-logo :light="true" /></a>
            <button type="button" class="grid size-11 place-items-center rounded-xl text-white/70 hover:bg-white/10 hover:text-white lg:hidden dash-nav-close-desk" data-dash-nav-close aria-label="{{ __('Close') }}">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            @unless ($onHome)
                <button type="button" class="hidden size-10 place-items-center rounded-xl text-white/60 hover:bg-white/10 hover:text-white lg:grid" data-dash-rail-toggle aria-controls="dash-nav" aria-expanded="true" title="{{ __('Show the slim menu') }}" aria-label="{{ __('Show the slim menu') }}">
                    <svg class="size-5 ltr:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chevron }}"/></svg>
                </button>
            @endunless
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
            <div class="mt-3 px-2"><x-egweb-badge tone="dark" /></div>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-30 flex min-h-14 items-center justify-between gap-2 border-b border-navy-100 bg-white/95 px-3 py-2 backdrop-blur sm:px-6 lg:static lg:min-h-16 lg:bg-white lg:py-3 lg:backdrop-blur-none">
            <div class="flex min-w-0 items-center gap-2">
                <button type="button" @class(['grid size-11 shrink-0 place-items-center rounded-xl text-navy-800 hover:bg-navy-50', 'lg:hidden' => ! $onHome]) data-dash-nav-toggle aria-controls="dash-nav" aria-expanded="false" aria-label="{{ __('Main menu') }}">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <a href="{{ route('dash.home') }}" @class(['grid size-11 shrink-0 place-items-center rounded-xl lg:hidden', 'bg-brand-50 text-brand-700' => request()->routeIs('dash.home'), 'text-navy-800 hover:bg-navy-50' => ! request()->routeIs('dash.home')])
                   data-header-apps aria-label="{{ __('Apps') }}" title="{{ __('Apps') }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \App\Support\DashboardApps::APPS_ICON }}"/></svg>
                </a>
                {{-- desktop without the full sidebar (home, the rail): the logo opens the top bar --}}
                <a href="{{ route('dash.home') }}" class="dash-head-logo hidden shrink-0 items-center self-stretch border-e border-navy-100 pe-4 me-2 lg:flex" data-header-logo aria-label="{{ __('Apps') }}"><x-logo :size="28" /></a>
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
        @unless ($onHome)
            {{-- the app header (desktop): the current app with its colour, and its apps as tabs, like an app's top menu --}}
            <div class="hidden min-h-12 items-center gap-4 border-b border-navy-100 bg-white px-6 lg:flex lg:px-8" data-app-header="{{ $appGroup['key'] }}">
                <a href="{{ route('dash.home') }}" class="flex shrink-0 items-center gap-2.5 py-2" title="{{ __('Apps') }}">
                    <span class="grid size-8 place-items-center rounded-lg text-white shadow-sm {{ $appHue }}">
                        <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \App\Support\DashboardApps::GROUP_ICONS[$appGroup['key']] ?? \App\Support\DashboardApps::APPS_ICON }}"/></svg>
                    </span>
                    <span class="text-sm font-extrabold text-navy-900">{{ $appGroup['title'] }}</span>
                </a>
                @if ($appGroup['apps'] !== [])
                    <nav class="dash-app-tabs -mb-px flex min-w-0 flex-1 items-stretch gap-1 self-stretch overflow-x-auto" aria-label="{{ $appGroup['title'] }}">
                        @foreach ($appGroup['apps'] as ['route' => $route, 'label' => $label])
                            @php($active = $isActive($route))
                            <a href="{{ route($route) }}" @if ($active) aria-current="page" @endif data-app-tab="{{ $route }}"
                               @class(['flex shrink-0 items-center whitespace-nowrap border-b-2 px-3 text-sm font-semibold transition',
                                   'border-brand-600 text-navy-900' => $active, 'border-transparent text-navy-500 hover:border-navy-200 hover:text-navy-900' => ! $active])>{{ $label }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
        @endunless
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
            @include('dashboard.partials.demo-banner')
            @include('dashboard.partials.whatsapp-banner')
            @include('dashboard.partials.phone-banner')
            <x-flash :toast="true" />
            @yield('content')
            @include('dashboard.partials.tutorials')
        </main>
    </div>
</div>
@endsection
