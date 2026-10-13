@extends('layouts.base')

@php
    $user = auth()->user();
    $sub = tenant()->subscription()->with('plan')->first();
    $rights = \App\Services\Entitlements::current();
    // [route, label, svg path, permission, plan feature]
    $nav = [
        ['dash.home', __('Home'), 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z', null, null],
        ['dash.orders', __('Orders'), 'M9 5h10M9 12h10M9 19h10M4 5h.01M4 12h.01M4 19h.01', 'orders.view', 'order_board'],
        ['dash.menu', __('Menu'), 'M4 4h16v16H4zM4 9h16M9 9v11', 'menu.manage', null],
        ['dash.tables', __('Tables & QR'), 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z', 'tables.manage', null],
        ['dash.branches', __('Branches'), 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z', 'branches.manage', null],
        ['dash.users', __('Users & roles'), 'M16 11a4 4 0 1 0-8 0M4 21a8 8 0 0 1 16 0', 'users.manage', null],
        ['dash.billing', __('Subscription'), 'M3 6h18v12H3zM3 10h18M7 15h3', 'billing.manage', null],
        ['dash.settings', __('Settings'), 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.8 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.8-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 9 4.6V4.5a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.8 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 21 11h.1a2 2 0 1 1 0 4z', 'settings.manage', null],
    ];
    // Built in the next phases; listed so staff see where the product is going.
    $soon = [__('Kitchen display'), __('POS'), __('Call center'), __('Loyalty card'), __('Reports')];
@endphp

@section('body')
<div class="flex min-h-screen">
    <aside class="hidden w-64 shrink-0 flex-col bg-navy-800 text-white lg:flex">
        <div class="px-5 py-5"><x-logo :light="true" /></div>
        <nav class="flex-1 space-y-1 px-3">
            @foreach ($nav as [$route, $label, $icon, $perm, $feature])
                @if ((!$perm || $user->can($perm)) && (!$feature || $rights->allows($feature)))
                    @php($active = request()->routeIs($route) || ($route !== 'dash.home' && request()->routeIs(str_replace('dash.', 'dash.', $route) . '.*')) || ($route === 'dash.menu' && request()->routeIs('dash.items.*')))
                    <a href="{{ route($route) }}" @class(['flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-semibold transition',
                        'bg-white/10 text-white' => $active, 'text-white/70 hover:bg-white/5 hover:text-white' => !$active])>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                        {{ $label }}
                    </a>
                @endif
            @endforeach
            <div class="px-3 pt-6 pb-2 text-xs font-semibold text-white/40">{{ __('Coming soon') }}</div>
            @foreach ($soon as $label)
                <div class="flex min-h-10 items-center px-3 text-sm text-white/35">{{ $label }}</div>
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
            @foreach ($nav as [$route, $label, $icon, $perm, $feature])
                @if ((!$perm || $user->can($perm)) && (!$feature || $rights->allows($feature)))
                    <a href="{{ route($route) }}" @class(['shrink-0 rounded-lg px-3 py-2 text-sm font-semibold', 'bg-navy-800 text-white' => request()->routeIs($route), 'text-navy-700' => !request()->routeIs($route)])>{{ $label }}</a>
                @endif
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
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
@endsection
