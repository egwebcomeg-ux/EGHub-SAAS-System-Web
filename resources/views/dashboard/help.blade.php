@extends('layouts.dashboard')
@section('title', __('Dashboard tutorial'))

@php
    $user = auth()->user();
    $rights = \App\Services\Entitlements::current();
    // "a|b" = either permission; null = everyone
    $may = fn (?string $permission) => $permission === null || collect(explode('|', $permission))->contains(fn (string $p) => $user->can($p));
    // [title, text, screenshot in public/img/site (without extension) or null, route name, permission, plan feature or null]
    // A step shows only to staff who may open its page and when the plan has its feature.
    $all = [
        [__('Enter your menu'), __('Categories first, then the items with a price and a photo. Items with photos sell better. Short on time? Import from Excel or a photo of the old menu, or let our team enter it for you.'), 'menu-editor', 'dash.menu', 'menu.manage', null],
        [__('Make it yours'), __('Logo, colors, font and the look of the menu are in Settings. The same colors appear on the QR cards and in the app icon guests add to their phones.'), 'settings', 'dash.settings', 'settings.manage', null],
        [__('Print the QR cards'), __('Add your tables, then print a card for each one from Tables & QR. Every table has its own code, so orders arrive with the table number.'), 'tables', 'dash.tables', 'tables.manage', null],
        [__('Follow the orders live'), __('Orders from the tables show up on the board the moment they are sent. Move them from new to preparing to ready, and the guest sees it on their phone.'), 'orders', 'dash.orders', 'orders.view', 'order_board'],
        [__('Bring your team in'), __('Give the cashier, the kitchen and the managers their own logins. Each role sees only what it needs; the owner can require two-factor authentication for everyone.'), null, 'dash.users', 'users.manage', null],
        [__('Set up the POS terminals'), __('Name each till (“Cashier 1”, K1) from POS terminals and set how long a device may sell offline: when the internet drops the cashier keeps taking cash, card, InstaPay and wallet payments, and the sales sync later with real numbers. Close the day with the Z report: sales by payment method, shift and cashier.'), 'pos', 'dash.terminals', 'branches.manage', 'pos'],
        [__('Add your drivers'), __('Add each rider as a user with the driver role. Give delivery orders to a driver from the order board or the call center; the driver taps picked up and delivered on their phone, guests follow the ride live, and the cash each driver holds is settled into the shift from the drivers page.'), null, 'dash.drivers', 'delivery.assign|delivery.drivers', 'delivery_zones|online_ordering'],
        [__('Sign corporate contracts'), __('A company pays up front for vouchers its staff spend at your place: create the contract, print the vouchers with their codes, void a lost one, and read the report of what was issued, redeemed and is still outstanding.'), null, 'dash.corporate', 'contracts.manage', 'corporate_vouchers'],
        [__('Redeem vouchers at the counter'), __('The redeem screen needs no POS: type or scan the voucher code, pick the open order or a paper bill, and print the receipt. An expired voucher needs a manager’s PIN.'), null, 'dash.redeem', 'vouchers.redeem', 'corporate_vouchers'],
        [__('Keep guests coming back'), __('A digital loyalty card with stamps or points, timed offers and popups, and a WhatsApp message when the order is ready.'), 'loyalty-setup', 'dash.loyalty.setup', 'loyalty.manage', 'loyalty_stamps'],
        [__('Send WhatsApp from your own number'), __('Guests can get your WhatsApp messages from your restaurant’s own WhatsApp Business number and name. The page walks you through Meta step by step: the number, a system user with a permanent token, then paste four values. Messages from your number are billed by Meta to your own account, without EgHub credits.'), null, 'dash.whatsapp-number', 'settings.manage', 'whatsapp_notifications'],
        [__('Read the numbers'), __('Best sellers, busy hours and sales by day. Export them to Excel whenever you need.'), 'reports', 'dash.reports', 'reports.view', 'daily_reports'],
        [__('Add the dashboard to your phone'), __('Open this dashboard in Chrome or Safari and choose "Add to Home Screen": it opens like an app. Protect your account from Account security with a code from your phone.'), null, 'dash.security', null, null],
    ];
    $steps = array_values(array_filter($all, fn (array $s) => $may($s[4]) && ($s[5] === null || $rights->allows($s[5]))));
@endphp

@section('content')
<div class="dash-head">
    <div>
        <h1 class="page-title">{{ __('Dashboard tutorial') }}</h1>
        <p class="dash-help">{{ __(':n short steps from an empty account to a menu guests scan every day.', ['n' => count($steps)]) }}</p>
    </div>
    <a href="{{ route('dash.home') }}" class="btn-ghost">{{ __('Back to home') }}</a>
</div>

@php($videos = \App\Support\Tutorials::visibleTo($user))
@if ($videos)
    <section class="card mb-4 max-w-5xl" data-help-videos>
        <h2 class="section-title mb-1">▶ {{ __('Video tutorials') }}</h2>
        <p class="mb-3 text-sm text-navy-500">{{ __('Short videos of each screen, step by step. The page of each one also has its own button.') }}</p>
        <div class="flex flex-wrap gap-2">@include('dashboard.partials.tutorials', ['tutorials' => $videos, 'inline' => true])</div>
    </section>
@endif

<ol class="grid max-w-5xl gap-4 lg:grid-cols-2" data-help-steps data-help-count="{{ count($steps) }}">
    @foreach ($steps as $i => [$title, $text, $image, $route, $permission, $feature])
        <li class="card flex flex-col" data-help-step="{{ $route }}">
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
            @if (\Illuminate\Support\Facades\Route::has($route))
                <a href="{{ route($route) }}" class="mt-3 inline-block text-sm font-semibold text-brand-700">{{ __('Open') }} ←</a>
            @endif
        </li>
    @endforeach
</ol>
@endsection
