@extends('layouts.landing')
@section('title', 'EgMenu — ' . __('one system for your whole place: QR menu, orders, cashier and delivery for restaurants and cafes'))
@section('description', 'EgMenu — ' . __('one system for your whole place') . '. ' . __('A free QR menu for cafes and restaurants in Egypt, with table and online ordering without commission, a loyalty card and a print-ready menu when you need them.'))

@push('head')
    <script type="application/ld+json">{!! \App\Services\Seo\RestaurantProfile::json(\App\Services\Seo\PlatformSchema::organization()) !!}</script>
    <script type="application/ld+json">{!! \App\Services\Seo\RestaurantProfile::json(\App\Services\Seo\PlatformSchema::website()) !!}</script>
@endpush

@php
    $trial = \App\Services\TenantProvisioner::TRIAL_DAYS;
    // the hero quotes the Complete plan's price from the database (the super admin may change it)
    $completePlan = $plans->first(fn ($p) => $p->code === \App\Services\TenantProvisioner::TRIAL_PLAN);

    // four promises, each one a feature that exists today; the ones sold only in Enterprise say so
    $promises = [
        [__('Keeps selling when the internet drops'), __('The cashier saves every sale on the device and sends it once, as soon as the connection is back.'), 'Enterprise'],
        [__('Every pound on record'), __('Sales, payment methods and busy hours in the reports; shifts, cash in and out with a reason, and a Z report on the cashier.'), null],
        [__('A menu that sells by itself'), __('Suggestions with each item, combos, best sellers at the top and offers, without anyone at the table.'), null],
        [__('The EgWeb team with you'), __('We reply the same day, and if you want, we set the menu up with you.'), null],
    ];

    // matches what is switched on: online (App\Billing\OnlinePayments) only once EgWeb turned it on
    $howToPay = app(\App\Billing\OnlinePayments::class)->howToPay();
    $faq = [
        [__('Is the free plan really free?'), __('Yes. Your menu stays online with its QR codes at no cost and with no time limit, with a small egmenu badge. You pay only if you choose the Complete plan.')],
        [__('Do my guests need to download an app?'), __('No. They scan the QR code and the menu opens in the browser.')],
        [__('Do I need special devices?'), __('No. The dashboard works on any phone, tablet or computer, and guests use their own phones.')],
        [__('What happens after the :days days of the Complete plan?', ['days' => $trial]), __('Nothing breaks. If you do not choose a plan, the account goes back to the free plan: the menu and QR codes keep working and everything you set up is kept.')],
        [__('Can I change prices myself?'), __('Yes, at any time from your dashboard. The QR menu, the printed menu and the TV screens all update.')],
        [__('How do I pay the subscription?'), $howToPay],
        [__('I have a chain or a busy kitchen. Which plan is mine?'), __('Enterprise: the POS, kitchen screens, the kiosk, the call center, stock and more than 5 branches, set up with you. Message us and we agree a price for your place.')],
        [__('I run a cafe or a shop, not a restaurant. Is EgMenu for me?'), __('Yes. When you sign up you choose a cafe, a restaurant, both, a pastry shop or bakery, or a shop (a butcher, a juice bar), and EgMenu sets up the right categories, a bar or prep screen instead of a kitchen and a loyalty card ready to switch on.')],
        [__('Is my data safe?'), __('Every restaurant’s data is kept separate and is visible only to its own staff, each with the permissions you give them.')],
    ];
@endphp

