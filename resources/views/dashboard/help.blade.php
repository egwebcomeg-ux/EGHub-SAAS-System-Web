@extends('layouts.dashboard')
@section('title', __('Dashboard tutorial'))

@php
    // [title, text, screenshot in public/img/site (without extension) or null, route name, permission]
    $steps = [
        [__('Enter your menu'), __('Categories first, then the items with a price and a photo. Items with photos sell better. Short on time? Import from Excel or a photo of the old menu, or let our team enter it for you.'), 'menu-editor', 'dash.menu', 'menu.manage'],
        [__('Make it yours'), __('Logo, colors, font and the look of the menu are in Settings. The same colors appear on the QR cards and in the app icon guests add to their phones.'), 'settings', 'dash.settings', 'settings.manage'],
        [__('Print the QR cards'), __('Add your tables, then print a card for each one from Tables & QR. Every table has its own code, so orders arrive with the table number.'), 'tables', 'dash.tables', 'tables.manage'],
        [__('Follow the orders live'), __('Orders from the tables show up on the board the moment they are sent. Move them from new to preparing to ready, and the guest sees it on their phone.'), 'orders', 'dash.orders', 'orders.view'],
        [__('Bring your team in'), __('Give the cashier, the kitchen and the managers their own logins. Each role sees only what it needs; the owner can require two-factor authentication for everyone.'), null, 'dash.users', 'users.manage'],
        [__('Keep guests coming back'), __('A digital loyalty card with stamps or points, timed offers and popups, and a WhatsApp message when the order is ready.'), 'loyalty-setup', 'dash.loyalty.setup', 'loyalty.manage'],
        [__('Read the numbers'), __('Best sellers, busy hours and sales by day. Export them to Excel whenever you need.'), 'reports', 'dash.reports', 'reports.view'],
        [__('Add the dashboard to your phone'), __('Open this dashboard in Chrome or Safari and choose "Add to Home Screen": it opens like an app. Protect your account from Account security with a code from your phone.'), null, 'dash.security', null],
    ];
@endphp

@section('content')
<div class="dash-head">
    <div>
        <h1 class="page-title">{{ __('Dashboard tutorial') }}</h1>
        <p class="dash-help">{{ __('Eight short steps from an empty account to a menu guests scan every day.') }}</p>
    </div>
    <a href="{{ route('dash.home') }}" class="btn-ghost">{{ __('Back to home') }}</a>
</div>

<ol class="grid max-w-5xl gap-4 lg:grid-cols-2" data-help-steps>
    @foreach ($steps as $i => [$title, $text, $image, $route, $permission])
        <li class="card flex flex-col">
            <div class="flex items-start gap-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-navy-800 text-sm font-extrabold text-white">{{ $i + 1 }}</span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-bold">{{ $title }}</h2>
                    <p class="mt-1 text-sm text-navy-600">{{ $text }}</p>
                </div>
            </div>
            @if ($image)
                <img src="{{ versioned_asset('img/site/'.$image.'-sm.webp') }}" srcset="{{ versioned_asset('img/site/'.$image.'.webp') }} 2x" alt="" loading="lazy" class="mt-3 w-full rounded-xl border border-navy-100 object-cover">
            @endif
            @if (! $permission || auth()->user()->can($permission))
                @if (\Illuminate\Support\Facades\Route::has($route))
                    <a href="{{ route($route) }}" class="mt-3 inline-block text-sm font-semibold text-brand-700">{{ __('Open') }} ←</a>
                @endif
            @endif
        </li>
    @endforeach
</ol>
@endsection
