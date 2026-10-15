@extends('layouts.base')

@php
    $user = auth()->user();
    $sub = tenant()->subscription()->with('plan')->first();
    $rights = \App\Services\Entitlements::current();
    // groups of [route, label, svg path, permission, plan feature]; a group with nothing allowed is hidden
    $groups = [
        [null, [
            ['dash.home', __('Home'), 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z', null, null],
        ]],
        [__('Orders & service'), [
            ['dash.orders', __('Orders'), 'M9 5h10M9 12h10M9 19h10M4 5h.01M4 12h.01M4 19h.01', 'orders.view', 'order_board'],
            ['dash.pos', __('POS'), 'M3 4h18v12H3zM8 20h8M12 16v4', 'pos.use', 'pos'],
            ['dash.callcenter', __('Call center'), 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z', 'callcenter.use', 'call_center'],
            ['dash.kitchen', \App\Support\BusinessType::prepScreen(), 'M6 13.9A4 4 0 0 1 7 6a5 5 0 0 1 10 0 4 4 0 0 1 1 7.9V20H6zM6 17h12', 'kitchen.view', 'kitchen_display'],
            ['dash.shifts', __('Shifts'), 'M12 8v4l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', 'shifts.manage', 'shifts'],
        ]],
        [__('Menu'), [
            ['dash.menu', __('Menu'), 'M4 4h16v16H4zM4 9h16M9 9v11', 'menu.manage', null],
            ['dash.tables', __('Tables & QR'), 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z', 'tables.manage', null],
            ['dash.tv', __('TV screens'), 'M3 5h18v12H3zM8 21h8M12 17v4', 'menu.manage', 'tv_screens'],
        ]],
        [__('Customers & marketing'), [
            ['dash.customers', __('Customers'), 'M17 20v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM21 20v-2a4 4 0 0 0-3-3.9M16 2.1a4 4 0 0 1 0 7.8', 'customers.view', 'customers'],
            ['dash.loyalty.desk', __('Loyalty card'), 'M3 6h18v12H3zM7 10h.01M11 10h.01M15 10h.01M7 14h.01M11 14h.01', 'loyalty.stamp', 'loyalty_stamps'],
            ['dash.marketing', __('Offers & popups'), 'M7 7h.01M3 11V3h8l10 10-8 8L3 11z', 'marketing.manage', 'offers'],
            ['dash.feedback', __('Ratings'), 'M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4l-5.9 3.1 1.2-6.5L2.5 9.4l6.6-.9z', 'customers.view', 'feedback'],
            ['dash.campaigns', __('Campaigns'), 'M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13', 'marketing.manage', 'campaigns'],
        ]],
        [__('Operations'), [
            ['dash.inventory', __('Inventory'), 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8', 'inventory.manage', 'inventory'],
            ['dash.stations', __('Kitchen stations'), 'M4 6h16M4 12h16M4 18h10', 'menu.manage', 'kitchen_stations'],
            ['dash.zones', __('Delivery zones'), 'M1 3h15v13H1zM16 8h4l3 3v5h-7zM5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM18.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z', 'delivery.manage', 'delivery_zones|online_ordering'],
        ]],
        [__('Management'), [
            ['dash.reports', __('Reports'), 'M4 20V10M10 20V4M16 20v-7M22 20H2', 'reports.view', 'daily_reports'],
            ['dash.branches', __('Branches'), 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', 'branches.manage', null],
            ['dash.users', __('Users & roles'), 'M16 11a4 4 0 1 0-8 0M4 21a8 8 0 0 1 16 0', 'users.manage', null],
            ['dash.audit', __('Audit log'), 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 5h6M9 12h6M9 16h4', 'audit.view', 'audit_log'],
            ['dash.domain', __('Your own domain'), 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18', 'settings.manage', null],
            ['dash.billing', __('Subscription'), 'M3 6h18v12H3zM3 10h18M7 15h3', 'billing.manage', null],
            ['dash.settings', __('Settings'), 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.8 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.8-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 9 4.6V4.5a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.8 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 21 11h.1a2 2 0 1 1 0 4z', 'settings.manage', null],
        ]],
    ];
    $allowed = fn (array $i) => (! $i[3] || $user->can($i[3])) && (! $i[4] || $rights->allows($i[4]));
    $groups = array_values(array_filter(array_map(fn ($g) => [$g[0], array_values(array_filter($g[1], $allowed))], $groups), fn ($g) => $g[1] !== []));
    $nav = array_merge(...array_map(fn ($g) => $g[1], $groups));
@endphp

@section('body')
<div class="flex min-h-screen">
    <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col bg-navy-800 text-white lg:flex">
        <div class="px-5 py-5"><x-logo :light="true" /></div>
        <nav class="flex-1 space-y-4 overflow-y-auto px-3 pb-4 [scrollbar-color:rgba(255,255,255,.15)_transparent] [scrollbar-width:thin]">
            @foreach ($groups as [$title, $items])
                <div class="space-y-0.5">
                    @if ($title)<div class="px-3 pb-1 text-[11px] font-bold tracking-wide text-white/40">{{ $title }}</div>@endif
                    @foreach ($items as [$route, $label, $icon])
                        @php
                            $active = request()->routeIs($route) || ($route !== 'dash.home' && request()->routeIs($route.'.*')) || ($route === 'dash.menu' && request()->routeIs('dash.items.*'));
                        @endphp
                        <a href="{{ route($route) }}" @class(['flex min-h-10 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition',
                            'bg-white/10 text-white' => $active, 'text-white/70 hover:bg-white/5 hover:text-white' => !$active])>
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
        <div class="border-t border-white/10 p-4 text-xs text-white/50">EgMenu · by EgWeb</div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex items-center justify-between gap-3 border-b border-navy-100 bg-white px-4 py-3 sm:px-6">
            <div class="min-w-0">
                <div class="truncate font-bold">{{ tenant()->name }}</div>
                <div class="text-xs text-navy-500">{{ $user->name }} · {{ $user->roleName() }}</div>
            </div>
            <div class="flex items-center gap-2">
                @if ($sub)
                    <span class="badge {{ $sub->status === 'trialing' ? 'bg-brand-100 text-brand-700' : 'bg-navy-100 text-navy-700' }}">
                        {{ $sub->plan->displayName() }}@if ($sub->status === 'trialing') · {{ __(':days days left', ['days' => $sub->daysLeft()]) }}@endif
                    </span>
                @endif
                <form method="post" action="{{ route('dash.locale') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button class="btn-ghost min-h-9 px-3">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</button>
                </form>
                <form method="post" action="{{ route('dash.logout') }}">
                    @csrf
                    <button class="btn-ghost min-h-9 px-3">{{ __('Log out') }}</button>
                </form>
            </div>
        </header>
        {{-- Phones/tablets: the sidebar becomes a scrollable strip. --}}
        <nav class="flex gap-1 overflow-x-auto border-b border-navy-100 bg-white px-3 py-2 lg:hidden">
            @foreach ($nav as [$route, $label])
                <a href="{{ route($route) }}" @class(['shrink-0 rounded-lg px-3 py-2 text-sm font-semibold', 'bg-navy-800 text-white' => request()->routeIs($route), 'text-navy-700' => !request()->routeIs($route)])>{{ $label }}</a>
            @endforeach
        </nav>
        <main class="flex-1 p-4 sm:p-6">
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
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
@endsection
