@extends('layouts.base')
@section('title', __('Welcome to EgHub') . ' · ' . $brand)
@section('body_class', 'bg-navy-50 text-navy-900 print:bg-white')

{{--
    The owner's welcome page (App\Http\Controllers\Dashboard\WelcomeKitController): one A4 sheet when printed or
    saved as PDF from the browser ("Download as PDF" = the print dialog). The temporary password shows only while
    the owner has not changed it.
--}}
@push('head')
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<style>
    .wk-qr svg { width: 100%; height: auto; display: block; }
    @page { size: A4; margin: 10mm; }
    @media print {
        html, body { background: #fff !important; }
        .wk-no-print { display: none !important; }
        .wk-sheet { box-shadow: none !important; border: 0 !important; margin: 0 !important; padding: 0 !important; max-width: none !important; }
        .wk-card { box-shadow: none !important; break-inside: avoid; }
        .wk-sheet a { text-decoration: none; color: inherit; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: 12px; }
        .wk-steps li { break-inside: avoid; padding: 6px 10px !important; }
        .wk-steps { display: grid !important; grid-template-columns: 1fr 1fr; gap: 6px; }
        .wk-steps > * + * { margin-top: 0 !important; }
        .wk-steps li:last-child { grid-column: 1 / -1; }
        .wk-sheet section, .wk-sheet > div { margin-top: 12px !important; }
        .wk-sheet h1 { font-size: 24px !important; }
        .wk-qr { width: 8.5rem !important; }
        .wk-modules a { min-height: 2.25rem !important; }
        /* the full feature list is the PDF's second page: page 1 stays the "start here" sheet */
        .wk-features { break-before: page; }
        .wk-features > div { display: grid !important; grid-template-columns: 1fr 1fr; gap: 10px 20px; }
        .wk-features li { font-size: 11px; }
    }
</style>
@endpush

@section('body')
@php
    $trial = $sub && $sub->status === 'trialing';
    $steps = [
        ['🔐', __('Change your password'), __('Sign in with the temporary password; the dashboard asks for your own right away.'), route('dash.security')],
        $logo
            ? ['🎨', __('Check your logo and colours'), __('We did it for you: check that they look right on your menu.'), route('dash.settings', ['tab' => 'identity'])]
            : ['🎨', __('Upload your logo and colours'), __('Settings → Identity: your logo, then your colours under Menu.'), route('dash.settings', ['tab' => 'identity'])],
        ['📋', __('Check your menu and prices'), __('Add or import your items (Excel, PDF or photos) and fix any price.'), route('dash.menu')],
        ['🖨', __('Print the table QR codes'), __('Ready cards for every table: print them and put them on the tables.'), route('dash.tables.print')],
        ['📱', __('Try an order from your phone'), __('Scan the QR below or open your menu link and send a test order.'), $menuUrl],
    ];
    $modules = array_values(array_filter([
        $rights->allows('qr_ordering|order_board|online_ordering') ? ['🧾', __('Orders board'), route('dash.orders')] : null,
        $rights->allows('pos') ? ['💳', __('POS cashier'), route('dash.pos')] : null,
        $rights->allows('loyalty_stamps|loyalty_points') ? ['⭐', __('Loyalty card'), route('dash.loyalty.setup')] : null,
        $rights->allows('daily_reports|advanced_reports') ? ['📊', __('Reports'), route('dash.reports')] : null,
        $rights->allows('customers') ? ['👥', __('Customers'), route('dash.customers')] : null,
        $rights->allows('reservations') ? ['📅', __('Reservations'), route('dash.reservations')] : null,
    ]));
@endphp
<main class="wk-sheet mx-3 my-4 max-w-3xl sm:mx-auto sm:my-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8 print:my-0" data-welcome-page>
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-navy-100 pb-4">
        <div class="flex items-center gap-3">
            <x-logo :size="30" />
            <span class="text-navy-300" aria-hidden="true">×</span>
            <img src="{{ versioned_asset('img/brand/egweb-on-light.webp') }}" alt="EgWeb" width="90" height="29" class="h-7 w-auto">
        </div>
        <button type="button" class="wk-no-print btn-primary min-h-10 px-4" onclick="window.print()" data-print>⬇ {{ __('Download as PDF') }}</button>
    </header>

    <section class="mt-5 flex items-center gap-4">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $brand }}" class="h-16 w-auto max-w-32 shrink-0 rounded-2xl border border-navy-100 bg-white object-contain p-1">
        @endif
        <div class="min-w-0">
            <p class="text-sm font-semibold text-brand-700">{{ __('Welcome to EgHub') }}</p>
            <h1 class="text-2xl font-black leading-tight sm:text-3xl">{{ $brand }}</h1>
            <p class="mt-1 text-sm text-navy-500">
                @if ($sub?->plan){{ __('Plan: :plan', ['plan' => $sub->plan->displayName()]) }}@endif
                @if ($trial) · {{ __('Free trial until :date', ['date' => local_time($sub->trial_ends_at, 'Y/m/d')]) }}@endif
            </p>
        </div>
    </section>

    <div class="mt-5 grid gap-4 sm:grid-cols-[1fr_1.3fr]">
        <section class="wk-card rounded-2xl border border-navy-100 p-4 text-center">
            <h2 class="text-sm font-bold text-navy-500">{{ __('Your menu') }}</h2>
            <div class="wk-qr mx-auto mt-2 w-40" data-menu-qr>{!! $qr !!}</div>
            <a href="{{ $menuUrl }}" dir="ltr" class="mt-2 block break-all text-sm font-bold text-navy-700" data-menu-url>{{ preg_replace('#^https?://#', '', $menuUrl) }}</a>
        </section>
        <section class="wk-card rounded-2xl border border-navy-100 p-4">
            <h2 class="text-sm font-bold text-navy-500">{{ __('Your dashboard') }}</h2>
            <dl class="mt-2 space-y-2 text-sm">
                <div><dt class="text-navy-500">{{ __('Sign in at') }}</dt><dd class="font-bold"><a href="{{ $loginUrl }}" dir="ltr">{{ preg_replace('#^https?://#', '', $loginUrl) }}</a></dd></div>
                <div><dt class="text-navy-500">{{ __('Email') }}</dt><dd class="break-all font-bold" data-login-email><span dir="ltr">{{ $owner->email }}</span></dd>
                    @if ($placeholderEmail)<dd class="text-xs text-navy-500">{{ __('A sign-in address we made for you: you can change it to your own from the dashboard.') }}</dd>@endif</div>
                @if ($owner->phone)
                    <div><dt class="text-navy-500">{{ __('or your mobile') }}</dt><dd class="font-bold"><span dir="ltr">{{ $owner->phone }}</span></dd></div>
                @endif
                <div><dt class="text-navy-500">{{ __('Temporary password') }}</dt>
                    @if ($password)
                        <dd><code class="inline-block rounded-xl border-2 border-dashed border-brand-500 bg-brand-50 px-3 py-1 text-lg font-black tracking-wider" dir="ltr" data-temp-password>{{ $password }}</code></dd>
                        <dd class="mt-1 text-xs text-navy-500">{{ __('You choose your own password at the first sign-in; this one stops working then.') }}</dd>
                    @else
                        <dd class="font-bold text-emerald-700" data-password-changed>✓ {{ __('The password was changed') }}</dd>
                    @endif
                </div>
            </dl>
        </section>
    </div>

    <section class="mt-5">
        <h2 class="text-lg font-black">{{ __('Your first 5 steps') }}</h2>
        <ol class="wk-steps mt-2 space-y-2">
            @foreach ($steps as $i => [$icon, $title, $hint, $link])
                <li class="wk-card flex items-start gap-3 rounded-2xl border border-navy-100 p-3">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-black text-white">{{ $i + 1 }}</span>
                    <div class="min-w-0">
                        <a href="{{ $link }}" class="font-bold hover:underline"><span aria-hidden="true">{{ $icon }}</span> {{ $title }}</a>
                        <p class="text-sm text-navy-500">{{ $hint }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    @if ($modules)
        <section class="mt-5">
            <h2 class="text-lg font-black">{{ __('Try the other modules') }}</h2>
            <p class="text-sm text-navy-500">{{ __('We filled them with sample data marked "(تجربة)" so you see the full picture. Clear it in one click when you start.') }}</p>
            <div class="wk-modules mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach ($modules as [$icon, $label, $link])
                    <a href="{{ $link }}" class="wk-card flex min-h-12 items-center gap-2 rounded-xl border border-navy-100 px-3 text-sm font-bold hover:border-brand-200"><span aria-hidden="true">{{ $icon }}</span> {{ $label }}</a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- everything the system can do, in one short line each (App\Support\SiteModules), ticked when the plan has it --}}
    <section class="mt-5 wk-features" data-kit-features>
        <h2 class="text-lg font-black">{{ __('Everything you can do with EgHub') }}</h2>
        <p class="text-sm text-navy-500">{{ __('✓ is in your plan now; the rest can be added any time from Subscription.') }}</p>
        <div class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
            @foreach (\App\Support\SiteModules::groups() as $groupKey => [$groupName])
                @php($groupModules = array_filter(\App\Support\SiteModules::all(), fn (array $m) => $m['group'] === $groupKey))
                @if ($groupModules)
                    <div class="wk-card">
                        <h3 class="text-sm font-black text-brand-700">{{ $groupName }}</h3>
                        <ul class="mt-1 space-y-1 text-[13px] leading-snug">
                            @foreach ($groupModules as $module)
                                @php($included = $module['feature'] === null || $rights->allows($module['feature']))
                                <li class="flex gap-1.5" data-kit-feature="{{ $module['slug'] }}" data-included="{{ $included ? '1' : '0' }}">
                                    <span class="shrink-0 {{ $included ? 'text-emerald-600' : 'text-navy-300' }}" aria-hidden="true">{{ $included ? '✓' : '＋' }}</span>
                                    <span><b>{{ $module['name'] }}</b> <span class="text-navy-500">— {{ $module['summary'] }}</span></span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </div>
    </section>

    <footer class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-navy-100 pt-4 text-sm">
        @if ($support['whatsapp'] || $support['email'])
            <div>
                <div class="font-bold">{{ __('Need help? We are here') }}</div>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-navy-600">
                    @if ($support['whatsapp'])<a href="https://wa.me/{{ whatsapp_number($support['whatsapp']) }}" data-support-whatsapp>WhatsApp <span dir="ltr">{{ $support['whatsapp'] }}</span></a>@endif
                    @if ($support['email'])<a href="mailto:{{ $support['email'] }}" dir="ltr" data-support-email>{{ $support['email'] }}</a>@endif
                </div>
            </div>
        @endif
        <p class="text-xs text-navy-500">{{ __('This page is private to you and works until :date.', ['date' => local_time($expiresAt, 'Y/m/d')]) }}</p>
    </footer>
</main>
@endsection
