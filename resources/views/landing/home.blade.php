@extends('layouts.landing')
@section('title', 'EgMenu — ' . __('A free QR menu for cafes and restaurants in Egypt'))

@php
    $trial = \App\Services\TenantProvisioner::TRIAL_DAYS;
    $modules = \App\Support\SiteModules::all();
    $pricing = ['plans' => $plans, 'addons' => $addons, 'matrix' => $matrix];
    $themesCount = count(\App\Support\MenuThemes::ALL);
    $free = $plans->first(fn ($p) => (float) $p->monthly_price === 0.0);

    $paths = [
        'cafe' => ['cup', __('For a cafe like Rosto'), __('Quick orders, regulars who come back, and a bar screen at rush hour.'), ['qr-menu', 'table-ordering', 'loyalty', 'kiosk', 'kitchen-display', 'pos']],
        'restaurant' => ['plate', __('For a restaurant like Sofra'), __('Accurate orders to the kitchen, delivery without commission, and costs under control.'), ['table-ordering', 'online-ordering', 'reservations', 'kitchen-display', 'call-center', 'inventory']],
    ];
    $showcase = ['qr-menu', 'table-ordering', 'online-ordering', 'loyalty', 'reservations', 'whatsapp', 'pos', 'kiosk'];

    $faq = [
        [__('Is the free plan really free?'), __('Yes. Your menu stays online with its QR codes at no cost and with no time limit, with a small egmenu badge. You pay only if you choose a paid plan.')],
        [__('Do my guests need to download an app?'), __('No. They scan the QR code and the menu opens in the browser.')],
        [__('Do I need special devices?'), __('No. The dashboard works on any phone, tablet or computer, and guests use their own phones.')],
        [__('What happens after the :days days of Pro?', ['days' => $trial]), __('Nothing breaks. If you do not choose a plan, the account goes back to the free plan: the menu and QR codes keep working and everything you set up is kept.')],
        [__('Can I change prices myself?'), __('Yes, at any time from your dashboard. The QR menu, the printed menu and the TV screens all update.')],
        [__('How do I pay the subscription?'), __('By Vodafone Cash or InstaPay from your dashboard. Paying for 6 or 12 months gets a discount.')],
        [__('I run a cafe, not a restaurant. Is EgMenu for me?'), __('Yes. When you sign up you choose cafe, restaurant or both, and EgMenu sets up the right categories, a bar screen instead of a kitchen and a stamp card ready to switch on.')],
        [__('Is my data safe?'), __('Every restaurant’s data is kept separate and is visible only to its own staff, each with the permissions you give them.')],
    ];
@endphp

