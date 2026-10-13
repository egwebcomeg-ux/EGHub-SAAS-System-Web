@extends('layouts.landing')
@section('title', 'EgMenu — ' . __('Digital QR menu for restaurants and cafes'))

@php
    $trial = \App\Services\TenantProvisioner::TRIAL_DAYS;
    $cycles = $plans->first()?->cycle_discounts ?? [];
    $features = [
        ['M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z', __('A menu with your look'), __('Photos, prices, sizes and extras in Arabic and English, in your colors and logo, with ready-made designs.')],
        ['M9 5h10M9 12h10M9 19h10M4 5h.01M4 12h.01M4 19h.01', __('Ordering from the table'), __('The guest scans the table QR and orders. The order reaches your screen at once with a sound alert.')],
        ['M3 6h18v12H3zM7 10h.01M11 10h.01M15 10h.01M7 14h.01M11 14h.01', __('Digital loyalty card'), __('Stamps or points on the guest phone: “every 5 coffees, the 6th is on us”. No app and no paper cards.')],
        ['M7 7h.01M3 11V3h8l10 10-8 8L3 11z', __('Timed offers'), __('Happy hour on a category, popups on the menu, combos and “goes well with” suggestions that raise the bill.')],
        ['M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z', __('Printed menu'), __('A print-ready PDF of the same menu, so the paper menu always matches the QR menu.')],
        ['M12 3v12M7 10l5 5 5-5M4 21h16', __('Import your menu in minutes'), __('Upload an Excel sheet, or a photo of your current menu and let AI fill in the items.')],
        ['M4 20V10M10 20V4M16 20v-7M22 20H2', __('Reports and ratings'), __('Daily sales, best sellers, busy hours and what guests say after their visit.')],
        ['M3 5h18v12H3zM8 21h8M12 17v4', __('Branches and TV screens'), __('A price per branch, menu boards on the shop TVs that update themselves.')],
    ];
    $faq = [
        [__('Do I need special devices?'), __('No. The dashboard works on any phone, tablet or computer, and guests use their own phones.')],
        [__('Do my guests need to download an app?'), __('No. They scan the QR code and the menu opens in the browser.')],
        [__('Can I change prices myself?'), __('Yes, at any time from your dashboard. The QR menu, the printed menu and the TV screens all update.')],
        [__('How do I pay the subscription?'), __('By Vodafone Cash or InstaPay from your dashboard. Paying for 6 or 12 months gets a discount.')],
        [__('Is there a contract?'), __('No. The subscription is monthly and you can stop whenever you want.')],
        [__('What happens after the free trial?'), __('You get :days days of the Pro plan with no payment details. Then choose the plan that fits you, or the menu pauses until you subscribe.', ['days' => $trial])],
        [__('I have a POS or a kitchen screen already. Do I need them?'), __('No. They are optional add-ons for bigger places. Most cafes only need the menu and table ordering.')],
        [__('Is my data safe?'), __('Every restaurant’s data is kept separate and is visible only to its own staff, each with the permissions you give them.')],
    ];
@endphp

