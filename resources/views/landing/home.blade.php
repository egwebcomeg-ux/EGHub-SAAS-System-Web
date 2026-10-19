@extends('layouts.landing')
@section('title', 'EgMenu — ' . __('A free QR menu for cafes and restaurants in Egypt'))

@php
    $trial = \App\Services\TenantProvisioner::TRIAL_DAYS;
    $modules = \App\Support\SiteModules::all();
    $groups = \App\Support\SiteModules::groups();
    $themesCount = count(\App\Support\MenuThemes::ALL);

    // one idea per card: the menu, ordering, loyalty, operations (each links to its group on /features)
    $ideas = [
        'menu' => __('Put the menu online in your guests’ languages, on paper and on the TVs, and keep every copy up to date from one place.'),
        'orders' => __('Orders from the table and from your own link with no commission, and table bookings, on one live board.'),
        'customers' => __('Bring guests back with a stamp card, offers, WhatsApp messages and a prepaid balance.'),
        'operations' => __('For busier places and chains: a cashier, a self-order tablet, kitchen screens and stock, in the Enterprise plan set up with you.'),
    ];

    $paths = [
        'cafe' => ['cup', __('For a cafe like Rosto'), __('Quick orders, regulars who come back, and a bar screen at rush hour.'), ['qr-menu', 'table-ordering', 'loyalty', 'kiosk', 'kitchen-display', 'pos']],
        'restaurant' => ['plate', __('For a restaurant like Sofra'), __('Accurate orders to the kitchen, delivery without commission, and costs under control.'), ['table-ordering', 'online-ordering', 'reservations', 'kitchen-display', 'call-center', 'inventory']],
    ];

    $faq = [
        [__('Is the free plan really free?'), __('Yes. Your menu stays online with its QR codes at no cost and with no time limit, with a small egmenu badge. You pay only if you choose the Complete plan.')],
        [__('Do my guests need to download an app?'), __('No. They scan the QR code and the menu opens in the browser.')],
        [__('Do I need special devices?'), __('No. The dashboard works on any phone, tablet or computer, and guests use their own phones.')],
        [__('What happens after the :days days of the Complete plan?', ['days' => $trial]), __('Nothing breaks. If you do not choose a plan, the account goes back to the free plan: the menu and QR codes keep working and everything you set up is kept.')],
        [__('Can I change prices myself?'), __('Yes, at any time from your dashboard. The QR menu, the printed menu and the TV screens all update.')],
        [__('How do I pay the subscription?'), __('By Vodafone Cash or InstaPay from your dashboard. Paying for a whole year at once gets a discount.')],
        [__('I have a chain or a busy kitchen. Which plan is mine?'), __('Enterprise: the POS, kitchen screens, the kiosk, the call center, stock and more than 5 branches, set up with you. Message us and we agree a price for your place.')],
        [__('I run a cafe or a shop, not a restaurant. Is EgMenu for me?'), __('Yes. When you sign up you choose cafe, restaurant, both or a shop (a bakery, a butcher, a juice bar), and EgMenu sets up the right categories, a bar or prep screen instead of a kitchen and a loyalty card ready to switch on.')],
        [__('Is my data safe?'), __('Every restaurant’s data is kept separate and is visible only to its own staff, each with the permissions you give them.')],
    ];
@endphp