@section('page')
{{-- ============ HERO ============ --}}
<section data-hero class="relative overflow-hidden border-b border-navy-100 bg-[var(--lp-paper)]">
    <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 pt-10 pb-14 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-12 lg:pt-16 lg:pb-20">
        <div>
            <p class="inline-flex items-center gap-2 rounded-full bg-emerald-600/10 px-3 py-1.5 text-sm font-bold text-emerald-800">
                <x-landing.icon name="check" class="size-4" />{{ __('Free forever for your menu') }}
            </p>
            <h1 class="mt-5 text-[2.35rem] leading-[1.22] font-black tracking-tight text-navy-900 sm:text-[3.4rem]">{{ __('Your menu online today, for free.') }}</h1>
            <p class="lp-lead mt-5 max-w-xl text-lg">{{ __('A QR menu for your cafe or restaurant that costs nothing. When you are ready, add table ordering, online orders with no commission and a loyalty card.') }}</p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="btn-primary min-h-13 px-7 text-base">{{ __('Start free') }}</a>
                <a href="{{ route('features') }}" class="btn-ghost min-h-13 px-6 text-base">{{ __('See the features') }}</a>
            </div>
            <p class="mt-4 text-sm text-navy-500">{{ __('No card, no contract. :days days of Pro included.', ['days' => $trial]) }}</p>
        </div>

        <div class="relative">
            {{-- desktop: the real order board, the guest's phone and one live touch --}}
            <div class="relative hidden pb-10 sm:block">
                <x-landing.shot name="orders" url="app.egmenu.app/orders" :eager="true" :alt="__('The live order board in the EgMenu dashboard (real screen)')" sizes="(min-width: 1024px) 700px, 90vw" />
                <x-landing.shot name="menu-cafe" frame="phone" :eager="true" :alt="__('Rosto’s menu on a guest’s phone (real screen)')" sizes="190px" class="absolute -bottom-2 -start-6 w-[160px] lg:-start-10 lg:w-[190px]" />
                <div class="lp-ping absolute top-14 end-4 flex items-center gap-2.5 rounded-xl bg-white px-3 py-2 text-sm font-bold shadow-[0_12px_30px_-12px_rgba(20,31,56,.45)] ring-1 ring-navy-100" aria-hidden="true">
                    <span class="lp-bell grid size-8 place-items-center rounded-lg bg-brand-600 text-white"><x-landing.icon name="bell" class="size-4" /></span>
                    <span>{{ __('New order') }}<span class="block text-xs font-semibold text-navy-500">{{ __('Table 4') }} · 170 {{ __('EGP') }}</span></span>
                </div>
            </div>
            {{-- phone: just the menu, so the first screen stays calm --}}
            <div class="mx-auto w-[230px] sm:hidden">
                <x-landing.shot name="menu-cafe" frame="phone" :eager="true" :alt="__('Rosto’s menu on a guest’s phone (real screen)')" sizes="230px" />
            </div>
        </div>
    </div>
</section>

{{-- ============ HONEST FACTS ============ --}}
<section class="border-b border-navy-100">
    <ul class="mx-auto grid max-w-7xl grid-cols-2 gap-px bg-navy-100 text-sm sm:grid-cols-3 lg:grid-cols-5">
        @foreach ([
            [__('0 EGP'), __('for your menu, with no time limit')],
            [__('0%'), __('commission on your orders')],
            [$themesCount, __('menu designs')],
            [__('Arabic + English'), __('on the menu and the dashboard')],
            [__('InstaPay'), __('or Vodafone Cash for paid plans')],
        ] as $i => [$big, $small])
            <li @class(['bg-white px-4 py-5 sm:px-6', 'col-span-2 sm:col-span-1' => $i === 4])>
                <b class="block text-xl font-black text-navy-900">{{ $big }}</b>
                <span class="text-navy-500">{{ $small }}</span>
            </li>
        @endforeach
    </ul>
</section>

{{-- ============ PICK YOUR PATH ============ --}}
<section class="py-16 lg:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <p class="lp-eyebrow">{{ __('Built for how you work') }}</p>
        <h2 class="lp-h2 mt-2 max-w-2xl">{{ __('A cafe and a restaurant need different things') }}</h2>
        <div class="mt-10 grid gap-5 lg:grid-cols-2">
            @foreach ($paths as $type => [$icon, $title, $lead, $slugs])
                <div class="rounded-3xl border border-navy-200 p-6 sm:p-8" data-reveal style="--d: {{ $loop->index * 80 }}ms">
                    <div class="flex items-center gap-3">
                        <span class="grid size-12 place-items-center rounded-2xl {{ $type === 'cafe' ? 'bg-[#4a3426]' : 'bg-navy-900' }} text-white"><x-landing.icon :name="$icon" class="size-6" /></span>
                        <h3 class="text-xl font-black">{{ $title }}</h3>
                    </div>
                    <p class="mt-4 text-navy-600">{{ $lead }}</p>
                    <ul class="mt-6 grid gap-x-4 sm:grid-cols-2">
                        @foreach ($slugs as $slug)
                            <li class="border-t border-navy-100">
                                <a href="{{ route('features.show', $slug) }}" class="group flex min-h-12 items-center gap-3 font-bold text-navy-800 hover:text-brand-700">
                                    <x-landing.icon :name="$modules[$slug]['icon']" class="size-5 text-navy-400 group-hover:text-brand-600" />{{ $modules[$slug]['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ WORKFLOW ============ --}}
<section id="how" class="border-y border-navy-100 bg-[var(--lp-paper)] py-16 lg:py-24">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:items-center lg:gap-14">
        <div>
            <p class="lp-eyebrow">{{ __('How an order moves') }}</p>
            <h2 class="lp-h2 mt-2">{{ $modules['table-ordering']['tagline'] }}</h2>
            <p class="lp-lead mt-4">{{ __('No waiting for a waiter and no wrong orders. Each order shows the table, the items with their options and notes, and moves from new to ready with one tap.') }}</p>
            <a href="{{ route('features.show', 'table-ordering') }}" class="mt-6 inline-flex min-h-11 items-center font-extrabold text-brand-700 hover:text-brand-600">{{ __('More about table ordering') }} <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span></a>
        </div>
        <x-landing.scene :steps="$modules['table-ordering']['steps']" :label="$modules['table-ordering']['name']" class="sm:rounded-3xl sm:bg-white sm:p-4 sm:ring-1 sm:ring-navy-100">
            @include('landing.scenes.table-ordering')
        </x-landing.scene>
    </div>
</section>

{{-- ============ MODULES ============ --}}
<section class="py-16 lg:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <p class="lp-eyebrow">{{ __('Features') }}</p>
                <h2 class="lp-h2 mt-2 max-w-2xl">{{ __('Take what you need, one module at a time') }}</h2>
            </div>
            <a href="{{ route('features') }}" class="inline-flex min-h-11 items-center font-extrabold text-brand-700 hover:text-brand-600">{{ __('All :n features', ['n' => count($modules)]) }} <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span></a>
        </div>
        <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($showcase as $slug)
                <x-landing.module-card :m="$modules[$slug]" :note="\App\Support\SiteModules::where($modules[$slug], $pricing)" data-reveal style="--d: {{ ($loop->index % 4) * 60 }}ms" />
            @endforeach
        </div>
    </div>
</section>

{{-- ============ PRINTED MENU ============ --}}
<section class="border-y border-navy-100 bg-navy-900 py-16 text-white lg:py-24">
    <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16">
        <div>
            <p class="lp-eyebrow text-brand-500!">{{ __('Printed menu') }}</p>
            <h2 class="mt-2 text-[clamp(1.65rem,1.2rem+1.6vw,2.5rem)] leading-[1.3] font-black">{{ __('A paper menu from the same items, without a designer') }}</h2>
            <p class="mt-4 text-lg leading-relaxed text-white/75">{{ __('Prices went up? Change them once. The QR menu updates at once, and the print-ready A4 menu is one click away.') }}</p>
            <ul class="mt-6 space-y-3 text-white/90">
                @foreach ([__('Arabic, English or both side by side'), __('Your logo, colours and a QR code to the online menu'), __('Print it or save it as a PDF for the print shop')] as $point)
                    <li class="flex items-start gap-3"><x-landing.icon name="check" class="mt-1 size-5 shrink-0 text-brand-500" />{{ $point }}</li>
                @endforeach
            </ul>
            <a href="{{ route('features.show', 'printed-menu') }}" class="btn mt-8 min-h-12 border border-white/25 px-6 text-white hover:bg-white/10">{{ __('See how it works') }}</a>
        </div>
        <x-landing.shot name="print" url="app.egmenu.app/menu/print" :alt="__('The printed menu preview in the dashboard (real screen)')" data-reveal />
    </div>
</section>

{{-- ============ PRICING ============ --}}
<section id="pricing" class="py-16 lg:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <p class="lp-eyebrow">{{ __('Pricing') }}</p>
        <h2 class="lp-h2 mt-2 max-w-2xl">{{ __('Start free. Pay only when you need more.') }}</h2>
        <p class="lp-lead mt-3 max-w-2xl">{{ __('Clear prices in Egyptian pounds, with no commission on your orders.') }}</p>
        <div class="mt-10">@include('landing.partials.plans')</div>
        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-navy-200 p-5">
            <p class="text-navy-600">{{ __('POS, the self-order kiosk, kitchen display, call center and inventory are add-ons you switch on when you need them; WhatsApp credits are paid once.') }}</p>
            <a href="{{ route('pricing') }}#compare" class="btn-ghost min-h-11">{{ __('Compare all plans and add-ons') }}</a>
        </div>
    </div>
</section>

{{-- ============ FAQ ============ --}}
<section id="faq" class="border-t border-navy-100 bg-[var(--lp-paper)] py-16 lg:py-24">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[1fr_2fr]">
        <div>
            <p class="lp-eyebrow">{{ __('FAQ') }}</p>
            <h2 class="lp-h2 mt-2">{{ __('Frequently asked questions') }}</h2>
            <p class="mt-3 text-navy-600">{{ __('Still have a question? Ask us and we reply the same day.') }}</p>
        </div>
        <div class="divide-y divide-navy-200 border-y border-navy-200">
            @foreach ($faq as [$q, $a])
                <details name="faq" class="lp-faq group" @if ($loop->first) open @endif>
                    <summary class="flex min-h-15 cursor-pointer list-none items-center justify-between gap-4 py-4 text-[1.05rem] font-extrabold">
                        {{ $q }}
                        <span class="lp-faq-icon grid size-8 shrink-0 place-items-center rounded-full border border-navy-300 text-lg leading-none text-navy-700">+</span>
                    </summary>
                    <p class="pb-5 leading-relaxed text-navy-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ FINAL CTA ============ --}}
<section class="py-16 lg:py-24">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6">
        <x-logo :size="44" :text="false" />
        <h2 class="lp-h2 mt-4">{{ __('Your menu can be live today') }}</h2>
        <p class="lp-lead mt-3">{{ __('Sign up, add your items and print the codes. If you need help, we set it up with you.') }}</p>
        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('register') }}" class="btn-primary min-h-13 px-8 text-base">{{ __('Start free') }}</a>
            <a href="{{ route('pricing') }}" class="btn-ghost min-h-13 px-6 text-base">{{ __('See prices') }}</a>
        </div>
    </div>
</section>
@endsection