@section('page')
{{-- hero --}}
<section class="relative overflow-hidden bg-gradient-to-b from-brand-50/70 to-white">
    <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-14 lg:grid-cols-2 lg:py-20">
        <div>
            <span class="badge bg-white text-brand-700 shadow-sm">{{ __(':days days free, no card needed', ['days' => $trial]) }}</span>
            <h1 class="mt-4 text-4xl font-black leading-tight sm:text-5xl">{{ __('Digital QR menu for restaurants and cafes') }}</h1>
            <p class="mt-4 max-w-xl text-lg text-navy-600">{{ __('Your menu on the guest phone in seconds, ordering from the table and a loyalty card that brings them back. Set it up yourself in under an hour.') }}</p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="btn-primary min-h-12 px-6 text-base">{{ __('Start your :days-day free trial', ['days' => $trial]) }}</a>
                <a href="#pricing" class="btn-ghost min-h-12 px-6 text-base">{{ __('See prices') }}</a>
            </div>
            <p class="mt-4 text-sm text-navy-500">{{ __('Plans from :price EGP a month.', ['price' => money($plans->min('monthly_price') ?? 0)]) }}</p>
        </div>

        {{-- phone mock-up of a guest menu --}}
        <div class="relative mx-auto w-72" aria-hidden="true">
            <div class="absolute -inset-10 -z-10 rounded-full bg-brand-200/40 blur-3xl"></div>
            <div class="rounded-[2.5rem] border-8 border-navy-900 bg-white shadow-2xl">
                <div class="rounded-t-[2rem] bg-navy-800 px-5 pt-6 pb-5 text-white">
                    <div class="text-lg font-extrabold">{{ __('Corner Cafe') }}</div>
                    <div class="text-xs text-white/70">{{ __('Table 4') }}</div>
                </div>
                <div class="flex gap-2 overflow-hidden whitespace-nowrap px-3 py-3 text-xs font-semibold">
                    <span class="rounded-full bg-brand-600 px-3 py-1 text-white">{{ __('Hot coffee') }}</span>
                    <span class="rounded-full bg-navy-50 px-3 py-1">{{ __('Cold drinks') }}</span>
                    <span class="rounded-full bg-navy-50 px-3 py-1">{{ __('Desserts') }}</span>
                </div>
                <div class="space-y-2 px-3 pb-4">
                    @foreach ([[__('Latte'), 65, 'bg-amber-200'], [__('Cappuccino'), 65, 'bg-orange-200'], [__('Mocha'), 75, 'bg-yellow-900/30'], [__('Cheesecake'), 90, 'bg-rose-200']] as [$name, $price, $tone])
                        <div class="flex items-center gap-3 rounded-xl border border-navy-100 p-2">
                            <div class="size-12 shrink-0 rounded-lg {{ $tone }}"></div>
                            <div class="flex-1"><div class="text-sm font-bold">{{ $name }}</div><div class="text-xs font-extrabold text-brand-700">{{ $price }} {{ __('EGP') }}</div></div>
                            <span class="grid size-7 place-items-center rounded-full bg-brand-600 text-white">+</span>
                        </div>
                    @endforeach
                    <div class="mt-3 rounded-xl bg-brand-600 py-2.5 text-center text-sm font-bold text-white">{{ __('Send order · 2 items') }}</div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- how it works --}}
<section class="mx-auto max-w-7xl px-4 py-16">
    <h2 class="text-center text-3xl font-black">{{ __('Ready in three steps') }}</h2>
    <div class="mt-10 grid gap-6 md:grid-cols-3">
        @foreach ([
            [__('Add your menu'), __('Type it, upload an Excel sheet or a photo of your printed menu.')],
            [__('Print the QR codes'), __('One code per table, ready to print from your dashboard.')],
            [__('Receive orders'), __('Guests browse and order; you see every order live and get paid as usual.')],
        ] as $n => [$title, $text])
            <div class="card text-center">
                <div class="mx-auto grid size-12 place-items-center rounded-2xl bg-brand-600 text-xl font-extrabold text-white">{{ $n + 1 }}</div>
                <h3 class="mt-4 text-lg font-bold">{{ $title }}</h3>
                <p class="mt-2 text-sm text-navy-500">{{ $text }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- features --}}
<section id="features" class="scroll-mt-20 bg-navy-50/60 py-16">
    <div class="mx-auto max-w-7xl px-4">
        <h2 class="text-center text-3xl font-black">{{ __('Everything a cafe needs, nothing it does not') }}</h2>
        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($features as [$icon, $title, $text])
                <div class="card">
                    <div class="grid size-11 place-items-center rounded-xl bg-brand-50 text-brand-700">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                    </div>
                    <h3 class="mt-4 font-bold">{{ $title }}</h3>
                    <p class="mt-1 text-sm text-navy-500">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- loyalty highlight --}}
<section class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 lg:grid-cols-2">
    <div class="order-2 lg:order-1">
        <div class="mx-auto max-w-sm rounded-3xl bg-navy-800 p-6 text-white shadow-xl" aria-hidden="true">
            <div class="flex items-center justify-between"><b>{{ __('Corner Cafe') }}</b><span class="text-xs text-white/60">{{ __('Loyalty card') }}</span></div>
            <div class="mt-6 grid grid-cols-5 gap-3">
                @for ($i = 1; $i <= 10; $i++)
                    <div @class(['grid aspect-square place-items-center rounded-full border-2 text-lg', 'border-brand-500 bg-brand-600' => $i <= 6, 'border-white/30' => $i > 6])>{{ $i <= 6 ? '☕' : '' }}</div>
                @endfor
            </div>
            <div class="mt-5 text-sm text-white/80">{{ __('4 more visits for a free coffee') }}</div>
        </div>
    </div>
    <div class="order-1 lg:order-2">
        <h2 class="text-3xl font-black">{{ __('Guests who come back') }}</h2>
        <p class="mt-4 text-lg text-navy-600">{{ __('The loyalty card lives on the guest phone. The cashier adds a stamp with one tap, and the gift is redeemed on the spot. You see who your regulars are and how often they visit.') }}</p>
    </div>