@section('page')
{{-- ============ HERO: tinted container, centered on phones, the real board beside it on wide screens ============ --}}
<section data-hero class="lp-hero lp-glow">
    <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 pt-10 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-12 lg:pt-16 lg:pb-20">
        <div class="text-center lg:text-start">
            <p class="lp-eyebrow" data-reveal><x-landing.icon name="check" class="size-4" />{{ __('Free forever for your menu') }}</p>
            <h1 class="mt-5 text-[2.2rem] leading-[1.25] font-black tracking-tight text-navy-900 sm:text-[3.4rem] sm:leading-[1.22]" data-reveal style="--d: 70ms">{{ __('Your menu online today,') }} <span class="text-brand-gradient">{{ __('for free.') }}</span></h1>
            <p class="lp-lead mx-auto mt-4 max-w-md sm:text-lg lg:mx-0 lg:max-w-xl" data-reveal style="--d: 140ms">{{ __('A free QR menu for your cafe or restaurant. Add table ordering, online orders and a loyalty card when you are ready.') }}</p>
            <div class="mt-7 flex flex-wrap justify-center gap-3 lg:justify-start" data-reveal style="--d: 210ms">
                <a href="{{ route('register') }}" class="btn-primary min-h-12 rounded-full px-7 text-base">{{ __('Start free') }}</a>
                <a href="{{ route('features') }}" class="btn-ghost min-h-12 rounded-full px-6 text-base">{{ __('See the features') }}</a>
            </div>
            <p class="mt-4 text-sm text-navy-500" data-reveal style="--d: 280ms">{{ __('No card, no contract. :days days of the Complete plan included.', ['days' => $trial]) }}</p>
        </div>

        <div class="relative">
            {{-- wide screens: the real order board, the guest's phone and one live touch --}}
            <div class="relative hidden pb-10 sm:block">
                <x-landing.shot name="orders" url="app.egmenu.app/orders" :eager="true" :alt="__('The live order board in the EgMenu dashboard (real screen)')" sizes="(min-width: 1024px) 700px, 90vw" />
                <x-landing.shot name="menu-cafe" frame="phone" :eager="true" :alt="__('Rosto’s menu on a guest’s phone (real screen)')" sizes="190px" class="absolute -bottom-2 -start-6 w-[160px] lg:-start-10 lg:w-[190px]" />
                <div class="lp-ping absolute top-14 end-4 flex items-center gap-2.5 rounded-xl bg-white px-3 py-2 text-sm font-bold shadow-[0_12px_30px_-12px_rgba(20,31,56,.45)] ring-1 ring-navy-100" aria-hidden="true">
                    <span class="lp-bell grid size-8 place-items-center rounded-lg bg-brand-600 text-white"><x-landing.icon name="bell" class="size-4" /></span>
                    <span>{{ __('New order') }}<span class="block text-xs font-semibold text-navy-500">{{ __('Table 4') }} · 170 {{ __('EGP') }}</span></span>
                </div>
            </div>
            {{-- phones: one product image, its lower half tucked into the container's edge --}}
            <div class="mx-auto h-[300px] w-[220px] overflow-hidden sm:hidden">
                <x-landing.shot name="menu-cafe" frame="phone" :eager="true" :alt="__('Rosto’s menu on a guest’s phone (real screen)')" sizes="220px" />
            </div>
        </div>
    </div>
</section>

{{-- ============ HONEST FACTS: four calm tiles ============ --}}
<section class="mx-auto max-w-7xl px-4 pt-8 sm:px-6">
    <ul class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            [__('0 EGP'), __('for your menu, with no time limit')],
            [__('0%'), __('commission on your orders')],
            [$themesCount, __('menu designs')],
            [__('Arabic + English'), __('on the menu and the dashboard')],
        ] as [$big, $small])
            <li class="rounded-2xl border border-navy-100 bg-white px-3 py-4 text-center sm:px-5" data-reveal style="--d: {{ $loop->index * 70 }}ms">
                <b class="block text-lg font-black text-navy-900 sm:text-xl">{{ $big }}</b>
                <span class="block text-sm leading-snug text-navy-500">{{ $small }}</span>
            </li>
        @endforeach
    </ul>
</section>

{{-- ============ WHO USES IT (only when enough listed places exist, see App\Services\Seo\SocialProof) ============ --}}
@if ($proofPlaces)
    <section data-social-proof class="pt-14">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <p class="text-center text-sm font-extrabold text-navy-500">{{ __('Restaurants and shops using EgMenu') }}</p>
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

{{-- ============ ONE IDEA PER CARD ============ --}}
<section class="py-20 lg:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <p class="lp-eyebrow">{{ __('Features') }}</p>
            <h2 class="lp-h2 mt-3">{{ __('Take what you need, one module at a time') }}</h2>
        </div>
        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:mt-14 lg:grid-cols-4 lg:gap-5">
            @foreach ($ideas as $g => $text)
                <div class="lp-idea lp-lift" data-reveal style="--d: {{ $loop->index * 70 }}ms">
                    <span class="lp-idea-icon"><x-landing.icon :name="$groups[$g][1]" class="size-6" /></span>
                    <h3 class="mt-5 text-xl font-black text-navy-900">{{ $groups[$g][0] }}</h3>
                    <p class="mt-2 flex-1 leading-relaxed text-navy-600">{{ $text }}</p>
                    <a href="{{ route('features') }}#{{ $g }}" class="btn-ghost mt-6 min-h-11 rounded-full px-5">{{ __('Learn more') }}</a>
                </div>
            @endforeach
        </div>
        <p class="mt-8 text-center"><a href="{{ route('features') }}" class="inline-flex min-h-11 items-center font-extrabold text-navy-600 hover:text-brand-700">{{ __('All :n features', ['n' => count($modules)]) }} <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span></a></p>
    </div>
</section>

{{-- ============ TRY IT: a live demo in the browser ============ --}}
<section id="demo" class="lp-band py-16 lg:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <p class="lp-eyebrow">{{ __('Try it here') }}</p>
            <h2 class="lp-h2 mt-3">{{ __('Change a price, and the menu on the phone changes with it') }}</h2>
            <p class="lp-lead mt-3">{{ __('This is your dashboard next to a guest’s phone at table 4. Edit a price or mark an item sold out, then order from the phone and watch the order reach you.') }}</p>
        </div>
        <div class="mt-10">@include('landing.partials.live-demo')</div>
    </div>
</section>

{{-- ============ CAFE OR RESTAURANT ============ --}}
<section class="py-20 lg:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <p class="lp-eyebrow">{{ __('Built for how you work') }}</p>
            <h2 class="lp-h2 mt-3">{{ __('A cafe and a restaurant need different things') }}</h2>
        </div>
        <div class="mt-10 grid gap-4 lg:mt-14 lg:grid-cols-2 lg:gap-5">
            @foreach ($paths as $type => [$icon, $title, $lead, $slugs])
                <div class="lp-lift rounded-[28px] border border-navy-100 bg-[var(--lp-paper)] p-6 sm:p-8" data-reveal style="--d: {{ $loop->index * 80 }}ms">
                    <div class="flex items-center gap-3">
                        <span class="lp-idea-icon size-12! text-navy-600!"><x-landing.icon :name="$icon" class="size-6" /></span>
                        <h3 class="text-xl font-black">{{ $title }}</h3>
                    </div>
                    <p class="mt-4 leading-relaxed text-navy-600">{{ $lead }}</p>
                    <ul id="path-{{ $type }}" data-more class="mt-5 grid gap-x-4 rounded-2xl bg-white px-4 sm:grid-cols-2">
                        @foreach ($slugs as $slug)
                            <li @class(['border-t border-navy-100 first:border-t-0 sm:[&:nth-child(2)]:border-t-0']) @if ($loop->index >= 3) data-more-item @endif>
                                <a href="{{ route('features.show', $slug) }}" class="group flex min-h-12 items-center gap-3 font-bold text-navy-800 hover:text-navy-600">
                                    <x-landing.icon :name="$modules[$slug]['icon']" class="size-5 text-navy-400 group-hover:text-brand-600" />{{ $modules[$slug]['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <x-landing.more :for="'path-' . $type" class="mt-4 justify-center" />
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ REAL NUMBERS (only above the floors in App\Services\Seo\SocialProof; never placeholders) ============ --}}
@if ($proofCounters)
    <section data-real-numbers class="pb-20 lg:pb-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center" data-reveal>
                <p class="lp-eyebrow">{{ __('Real numbers') }}</p>
                <h2 class="lp-h2 mt-3">{{ __('What is running on EgMenu right now') }}</h2>
                <p class="mt-2 text-sm text-navy-500">{{ __('Taken from the database, refreshed every 10 minutes.') }}</p>
            </div>
            <dl class="mt-10 grid gap-3 sm:grid-cols-3">
                @foreach ([
                    ['restaurants', __('restaurants and shops live')],
                    ['views', __('menu views')],
                    ['orders', __('orders placed')],
                ] as [$counterKey, $counterLabel])
                    <div class="flex flex-col-reverse rounded-[24px] border border-navy-100 bg-white px-6 py-7 text-center" data-reveal style="--d: {{ $loop->index * 70 }}ms">
                        <dt class="mt-1 text-sm font-bold text-navy-500">{{ $counterLabel }}</dt>
                        <dd class="text-4xl font-black text-navy-900" dir="ltr" data-counter="{{ $counterKey }}">{{ \App\Services\Seo\SocialProof::compact($proofCounters[$counterKey]) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>
@endif

{{-- ============ OWNERS IN THEIR OWN WORDS (App\Models\Testimonial: real quotes with consent; hidden while none) ============ --}}
@if ($testimonials->isNotEmpty())
    <section data-testimonials class="pb-20 lg:pb-28">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center" data-reveal>
                <p class="lp-eyebrow">{{ __('From our customers') }}</p>
                <h2 class="lp-h2 mt-3">{{ __('Owners in their own words') }}</h2>
            </div>
            <ul class="mt-10 flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2 [scrollbar-width:none] sm:flex-wrap sm:justify-center sm:overflow-visible">
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
<section id="pricing" class="lp-band py-16 lg:py-24">
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <p class="lp-eyebrow">{{ __('Pricing') }}</p>
            <h2 class="lp-h2 mt-3">{{ __('Start free. Pay only when you need more.') }}</h2>
            <p class="lp-lead mt-3">{{ __('Clear prices in Egyptian pounds, with no commission on your orders.') }}</p>
        </div>
        <ul class="mt-10 grid gap-3 lg:grid-cols-3" data-home-plans>
            @foreach ($plans as $p)
                @php
                    $isContact = $p->isContactSales();
                    $isFree = ! $isContact && (float) $p->monthly_price === 0.0;
                @endphp
                <li @class(['lp-lift relative flex items-center justify-between gap-3 rounded-2xl border bg-white px-5 py-4 lg:flex-col lg:items-start lg:justify-start lg:py-5',
                            'border-emerald-600 ring-1 ring-emerald-600' => $isFree, 'border-navy-800 ring-1 ring-navy-800' => !$isFree && $p->is_featured, 'border-navy-100' => !$isFree && !$p->is_featured])
                    data-home-plan="{{ $p->code }}" data-reveal style="--d: {{ $loop->index * 60 }}ms">
                    <span class="flex min-w-0 flex-col">
                        <b class="text-lg font-black text-navy-900">{{ $p->displayName() }}</b>
                        @if ($isFree)
                            <span class="text-xs font-bold text-emerald-700">{{ __('Free forever, no card') }}</span>
                        @elseif ($isContact)
                            <span class="text-xs font-bold text-navy-600">{{ __('For chains and busy kitchens') }}</span>
                        @elseif ($p->is_featured)
                            <span class="text-xs font-bold text-navy-600">{{ __('Most popular') }}</span>
                        @endif
                    </span>
                    <span class="shrink-0 text-end lg:text-start">
                        @if ($isContact)
                            <a href="{{ $p->contactUrl() }}" target="_blank" rel="noopener" class="text-lg font-black text-brand-700 hover:text-brand-800" data-contact-sales-link>{{ __('Contact us') }}</a>
                        @else
                            <b class="text-2xl font-black tracking-tight text-navy-900" dir="ltr">{{ money($p->monthly_price) }}</b>
                            <span class="text-sm font-semibold text-navy-500">{{ __('EGP / month') }}</span>
                            @if ((float) $p->monthly_price > 0 && $p->priceFor(12) < $p->monthly_price * 12)
                                <span class="block text-xs font-semibold text-emerald-700">{{ __('or :total EGP a year', ['total' => money($p->priceFor(12))]) }}</span>
                            @endif
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
        <div class="mt-8 flex flex-wrap justify-center gap-3" data-reveal>
            <a href="{{ route('pricing') }}" class="btn-primary min-h-12 rounded-full px-7 text-base">{{ __('See prices') }}</a>
            <a href="{{ route('pricing') }}#calculator" class="btn-ghost min-h-12 rounded-full px-6 text-base">{{ __('Work out what you save') }}</a>
        </div>
        <p class="mt-4 text-center text-sm text-navy-500">{{ __('By Vodafone Cash or InstaPay from your dashboard. Paying for a whole year at once gets a discount.') }}</p>
    </div>
</section>

{{-- ============ GUESTS PAY ONLINE THROUGH THE RESTAURANT'S OWN GATEWAY (a service, App\Support\SiteServices payment_gateway) ============ --}}
<section data-payment-gateways class="pt-16 lg:pt-24">
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <div class="flex flex-col items-center gap-6 rounded-[28px] border border-navy-100 bg-white p-6 text-center sm:p-10 lg:flex-row lg:text-start" data-reveal>
            <span class="lp-idea-icon shrink-0"><x-landing.icon name="wallet" class="size-6" /></span>
            <div class="min-w-0 flex-1">
                <h2 class="text-xl font-black text-navy-900 sm:text-2xl">{{ __('Guests pay online, through your own gateway') }}</h2>
                <p class="mt-2 text-navy-600">{{ __('Have a contract with a payment company? We connect it to your menu and online orders, and the money goes straight to your account with no commission from us.') }}</p>
                <ul class="mt-4 flex flex-wrap justify-center gap-2 lg:justify-start" aria-label="{{ __('Payment gateways we connect') }}">
                    @foreach (['fawry' => 'Fawry', 'kashier' => 'Kashier', 'paymob' => 'Paymob', 'geidea' => 'Geidea'] as $gatewayFile => $gateway)
                        <li class="grid h-14 w-28 place-items-center rounded-2xl border border-navy-100 bg-white px-3 sm:w-32" data-gateway="{{ $gatewayFile }}">
                            <img src="{{ versioned_asset('img/partners/' . $gatewayFile . '.webp') }}" alt="{{ $gateway }}" width="120" height="40" loading="lazy" decoding="async" class="max-h-8 w-auto max-w-full object-contain">
                        </li>
                    @endforeach
                    <li class="grid h-14 place-items-center rounded-2xl border border-dashed border-navy-300 px-4 text-sm font-bold text-navy-500">{{ __('or any gateway with an API') }}</li>
                </ul>
            </div>
            <a href="{{ route('services', ['service' => 'payment_gateway']) }}#request" class="btn-primary min-h-12 shrink-0 rounded-full px-6">{{ __('Ask for the connection') }}</a>
        </div>
    </div>
</section>

{{-- ============ FAQ ============ --}}
<section id="faq" class="py-20 lg:py-28">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">
        <div class="text-center" data-reveal>
            <p class="lp-eyebrow">{{ __('FAQ') }}</p>
            <h2 class="lp-h2 mt-3">{{ __('Frequently asked questions') }}</h2>
            <p class="mt-3 text-navy-600">{{ __('Still have a question? Ask us and we reply the same day.') }}</p>
        </div>
        <div id="faq-list" data-more class="mt-10 space-y-3">
            @foreach ($faq as [$q, $a])
                <details name="faq" class="lp-faq group rounded-2xl border border-navy-100 bg-white px-5 open:bg-[var(--lp-paper)]" @if ($loop->first) open @endif @if ($loop->index >= 5) data-more-item @endif>
                    <summary class="flex min-h-15 cursor-pointer list-none items-center justify-between gap-4 py-4 text-[1.02rem] font-extrabold">
                        {{ $q }}
                        <span class="lp-faq-icon grid size-8 shrink-0 place-items-center rounded-full border border-navy-200 bg-white text-lg leading-none text-navy-600">+</span>
                    </summary>
                    <p class="pb-5 leading-relaxed text-navy-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
        <x-landing.more for="faq-list" />
    </div>
</section>

{{-- ============ FINAL CTA ============ --}}
<section class="lp-band lp-glow mb-8 py-16 lg:py-24">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6" data-reveal>
        <x-logo :size="44" :text="false" />
        <h2 class="lp-h2 mt-4">{{ __('Your menu can be live today') }}</h2>
        <p class="lp-lead mt-3">{{ __('Sign up, add your items and print the codes. If you need help, we set it up with you.') }}</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-primary min-h-12 rounded-full px-8 text-base">{{ __('Start free') }}</a>
            <a href="{{ route('pricing') }}" class="btn-ghost min-h-12 rounded-full px-6 text-base">{{ __('See prices') }}</a>
        </div>
    </div>
</section>
@endsection