@section('page')
{{-- ============ HERO: navy, an editorial promise on the start side, the real board, the guest's phone and one live touch on the end side ============ --}}
<section data-hero class="lp-hero hm-hero">
    <div class="hm-orbits" aria-hidden="true"><i></i><i></i><i></i><b></b></div>
    <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 pt-10 pb-12 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:gap-10 lg:pt-20 lg:pb-24">
        <div>
            <p class="hm-kicker" data-reveal><span dir="ltr">EgMenu</span> — {{ __('one system for your whole place') }}</p>
            <h1 class="hm-display mt-5 text-white" data-reveal style="--d: 70ms"><span>{{ __('Know every order') }}</span> <span>{{ __('and every pound') }}</span> <span class="text-brand-500">{{ __('in your place.') }}</span></h1>
            <p class="hm-trail mt-5" data-reveal style="--d: 110ms">{{ __('For restaurants, cafés, pastry shops and bakeries — from the menu to the till to delivery') }}</p>
            <p class="hm-sub mt-6" data-reveal style="--d: 140ms">
                @if ($completePlan && (float) $completePlan->monthly_price > 0)
                    {{ __('EgMenu runs the QR menu, orders from the table and from your own link with no commission, the loyalty card and the reports on one dashboard, in Arabic and English. The menu is free with no time limit; the Complete plan is :price EGP a month.', ['price' => money($completePlan->monthly_price)]) }}
                @else
                    {{ __('EgMenu runs the QR menu, orders from the table and from your own link with no commission, the loyalty card and the reports on one dashboard, in Arabic and English. The menu is free with no time limit.') }}
                @endif
            </p>
            <div class="mt-8 flex flex-wrap items-center gap-x-7 gap-y-4" data-reveal style="--d: 210ms">
                <a href="{{ route('register') }}" class="btn-primary min-h-13 rounded-full px-8 text-base">{{ __('Start free') }} <span aria-hidden="true" class="rtl:rotate-180">→</span></a>
                <a href="#start" class="hm-link text-white"><u>{{ __('See how you start') }}</u> <span aria-hidden="true">↓</span></a>
            </div>
            <p class="hm-note mt-7" data-reveal style="--d: 280ms"><x-landing.icon name="sparkles" class="size-4 shrink-0 text-brand-500" />{{ __('No card, no contract. :days days of the Complete plan included.', ['days' => $trial]) }}</p>
        </div>

        {{-- the real order board in a browser, the guest's phone over its corner and one glass card (an example order) --}}
        <div class="hm-compose" data-reveal style="--d: 160ms">
            <x-landing.shot name="orders" url="app.egmenu.app/orders" :eager="true" :alt="__('The live order board in the EgMenu dashboard (real screen)')" sizes="(min-width: 1024px) 660px, 92vw" class="hm-compose-board" />
            <x-landing.shot name="menu-cafe" frame="phone" :eager="true" :alt="__('Rosto’s menu on a guest’s phone (real screen)')" sizes="(min-width: 1024px) 190px, 34vw" class="hm-compose-phone" />
            <div class="hm-glass hm-compose-card" aria-hidden="true">
                <span class="flex items-center justify-between gap-3 text-xs font-bold text-navy-500"><span class="flex items-center gap-1.5"><i class="hm-live"></i>{{ __('New order') }} · {{ __('Table 4') }}</span><span class="hm-tag">{{ __('Example') }}</span></span>
                <span class="mt-2 flex items-end justify-between gap-6">
                    <span><span class="block text-[11px] font-bold text-navy-400">{{ __('Total') }}</span><b class="hm-num text-2xl text-navy-900" dir="ltr">170</b> <span class="text-xs font-bold text-navy-500">{{ __('EGP') }}</span></span>
                    <span class="text-end"><span class="block text-[11px] font-bold text-navy-400">{{ __('Commission') }}</span><b class="hm-num text-2xl text-brand-700" dir="ltr">0%</b></span>
                </span>
            </div>
        </div>
    </div>
</section>

{{-- ============ LOGOS: places EgWeb put in the strip with the owner's consent (App\Services\Seo\SiteShowcase, at least MIN_LOGOS) ============ --}}
@if ($showcaseLogos)
    @php
        // one copy of the loop must be wider than the strip (8 boxes cover 1280px), so a short list is repeated in it;
        // the repeats and the second copy are hidden from screen readers and the keyboard
        $showcaseRounds = (int) ceil(8 / count($showcaseLogos));
    @endphp
    <section data-site-showcase class="pt-14" aria-labelledby="showcase-title">
        <h2 id="showcase-title" class="hm-kicker hm-kicker--quiet justify-center px-4 text-center">{{ __('Restaurants and cafes running on EgMenu') }}</h2>
        <div class="lp-marquee mx-auto mt-6 max-w-7xl" style="--lp-marquee-duration: {{ count($showcaseLogos) * $showcaseRounds * 4 }}s">
            <div class="lp-marquee-track">
                @foreach ([false, true] as $copy)
                    <ul class="lp-marquee-list" @if ($copy) aria-hidden="true" data-showcase-copy @endif>
                        @foreach (array_merge(...array_fill(0, $showcaseRounds, $showcaseLogos)) as $place)
                            @php $repeat = $copy || $loop->index >= count($showcaseLogos); @endphp
                            <li class="lp-marquee-item" @if ($repeat && ! $copy) aria-hidden="true" data-showcase-repeat @endif>
                                <a href="{{ $place['url'] }}" target="_blank" rel="noopener" class="lp-logo-box" @if ($repeat) tabindex="-1" @endif>
                                    <img src="{{ $place['logo'] }}" alt="{{ tr($place['name'], $place['name_en']) }}" width="160" height="64" loading="lazy" decoding="async">
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        </div>
    </section>
@endif

{{-- ============ WHO USES IT (only when enough listed places exist, see App\Services\Seo\SocialProof) ============ --}}
@if ($proofPlaces)
    <section data-social-proof class="pt-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <p class="hm-kicker hm-kicker--quiet justify-center text-center">{{ __('Restaurants and shops using EgMenu') }}</p>
            <ul class="mt-4 flex flex-wrap justify-center gap-2">
                @foreach ($proofPlaces as $place)
                    <li class="max-w-full">
                        <a href="{{ \App\Models\Tenant::pathUrl($place['slug']) }}" class="flex min-h-11 max-w-full items-center gap-2 rounded-full border border-navy-200 bg-white ps-1.5 pe-4 text-sm font-bold text-navy-800 hover:border-navy-600 hover:text-navy-600">
                            @if ($place['logo'])
                                <img src="{{ $place['logo'] }}" alt="" width="32" height="32" loading="lazy" class="size-8 shrink-0 rounded-full border border-navy-100 object-cover">
                            @else
                                <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-full bg-navy-900 text-xs font-black text-white">{{ mb_substr(tr($place['name'], $place['name_en']), 0, 1) }}</span>
                            @endif
                            <span class="truncate">{{ tr($place['name'], $place['name_en']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-center text-sm"><a href="{{ route('directory') }}" class="inline-flex min-h-11 items-center font-extrabold text-navy-600 hover:text-brand-700">{{ __('Browse the restaurant directory') }} <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span></a></p>
        </div>
    </section>
@endif

{{-- ============ FROM PAPER TO QR: five steps that play by themselves ============ --}}
@include('landing.partials.home-walkthrough')

{{-- ============ FOUR PROMISES, numbered under thin rules ============ --}}
<section class="pb-16 lg:pb-24" aria-labelledby="promises-title">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <h2 id="promises-title" class="sr-only">{{ __('Why EgMenu') }}</h2>
        <ol class="grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($promises as [$promiseTitle, $promiseText, $promiseTag])
                <li class="hm-promise" data-reveal style="--d: {{ $loop->index * 70 }}ms">
                    <span class="hm-num text-sm text-brand-700">{{ sprintf('%02d', $loop->iteration) }}</span>
                    <h3 class="mt-4 text-lg leading-snug font-black text-navy-900">{{ $promiseTitle }}</h3>
                    <p class="mt-2 leading-relaxed text-navy-500">{{ $promiseText }}</p>
                    @if ($promiseTag)<span class="hm-tag mt-3">{{ __('In the :plan plan', ['plan' => $promiseTag]) }}</span>@endif
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ============ ONE SYSTEM: the list on the start side, a device in orbit on the end side (pinned while it plays) ============ --}}
@include('landing.partials.home-systems')

{{-- ============ TRY IT: a live demo in the browser ============ --}}
<section id="demo" class="py-16 lg:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="max-w-2xl" data-reveal>
            <p class="hm-kicker">{{ __('Try it here') }}</p>
            <h2 class="hm-h2 mt-4">{{ __('Change a price, and the menu on the phone changes with it') }}</h2>
            <p class="lp-lead mt-4">{{ __('This is your dashboard next to a guest’s phone at table 4. Edit a price or mark an item sold out, then order from the phone and watch the order reach you.') }}</p>
        </div>
        <div class="mt-10 lg:mt-14">@include('landing.partials.live-demo')</div>
    </div>
</section>

{{-- ============ REAL NUMBERS (only above the floors in App\Services\Seo\SocialProof; never placeholders) ============ --}}
@if ($proofCounters)
    <section data-real-numbers class="pb-16 lg:pb-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="max-w-2xl" data-reveal>
                <p class="hm-kicker">{{ __('Real numbers') }}</p>
                <h2 class="hm-h2 mt-4">{{ __('What is running on EgMenu right now') }}</h2>
                <p class="mt-2 text-sm text-navy-500">{{ __('Taken from the database, refreshed every 10 minutes.') }}</p>
            </div>
            <dl class="mt-10 grid gap-x-8 gap-y-8 sm:grid-cols-3">
                @foreach ([
                    ['restaurants', __('restaurants and shops live')],
                    ['views', __('menu views')],
                    ['orders', __('orders placed')],
                ] as [$counterKey, $counterLabel])
                    <div class="hm-promise flex flex-col-reverse" data-reveal style="--d: {{ $loop->index * 70 }}ms">
                        <dt class="mt-1 text-sm font-bold text-navy-500">{{ $counterLabel }}</dt>
                        <dd class="hm-num text-5xl text-navy-900" dir="ltr" data-counter="{{ $counterKey }}">{{ \App\Services\Seo\SocialProof::compact($proofCounters[$counterKey]) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>
@endif

{{-- ============ OWNERS IN THEIR OWN WORDS (App\Models\Testimonial: real quotes with consent; hidden while none) ============ --}}
@if ($testimonials->isNotEmpty())
    <section data-testimonials class="pb-16 lg:pb-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="max-w-2xl" data-reveal>
                <p class="hm-kicker">{{ __('From our customers') }}</p>
                <h2 class="hm-h2 mt-4">{{ __('Owners in their own words') }}</h2>
            </div>
            <ul class="mt-10 flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2 [scrollbar-width:none] sm:flex-wrap sm:overflow-visible">
                @foreach ($testimonials as $t)
                    <li class="w-[85%] shrink-0 snap-center sm:w-[calc(50%-0.5rem)] lg:w-[calc(33.333%-0.75rem)]" data-reveal style="--d: {{ ($loop->index % 3) * 70 }}ms">
                        <figure class="lp-lift flex h-full flex-col rounded-[24px] border border-navy-100 bg-white p-6">
                            <div class="flex items-center justify-between gap-3">
                                <svg aria-hidden="true" viewBox="0 0 32 24" class="h-6 w-8 fill-brand-200 rtl:-scale-x-100"><path d="M0 24V14C0 6 4 1 12 0l1 4c-4 1-6 4-6 8h6v12H0Zm19 0V14c0-8 4-13 12-14l1 4c-4 1-6 4-6 8h6v12H19Z"/></svg>
                                @if ($t->rating)<div class="text-brand-600" role="img" aria-label="{{ __(':n out of 5', ['n' => $t->rating]) }}">{{ str_repeat('★', $t->rating) }}<span class="text-navy-200">{{ str_repeat('★', 5 - $t->rating) }}</span></div>@endif
                            </div>
                            <blockquote class="mt-3 flex-1 leading-relaxed text-navy-800">{{ $t->displayQuote() }}</blockquote>
                            <figcaption class="mt-5 flex items-center gap-3">
                                @if ($t->photo)
                                    <img src="/storage/{{ $t->photo }}" alt="" width="44" height="44" loading="lazy" class="size-11 shrink-0 rounded-full border border-navy-100 object-cover">
                                @else
                                    <span aria-hidden="true" class="grid size-11 shrink-0 place-items-center rounded-full bg-navy-600 font-black text-white">{{ mb_substr($t->name, 0, 1) }}</span>
                                @endif
                                <span class="min-w-0">
                                    <span class="block font-extrabold text-navy-900">{{ $t->name }}</span>
                                    @if ($t->displayRole())<span class="block truncate text-sm text-navy-500">{{ $t->displayRole() }}</span>@endif
                                    @if ($url = $t->menuUrl())<a href="{{ $url }}" class="inline-flex min-h-8 items-center text-sm font-bold text-navy-600 hover:text-brand-700">{{ __('See their menu') }} <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span></a>@endif
                                </span>
                            </figcaption>
                        </figure>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

{{-- ============ PRICING TEASER: every plan on one line, the details on /pricing ============ --}}
<section id="pricing" class="lp-band py-14 lg:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between" data-reveal>
            <div class="max-w-2xl">
                <p class="hm-kicker">{{ __('Pricing') }}</p>
                <h2 class="hm-h2 mt-4">{{ __('Start free. Pay only when you need more.') }}</h2>
            </div>
            <p class="max-w-sm text-navy-500 lg:text-end">{{ __('Clear prices in Egyptian pounds, with no commission on your orders.') }}</p>
        </div>
        {{-- three equal cards: price, what is in it, one action each (prices and limits from the database) --}}
        <ul class="mt-10 grid items-stretch gap-4 lg:grid-cols-3" data-home-plans>
            @foreach ($plans as $p)
                @php
                    $isContact = $p->isContactSales();
                    $isFree = ! $isContact && (float) $p->monthly_price === 0.0;
                    $limits = $p->entitlements()['limits'] ?? [];
                    $points = $isContact
                        ? [__('Everything in Complete'), __('POS, kitchen and bar screens, kiosk'), __('Call center and inventory'), __('More than 5 branches, setup and training')]
                        : ($isFree
                            ? [__('QR menu in Arabic and English'), __('Up to :n items', ['n' => $limits['menu_items'] ?? 30]), __('Import the menu from Excel'), __('No card, no time limit')]
                            : [__('One branch and :n users', ['n' => $limits['staff'] ?? 8]), __('Table and online ordering, delivery'), __('Loyalty card, wallet, offers, campaigns'), __('Reports and your own domain')]);
                @endphp
                <li @class(['lp-lift relative flex flex-col rounded-[24px] border p-6',
                            'border-navy-800 bg-navy-800 text-white shadow-[0_30px_60px_-30px_rgba(19, 29, 61, .7)] hm-plan-dark' => $p->is_featured,
                            'border-navy-100 bg-white' => ! $p->is_featured])
                    data-home-plan="{{ $p->code }}" data-reveal style="--d: {{ $loop->index * 60 }}ms">
                    @if ($p->is_featured)
                        <span class="absolute -top-3 start-6 rounded-full bg-brand-600 px-3 py-1 text-xs font-black text-white">{{ __('Most popular') }}</span>
                    @endif
                    <b class="text-xl font-black text-navy-900">{{ $p->displayName() }}</b>
                    <span class="mt-0.5 text-sm text-navy-500">{{ $isFree ? __('Free forever, no card') : ($isContact ? __('For chains and busy kitchens') : __('Everything a cafe or restaurant runs on')) }}</span>
                    <div class="mt-4 min-h-[4.25rem]">
                        @if ($isContact)
                            <b class="text-3xl font-black text-navy-900">{{ __('By contact') }}</b>
                            <span class="block text-xs font-semibold text-navy-500">{{ __('Priced for your branches and screens') }}</span>
                        @else
                            @if ($p->showsLaunchDiscount())
                                <span class="me-1 text-base font-bold text-navy-400 line-through" dir="ltr">{{ money($p->original_price) }}</span>
                            @endif
                            <b class="text-4xl font-black tracking-tight text-navy-900" dir="ltr">{{ money($p->monthly_price) }}</b>
                            <span class="text-sm font-semibold text-navy-500">{{ __('EGP / month') }}</span>
                            @if ($p->showsLaunchDiscount())
                                <span class="mt-1 block w-fit rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-black text-emerald-800">{{ __(':pct% off for the launch period', ['pct' => (int) round((1 - $p->monthly_price / $p->original_price) * 100)]) }}</span>
                                @if ($offerEnds = \App\Models\Plan::launchOfferEndsAt())
                                    <span class="mt-1 block text-xs font-bold text-navy-600" data-offer-ends>{{ __('Offer valid until :date', ['date' => $offerEnds->format('d/m/Y')]) }}</span>
                                @endif
                            @endif
                            @if ((float) $p->monthly_price > 0 && $p->priceFor(12) < $p->monthly_price * 12)
                                <span class="block text-xs font-bold text-emerald-700">{{ __('or :total EGP a year', ['total' => money($p->priceFor(12))]) }}</span>
                            @endif
                        @endif
                    </div>
                    <ul class="mt-4 flex-1 space-y-2 border-t border-navy-100 pt-4 text-sm text-navy-700">
                        @foreach ($points as $point)
                            <li class="flex items-start gap-2"><x-landing.icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-600" />{{ $point }}</li>
                        @endforeach
                    </ul>
                    @if ($isContact)
                        <a href="{{ $p->contactUrl() }}" target="_blank" rel="noopener" class="btn-ghost mt-6 min-h-12 w-full rounded-full" data-contact-sales-link>{{ __('Contact us') }}</a>
                    @elseif ($isFree)
                        <a href="{{ route('register') }}" class="btn-ghost mt-6 min-h-12 w-full rounded-full">{{ __('Start free') }}</a>
                    @else
                        <a href="{{ route('register') }}" class="btn-primary mt-6 min-h-12 w-full rounded-full">{{ __('Try it free for 14 days') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="mt-8 flex flex-wrap justify-center gap-x-6 gap-y-2" data-reveal>
            <a href="{{ route('pricing') }}" class="hm-link text-navy-800"><u>{{ __('Compare the plans in detail') }}</u> <span aria-hidden="true" class="rtl:rotate-180">→</span></a>
            <a href="{{ route('pricing') }}#calculator" class="hm-link text-navy-800"><u>{{ __('Work out what you save') }}</u> <span aria-hidden="true" class="rtl:rotate-180">→</span></a>
        </div>
        <p class="mt-4 text-center text-sm text-navy-500">{{ $howToPay }}</p>
    </div>
</section>

{{-- ============ GUESTS PAY ONLINE THROUGH THE RESTAURANT'S OWN GATEWAY (a service, App\Support\SiteServices payment_gateway) ============ --}}
<section data-payment-gateways class="pt-16 lg:pt-24">
    <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="hm-promise flex flex-col gap-6 lg:flex-row lg:items-center" data-reveal>
            <div class="min-w-0 flex-1">
                <h2 class="text-xl font-black text-navy-900 sm:text-2xl">{{ __('Guests pay online, through your own gateway') }}</h2>
                <p class="mt-2 max-w-2xl text-navy-500">{{ __('Have a contract with a payment company? We connect it to your menu and online orders, and the money goes straight to your account with no commission from us.') }}</p>
                <ul class="mt-5 flex flex-wrap gap-2" aria-label="{{ __('Payment gateways we connect') }}">
                    @foreach (['fawry' => 'Fawry', 'kashier' => 'Kashier', 'paymob' => 'Paymob', 'geidea' => 'Geidea'] as $gatewayFile => $gateway)
                        <li class="grid h-14 w-28 place-items-center rounded-2xl border border-navy-100 bg-white px-3 sm:w-32" data-gateway="{{ $gatewayFile }}">
                            <img src="{{ versioned_asset('img/partners/' . $gatewayFile . '.webp') }}" alt="{{ $gateway }}" width="120" height="40" loading="lazy" decoding="async" class="max-h-8 w-auto max-w-full object-contain">
                        </li>
                    @endforeach
                    <li class="grid h-14 place-items-center rounded-2xl border border-dashed border-navy-300 px-4 text-sm font-bold text-navy-500">{{ __('or any gateway with an API') }}</li>
                </ul>
            </div>
            <a href="{{ route('services', ['service' => 'payment_gateway']) }}#request" class="btn-ghost min-h-12 shrink-0 self-start rounded-full px-6 lg:self-center">{{ __('Ask for the connection') }}</a>
        </div>
    </div>
</section>

{{-- ============ FAQ: the title stays on the start side, the questions on the end side ============ --}}
<section id="faq" class="py-16 lg:py-24">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 sm:px-6 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-16">
        <div data-reveal class="lg:sticky lg:top-28 lg:self-start">
            <p class="hm-kicker">{{ __('FAQ') }}</p>
            <h2 class="hm-h2 mt-4">{{ __('Frequently asked questions') }}</h2>
            <p class="mt-3 text-navy-500">{{ __('Still have a question? Ask us and we reply the same day.') }}</p>
        </div>
        <div>
            <div id="faq-list" data-more class="border-t border-navy-100">
                @foreach ($faq as [$q, $a])
                    <details name="faq" class="lp-faq group border-b border-navy-100" @if ($loop->first) open @endif @if ($loop->index >= 5) data-more-item @endif>
                        <summary class="flex min-h-15 cursor-pointer list-none items-center justify-between gap-4 py-5 text-[1.05rem] font-extrabold text-navy-900">
                            {{ $q }}
                            <span class="lp-faq-icon grid size-8 shrink-0 place-items-center rounded-full border border-navy-200 bg-white text-lg leading-none text-navy-600">+</span>
                        </summary>
                        <p class="pb-6 leading-relaxed text-navy-500">{{ $a }}</p>
                    </details>
                @endforeach
            </div>
            <x-landing.more for="faq-list" />
        </div>
    </div>
</section>

{{-- ============ FINAL CTA: navy, in orbit ============ --}}
<section class="hm-final mb-8">
    <div class="hm-orbits hm-orbits--center" aria-hidden="true"><i></i><i></i><i></i><b></b></div>
    <div class="relative mx-auto max-w-3xl px-4 py-20 text-center sm:px-6 lg:py-28" data-reveal>
        <x-logo :size="44" :text="false" />
        <p class="hm-kicker mt-6 justify-center"><span dir="ltr">EgMenu</span> — {{ __('one system for your whole place') }}</p>
        <h2 class="hm-display hm-display--sm mt-4 text-white">{{ __('Your menu can be live today') }}</h2>
        <p class="hm-sub mx-auto mt-5 max-w-xl">{{ __('Sign up with your email or your Google account, add your items and print the codes. If you need help, we set it up with you.') }}</p>
        <div class="mt-9 flex flex-wrap items-center justify-center gap-x-7 gap-y-4">
            <a href="{{ route('register') }}" class="btn-primary min-h-13 rounded-full px-8 text-base">{{ __('Start free') }} <span aria-hidden="true" class="rtl:rotate-180">→</span></a>
            <a href="{{ route('pricing') }}" class="hm-link text-white"><u>{{ __('See prices') }}</u> <span aria-hidden="true" class="rtl:rotate-180">→</span></a>
        </div>
    </div>
</section>
@endsection