</section>

{{-- pricing --}}
<section id="pricing" class="scroll-mt-20 bg-navy-50/60 py-16">
    <div class="mx-auto max-w-7xl px-4">
        <h2 class="text-center text-3xl font-black">{{ __('Simple monthly prices') }}</h2>
        <p class="mx-auto mt-3 max-w-2xl text-center text-navy-500">
            {{ __('Every plan starts with a :days-day free trial.', ['days' => $trial]) }}
            @if (!empty($cycles['6']) || !empty($cycles['12']))
                {{ __('Pay for 6 months and save :six%, or a year and save :twelve%.', ['six' => $cycles['6'] ?? 0, 'twelve' => $cycles['12'] ?? 0]) }}
            @endif
        </p>
        <div class="mt-10 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($plans as $p)
                <div @class(['card relative flex flex-col', 'ring-2 ring-brand-500' => $p->is_featured])>
                    @if ($p->is_featured)<span class="badge absolute -top-3 start-5 bg-brand-600 text-white">{{ __('Most popular') }}</span>@endif
                    <h3 class="text-xl font-extrabold">{{ $p->displayName() }}</h3>
                    <p class="mt-1 min-h-10 text-sm text-navy-500">{{ tr($p->tagline, $p->tagline_en) }}</p>
                    <div class="my-4">
                        @if ($p->original_price)<span class="me-2 text-navy-500 line-through">{{ money($p->original_price) }}</span>@endif
                        <span class="text-4xl font-extrabold">{{ money($p->monthly_price) }}</span>
                        <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span>
                    </div>
                    <div class="flex-1">@include('partials.plan-features', ['plan' => $p])</div>
                    <a href="{{ route('register') }}" class="{{ $p->is_featured ? 'btn-primary' : 'btn-ghost' }} mt-6 w-full">{{ __('Start free') }}</a>
                </div>
            @endforeach
        </div>

        @if ($addons->isNotEmpty())
            <h3 class="mt-14 text-center text-2xl font-extrabold">{{ __('Add-ons for bigger restaurants') }}</h3>
            <p class="mx-auto mt-2 mb-6 max-w-2xl text-center text-navy-500">{{ __('Start with the menu. When you grow, add only what you need, on top of any plan.') }}</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($addons as $addon)
                    <div class="card">
                        <h4 class="font-bold">{{ $addon->displayName() }}</h4>
                        <p class="mt-1 min-h-14 text-sm text-navy-500">{{ tr($addon->description, $addon->description_en) }}</p>
                        <div class="mt-3"><b class="text-xl">+{{ money($addon->monthly_price) }}</b> <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span></div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>

{{-- FAQ --}}
<section id="faq" class="mx-auto max-w-3xl scroll-mt-20 px-4 py-16">
    <h2 class="text-center text-3xl font-black">{{ __('Frequently asked questions') }}</h2>
    <div class="mt-8 divide-y divide-navy-100 rounded-2xl border border-navy-100 bg-white">
        @foreach ($faq as [$q, $a])
            <details class="group px-5 py-4">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-bold">
                    {{ $q }}
                    <span class="text-xl text-brand-600 transition group-open:rotate-45">+</span>
                </summary>
                <p class="mt-2 text-navy-600">{{ $a }}</p>
            </details>
        @endforeach
    </div>
</section>

{{-- final call to action --}}
<section class="px-4 pb-16">
    <div class="mx-auto max-w-5xl rounded-3xl bg-navy-800 px-6 py-12 text-center text-white">
        <h2 class="text-3xl font-black">{{ __('Your menu can be live today') }}</h2>
        <p class="mx-auto mt-3 max-w-xl text-white/70">{{ __('Sign up, add your items and print the codes. If you need help, we set it up with you.') }}</p>
        <a href="{{ route('register') }}" class="btn-primary mt-7 min-h-12 px-7 text-base">{{ __('Start your :days-day free trial', ['days' => $trial]) }}</a>
    </div>
</section>
@endsection
