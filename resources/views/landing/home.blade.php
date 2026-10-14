@extends('layouts.landing')
@section('title', 'EgMenu — ' . __('QR menu, ordering and loyalty for cafes and restaurants'))

@php
    $trial = \App\Services\TenantProvisioner::TRIAL_DAYS;
    $minPrice = money($plans->min('monthly_price') ?? 0);
    $cycles = collect(\App\Models\Plan::CYCLES)->mapWithKeys(fn ($m) => [$m => (float) ($plans->max(fn ($p) => (float) ($p->cycle_discounts[(string) $m] ?? 0)) ?? 0)]);
    $themesCount = count(\App\Support\MenuThemes::ALL);
    $extraBranchOff = (int) round((1 - \App\Models\AddonPrice::EXTRA_BRANCH_SHARE) * 100);

    // Where a feature lives: the cheapest public plan that has it, else the add-on that unlocks it.
    $where = function (string $key) use ($plans, $matrix, $addons) {
        foreach ($plans as $p) {
            if (!empty($matrix[$p->id]['features'][$key])) {
                return $p === $plans->first() ? __('In every plan') : __('From :plan', ['plan' => $p->displayName()]);
            }
        }
        return $addons->contains(fn ($a) => in_array($key, (array) $a->features, true)) ? __('Add-on') : null;
    };

    $phones = [
        'cafe' => [
            'name' => __('Corner Cafe'), 'table' => __('Table 4'),
            'cats' => [__('Hot coffee'), __('Cold drinks'), __('Desserts')],
            'items' => [['☕', __('Spanish latte'), 75, 'bg-amber-100'], ['🧋', __('Iced caramel macchiato'), 85, 'bg-orange-100'], ['🍰', __('Cheesecake'), 90, 'bg-rose-100'], ['🥐', __('Butter croissant'), 45, 'bg-yellow-100']],
        ],
        'restaurant' => [
            'name' => __('Grill House'), 'table' => __('Table 12'),
            'cats' => [__('Grills'), __('Sandwiches'), __('Salads')],
            'items' => [['🍖', __('Mixed grill'), 320, 'bg-orange-100'], ['🍢', __('Shish tawook'), 185, 'bg-amber-100'], ['🥗', __('Fattoush'), 70, 'bg-lime-100'], ['🍮', __('Kunafa'), 95, 'bg-yellow-100']],
        ],
    ];

    $audiences = [
        'cafe' => [
            'icon' => 'cup', 'label' => __('Cafe'),
            'lead' => __('A cafe lives on speed and regulars. EgMenu keeps the line moving and brings the same faces back.'),
            'cards' => [
                ['stamp', 'loyalty_stamps', __('Digital stamp card'), __('“Every 5 coffees, the 6th is on us.” The cashier stamps with one tap and the card lives on the guest’s phone. No app, no paper.')],
                ['tv', 'kitchen_display', __('Bar screen for the barista'), __('Drink orders show up on a screen at the bar in the order they came, so nothing is forgotten at rush hour.')],
                ['zap', 'qr_ordering', __('Quick ordering at the counter or the table'), __('Guests order from their phone while they wait or sit; your team only prepares and serves.')],
                ['clock', 'offers', __('Happy hour that runs itself'), __('Lower the price of a category at set hours, cold drinks from 3 to 6 for example, and it switches on and off by itself.')],
            ],
            'plans' => ['starter', 'basic'],
            'pick' => __('Most cafes start with Starter (menu only) or Basic (with table ordering), then move to Pro for the stamp card.'),
        ],
        'restaurant' => [
            'icon' => 'plate', 'label' => __('Restaurant'),
            'lead' => __('A restaurant lives on accuracy and cost control. EgMenu sends clean orders to the kitchen and shows you where the money goes.'),
            'cards' => [
                ['chef', 'kitchen_display', __('Kitchen display with stations'), __('Grill, salads and desserts each see only their own items, with timers and colours. No lost paper tickets.')],
                ['plate', 'qr_ordering', __('Table ordering with options'), __('Sizes, extras and notes like “no onions”, typed by the guest and read exactly as written by the kitchen.')],
                ['scooter', 'call_center', __('Delivery zones and a call center'), __('A fee and a minimum order for every area, and a call-center screen that finds the customer by phone number.')],
                ['box', 'inventory', __('Inventory and recipe costing'), __('Know what every dish really costs you. Stock goes down by itself with every order.')],
            ],
            'plans' => ['pro', 'business'],
            'pick' => __('Restaurants usually take Pro or Business, then add the kitchen display, POS or inventory only when they need them.'),
        ],
    ];

    $compare = [
        [__('Ordering'), __('Fast: from the counter or the table, ready in minutes'), __('Detailed: sizes, extras and notes straight to the kitchen')],
        [__('Prep screen'), __('A bar screen for the barista'), __('A kitchen display with a screen per station')],
        [__('Bringing guests back'), __('Stamp card: every 5 coffees, one free'), __('Points on every order and a customer list')],
        [__('Selling more'), __('Happy hour on cold drinks or desserts'), __('Combos and “goes well with” suggestions')],
        [__('Outside the shop'), __('Takeaway from your menu link'), __('Delivery zones with fees, and a call center')],
        [__('Back office'), __('A simple daily report'), __('Inventory, recipe costing and staff permissions')],
    ];

    $features = [
        ['bell', 'qr_ordering', __('Table ordering with a sound alert'), __('The guest scans the table code and orders. The order reaches your screen at once with a ring, so nobody waits for a waiter.'), 'both'],
        ['bag', 'online_ordering', __('Online ordering with no commission'), __('Takeaway and delivery from your own menu link, with delivery zones and fees. Every pound of the order is yours.'), 'both'],
        ['stamp', 'loyalty_stamps', __('Digital loyalty card'), __('Stamps or points on the guest’s phone. You see who your regulars are and how often they come.'), 'cafe'],
        ['tag', 'offers', __('Offers, popups and combos'), __('Timed offers, a popup when the menu opens and “goes well with” suggestions that raise the bill.'), 'both'],
        ['tv', 'tv_screens', __('Menu on the shop TVs'), __('Your menu and offers on any smart TV, updated by itself whenever you change a price.'), 'both'],
        ['chart', 'daily_reports', __('Reports you can read'), __('Daily sales, best sellers, busy hours, table scans and what guests say after their visit.'), 'both'],
        ['globe', 'custom_domain', __('Your own link or domain'), __('Your menu at yourname.egmenu.app from day one, or on your own domain like mycafe.com.'), 'both'],
        ['sparkles', 'ai_import', __('Menu import from Excel or a photo'), __('Upload an Excel sheet, or a photo of your printed menu and let AI fill in the items for you.'), 'both'],
        ['users', 'staff_roles', __('Staff roles'), __('The cashier, the waiter and the kitchen each see only what they need, with the permissions you choose.'), 'restaurant'],
        ['store', 'branch_pricing', __('Branches with their own prices'), __('One menu for all branches, with a different price or availability in each one.'), 'restaurant'],
        ['chef', 'kitchen_display', __('Kitchen and bar display'), __('Orders on a screen in the kitchen or at the bar, with a screen per station and timers.'), 'restaurant'],
        ['cash', 'pos', __('POS cashier and shifts'), __('Take counter orders, open and close shifts with the cash drawer, and show the guest their order number.'), 'both'],
    ];

    $faq = [
        [__('Do I need special devices?'), __('No. The dashboard works on any phone, tablet or computer, and guests use their own phones.')],
        [__('Do my guests need to download an app?'), __('No. They scan the QR code and the menu opens in the browser.')],
        [__('I run a cafe, not a restaurant. Is EgMenu for me?'), __('Yes. When you sign up you choose cafe, restaurant or both, and EgMenu sets up the right categories, a bar screen instead of a kitchen and a stamp card ready to switch on.')],
        [__('Can I change prices myself?'), __('Yes, at any time from your dashboard. The QR menu, the printed menu and the TV screens all update.')],
        [__('Can I print the menu without a designer?'), __('Yes. Export a print-ready PDF in A4 or A5 with your colours and logo and send it to any print shop. After a price change, export a new one in one click.')],
        [__('How do I pay the subscription?'), __('By Vodafone Cash or InstaPay from your dashboard. Paying for 6 or 12 months gets a discount.')],
        [__('Is there a contract?'), __('No. The subscription is monthly and you can stop whenever you want.')],
        [__('What happens after the free trial?'), __('You get :days days of the Pro plan with no payment details. Then choose the plan that fits you, or the menu pauses until you subscribe.', ['days' => $trial])],
        [__('I have a POS or a kitchen screen already. Do I need them?'), __('No. They are optional add-ons for bigger places. Most cafes only need the menu and table ordering.')],
        [__('Is my data safe?'), __('Every restaurant’s data is kept separate and is visible only to its own staff, each with the permissions you give them.')],
    ];

    $bestFor = [
        'starter' => __('A small cafe that wants a good-looking menu'),
        'basic' => __('A cafe or restaurant with table ordering'),
        'pro' => __('A busy place that wants loyalty, offers and online orders'),
        'business' => __('Restaurants with more than one branch'),
    ];

    $groups = collect(\App\Models\Plan::GROUPS)->except('operations');
    $planByCode = $plans->keyBy('code');
@endphp

@push('head')
    <script>document.documentElement.classList.add('js')</script>
@endpush

@section('page')
<div id="lp" data-aud="cafe" data-i18n="{{ json_encode([
    'billedMonthly' => __('Billed monthly'),
    'billedEvery' => __(':total EGP for :period, you save :saved EGP'),
    'periods' => collect(\App\Models\Plan::CYCLES)->mapWithKeys(fn ($m) => [$m => cycle_label($m)]),
    'exporting' => __('Preparing the PDF…'),
    'exportPdf' => __('Export PDF'),
], JSON_UNESCAPED_UNICODE) }}">

{{-- ============ HERO ============ --}}
<section class="relative overflow-hidden bg-gradient-to-b from-brand-50 via-white to-white">
    <div class="lp-dots pointer-events-none absolute inset-0" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -top-32 -end-32 size-[28rem] rounded-full bg-brand-200/50 blur-3xl" aria-hidden="true"></div>
    <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 pt-10 pb-16 lg:grid-cols-[1.1fr_1fr] lg:pt-16 lg:pb-24">
        <div>
            <div class="inline-flex flex-wrap items-center gap-2 rounded-full border border-brand-100 bg-white/80 py-1 ps-1 pe-3 text-xs font-semibold text-navy-700 shadow-sm">
                <span class="rounded-full bg-brand-600 px-2.5 py-0.5 text-white">{{ __(':days days free', ['days' => $trial]) }}</span>
                {{ __('No card · No commission · Cancel anytime') }}
            </div>
            <h1 class="mt-5 text-[2.15rem] leading-[1.25] font-extrabold tracking-tight text-navy-900 sm:text-5xl sm:leading-[1.2]">
                {{ __('The menu, the order and the loyalty card') }}
                <span class="relative whitespace-nowrap text-brand-600">{{ __('from one QR code') }}<svg class="absolute -bottom-2 start-0 h-3 w-full text-brand-200" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true"><path d="M2 9c50-6 146-8 196-3" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg></span>
            </h1>
            <p class="mt-6 max-w-xl text-lg leading-relaxed text-navy-600">{{ __('EgMenu gives your cafe or restaurant a beautiful QR menu, ordering from the table and online with no commission, a digital loyalty card and a print-ready menu. You set it up yourself in under an hour.') }}</p>

            <div class="mt-6 flex flex-wrap items-center gap-2 text-sm" role="group" aria-label="{{ __('I run a') }}">
                <span class="font-semibold text-navy-500">{{ __('I run a') }}</span>
                <div class="inline-flex rounded-full border border-navy-100 bg-white p-1 shadow-sm">
                    <button type="button" data-aud-set="cafe" aria-pressed="true" class="flex min-h-9 items-center gap-1.5 rounded-full px-4 font-bold text-navy-700 transition"><x-landing.icon name="cup" class="size-4" />{{ __('Cafe') }}</button>
                    <button type="button" data-aud-set="restaurant" aria-pressed="false" class="flex min-h-9 items-center gap-1.5 rounded-full px-4 font-bold text-navy-700 transition"><x-landing.icon name="plate" class="size-4" />{{ __('Restaurant') }}</button>
                </div>
            </div>

            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="btn-primary group min-h-13 px-7 text-base shadow-lg shadow-brand-600/25">
                    {{ __('Start your :days-day free trial', ['days' => $trial]) }}
                    <x-landing.icon name="arrow" class="size-5 transition group-hover:translate-x-1 rtl:rotate-180 rtl:group-hover:-translate-x-1" />
                </a>
                <a href="#how" class="btn-ghost min-h-13 px-6 text-base">
                    <span class="grid size-7 place-items-center rounded-full bg-navy-800 text-white"><x-landing.icon name="play" class="size-3.5" /></span>
                    {{ __('See how it works') }}
                </a>
            </div>
            <ul class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm text-navy-600">
                @foreach ([__('Plans from :price EGP a month', ['price' => $minPrice]), __('Arabic and English'), __('Works on any phone')] as $point)
                    <li class="flex items-center gap-1.5"><x-landing.icon name="check" class="size-4 text-emerald-600" />{{ $point }}</li>
                @endforeach
            </ul>
        </div>

        {{-- phone mock-up: items get added, the cart counts up, the order reaches the dashboard --}}
        <div class="relative mx-auto" aria-hidden="true">
            <div class="absolute inset-8 -z-10 rounded-full bg-brand-300/30 blur-3xl"></div>
            @foreach ($phones as $aud => $ph)
                <div class="only-{{ $aud }} lp-phone relative rounded-[2.6rem] border-[9px] border-navy-900 bg-white shadow-[0_40px_80px_-30px_rgba(20,31,56,.55)]" data-phone>
                    <div class="absolute top-1.5 start-1/2 z-10 h-5 w-24 -translate-x-1/2 rounded-full bg-navy-900 rtl:translate-x-1/2"></div>
                    <div class="overflow-hidden rounded-[2rem]">
                        <div class="bg-navy-800 px-5 pt-9 pb-4 text-white">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-lg font-extrabold">{{ $ph['name'] }}</div>
                                    <div class="text-xs text-white/70">{{ $ph['table'] }} · {{ __('Open now') }}</div>
                                </div>
                                <div class="grid size-10 place-items-center rounded-full bg-white/10 text-xl">{{ $aud === 'cafe' ? '☕' : '🔥' }}</div>
                            </div>
                        </div>
                        <div class="flex gap-2 overflow-hidden whitespace-nowrap px-3 py-3 text-xs font-semibold">
                            @foreach ($ph['cats'] as $i => $cat)
                                <span @class(['rounded-full px-3 py-1', 'bg-brand-600 text-white' => $i === 0, 'bg-navy-50 text-navy-700' => $i > 0])>{{ $cat }}</span>
                            @endforeach
                        </div>
                        <div class="relative space-y-2 px-3 pb-20" style="min-height: 330px">
                            @foreach ($ph['items'] as [$emoji, $name, $price, $tone])
                                <div class="flex items-center gap-3 rounded-2xl border border-navy-100 bg-white p-2" data-item data-price="{{ $price }}">
                                    <div class="grid size-14 shrink-0 place-items-center rounded-xl text-2xl {{ $tone }}">{{ $emoji }}</div>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-bold text-navy-900">{{ $name }}</div>
                                        <div class="text-xs font-extrabold text-brand-700">{{ $price }} {{ __('EGP') }}</div>
                                    </div>
                                    <span class="lp-plus grid size-8 place-items-center rounded-full bg-brand-600 text-lg leading-none font-bold text-white">+</span>
                                </div>
                            @endforeach
                            <div class="lp-toast absolute inset-x-3 top-2 z-10 flex items-center gap-2 rounded-2xl bg-emerald-600 px-3 py-2.5 text-sm font-bold text-white shadow-lg" data-toast>
                                <x-landing.icon name="check" class="size-5" /> {{ __('Order sent. Preparing now!') }}
                            </div>
                            <div class="lp-cart absolute inset-x-3 bottom-3 flex items-center justify-between rounded-2xl bg-brand-600 px-4 py-3 text-sm font-bold text-white shadow-lg" data-cart>
                                <span class="flex items-center gap-2"><span class="grid size-6 place-items-center rounded-full bg-white text-xs text-brand-700" data-count>0</span>{{ __('Send order') }}</span>
                                <span><span data-total>0</span> {{ __('EGP') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="lp-float absolute top-24 -start-10 hidden items-center gap-2 rounded-2xl bg-white px-3 py-2 text-sm font-bold text-navy-800 shadow-xl ring-1 ring-navy-100 sm:flex lg:-start-40" data-ding>
                <span class="grid size-8 place-items-center rounded-xl bg-brand-50 text-brand-600"><x-landing.icon name="bell" class="size-5" /></span>
                <span>{{ __('New order') }}<span class="block text-xs font-semibold text-navy-500"><span class="only-cafe">{{ __('Table 4') }}</span><span class="only-restaurant">{{ __('Table 12') }}</span> · {{ __('just now') }}</span></span>
            </div>
            <div class="lp-float lp-float-2 absolute -bottom-5 -end-8 hidden items-center gap-2 rounded-2xl bg-navy-800 px-3 py-2 text-sm font-bold text-white shadow-xl sm:flex lg:-end-20">
                <span class="only-cafe">☕ {{ __('+1 stamp · 4 to a free coffee') }}</span>
                <span class="only-restaurant">⭐ {{ __('+32 points on this order') }}</span>
            </div>
        </div>
    </div>
</section>

{{-- ============ TRUST STRIP ============ --}}
<section class="border-y border-navy-100 bg-white">
    <div class="mx-auto grid max-w-7xl grid-cols-2 gap-y-6 px-4 py-8 text-center md:grid-cols-4">
        @foreach ([
            [$trial, '', __('days free trial, no card')],
            [0, '%', __('commission on your orders')],
            [$themesCount, '', __('ready menu designs')],
            [60, ' ' . __('min'), __('to put your menu online')],
        ] as [$n, $suffix, $label])
            <div data-reveal style="--d: {{ $loop->index * 80 }}ms">
                <div class="text-3xl font-extrabold text-navy-900 sm:text-4xl"><span data-count-to="{{ $n }}">{{ $n }}</span>{{ $suffix }}</div>
                <div class="mt-1 text-sm text-navy-500">{{ $label }}</div>
            </div>
        @endforeach
    </div>
    <div class="border-t border-navy-50 bg-navy-50/50">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-8 gap-y-2 px-4 py-3 text-sm font-semibold text-navy-600">
            <span class="flex items-center gap-2"><x-landing.icon name="flag" class="size-4 text-brand-600" />{{ __('Made in Egypt') }}</span>
            <span class="flex items-center gap-2"><x-landing.icon name="wallet" class="size-4 text-brand-600" />{{ __('Prices in EGP') }}</span>
            <span class="flex items-center gap-2"><x-landing.icon name="phone" class="size-4 text-brand-600" />{{ __('Pay by InstaPay or Vodafone Cash') }}</span>
            <span class="flex items-center gap-2"><x-landing.icon name="chat" class="size-4 text-brand-600" />{{ __('Arabic support on WhatsApp') }}</span>
        </div>
    </div>
</section>

{{-- ============ CAFE OR RESTAURANT ============ --}}
<section id="for-you" class="py-20">
    <div class="mx-auto max-w-7xl px-4">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <span class="text-sm font-bold tracking-wide text-brand-600">{{ __('Built for how you work') }}</span>
            <h2 class="mt-2 text-3xl font-extrabold sm:text-4xl">{{ __('Cafe or restaurant?') }}</h2>
            <p class="mt-3 text-navy-500">{{ __('Same app, different priorities. Pick yours and see what matters for you.') }}</p>
        </div>

        <div class="mt-8 flex justify-center" data-reveal>
            <div class="inline-flex rounded-2xl border border-navy-100 bg-navy-50 p-1.5" role="tablist" aria-label="{{ __('Cafe or restaurant?') }}">
                @foreach ($audiences as $aud => $a)
                    <button type="button" role="tab" data-aud-set="{{ $aud }}" aria-selected="{{ $aud === 'cafe' ? 'true' : 'false' }}" aria-controls="panel-{{ $aud }}" class="flex min-h-12 items-center gap-2 rounded-xl px-5 text-base font-bold text-navy-700 transition sm:px-8">
                        <x-landing.icon :name="$a['icon']" class="size-5" /> {{ $a['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        @foreach ($audiences as $aud => $a)
            <div id="panel-{{ $aud }}" role="tabpanel" class="only-{{ $aud }} aud-panel mt-10">
                <p class="mx-auto max-w-2xl text-center text-lg text-navy-700">{{ $a['lead'] }}</p>
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($a['cards'] as [$icon, $key, $title, $text])
                        <div class="card group relative flex flex-col transition hover:-translate-y-1 hover:shadow-lg">
                            <div class="flex items-start justify-between gap-2">
                                <div class="grid size-12 place-items-center rounded-2xl {{ $aud === 'cafe' ? 'bg-brand-50 text-brand-600' : 'bg-navy-50 text-navy-800' }} transition group-hover:scale-110"><x-landing.icon :name="$icon" /></div>
                                @if ($w = $where($key))<span class="badge bg-navy-50 text-navy-600">{{ $w }}</span>@endif
                            </div>
                            <h3 class="mt-4 text-lg font-bold">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-navy-500">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-6 flex flex-col items-center justify-center gap-3 rounded-2xl bg-navy-800 px-5 py-4 text-center text-white sm:flex-row sm:text-start">
                    <x-landing.icon name="sparkles" class="size-6 shrink-0 text-brand-500" />
                    <p class="text-sm sm:text-base">{{ $a['pick'] }}</p>
                    <a href="#pricing" class="btn shrink-0 bg-white text-navy-900 hover:bg-brand-50">{{ __('See the plans') }}</a>
                </div>
            </div>
        @endforeach

        {{-- side by side --}}
        <div class="mx-auto mt-14 max-w-5xl overflow-hidden rounded-3xl border border-navy-100 bg-white shadow-sm" data-reveal>
            <div class="grid grid-cols-[minmax(0,.8fr)_1fr_1fr] bg-navy-50 text-sm font-extrabold sm:text-base">
                <div class="p-3 text-navy-500 sm:p-4">{{ __('Side by side') }}</div>
                <div class="flex items-center gap-2 p-3 text-brand-700 sm:p-4" data-col="cafe"><x-landing.icon name="cup" class="size-5" />{{ __('Cafe') }}</div>
                <div class="flex items-center gap-2 p-3 text-navy-800 sm:p-4" data-col="restaurant"><x-landing.icon name="plate" class="size-5" />{{ __('Restaurant') }}</div>
            </div>
            @foreach ($compare as [$row, $cafe, $rest])
                <div class="grid grid-cols-[minmax(0,.8fr)_1fr_1fr] border-t border-navy-100 text-sm">
                    <div class="p-3 font-bold text-navy-900 sm:p-4">{{ $row }}</div>
                    <div class="p-3 text-navy-600 transition sm:p-4" data-col="cafe">{{ $cafe }}</div>
                    <div class="p-3 text-navy-600 transition sm:p-4" data-col="restaurant">{{ $rest }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ HOW IT WORKS ============ --}}
<section id="how" class="bg-navy-900 py-20 text-white">
    <div class="mx-auto max-w-7xl px-4">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <span class="text-sm font-bold tracking-wide text-brand-500">{{ __('How it works') }}</span>
            <h2 class="mt-2 text-3xl font-extrabold sm:text-4xl">{{ __('Live today, in three steps') }}</h2>
        </div>
        <div class="relative mt-12">
        <div class="absolute top-14 inset-x-[16%] hidden h-0.5 bg-gradient-to-l from-brand-600/0 via-brand-600/60 to-brand-600/0 md:block" aria-hidden="true"></div>
        <ol class="relative grid gap-6 md:grid-cols-3">
            @foreach ([
                ['upload', __('Add your menu'), __('Type it, upload an Excel sheet or a photo of your printed menu. Photos, sizes and extras included.')],
                ['qr', __('Print the QR codes'), __('One code per table, ready to print from your dashboard, plus a print-ready paper menu.')],
                ['bell', __('Receive orders'), __('Guests browse and order from their phones; you hear a ring and see every order live.')],
            ] as $n => [$icon, $title, $text])
                <li class="relative rounded-3xl border border-white/10 bg-[#1b2846] p-6 text-center" data-reveal style="--d: {{ $n * 120 }}ms">
                    <div class="relative mx-auto grid size-16 place-items-center rounded-2xl bg-brand-600 text-white shadow-lg shadow-brand-600/30">
                        <x-landing.icon :name="$icon" class="size-7" />
                        <span class="absolute -top-2 -end-2 grid size-7 place-items-center rounded-full bg-white text-sm font-extrabold text-navy-900">{{ $n + 1 }}</span>
                    </div>
                    <h3 class="mt-5 text-xl font-bold">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-white/70">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
        </div>
        <div class="mt-10 text-center" data-reveal>
            <a href="{{ route('register') }}" class="btn-primary min-h-12 px-7 text-base">{{ __('Start free') }}</a>
            <p class="mt-3 text-sm text-white/60">{{ __('Need a hand? We set it up with you on WhatsApp.') }}</p>
        </div>
    </div>
</section>

{{-- ============ LIVE ORDERS SHOWCASE ============ --}}
<section class="overflow-hidden py-20">
    <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 lg:grid-cols-2">
        <div data-reveal>
            <span class="text-sm font-bold tracking-wide text-brand-600">{{ __('Table ordering') }}</span>
            <h2 class="mt-2 text-3xl font-extrabold sm:text-4xl">{{ __('The order rings on your screen the second it is sent') }}</h2>
            <p class="mt-4 text-lg leading-relaxed text-navy-600">{{ __('No waiting for a waiter, no wrong orders. Each order shows the table, the items with their options and notes, and moves from new to ready with one tap.') }}</p>
            <ul class="mt-6 space-y-3">
                @foreach ([__('Sound alert for every new order'), __('Sizes, extras and notes as the guest wrote them'), __('Works on the phone, tablet or computer you already have')] as $point)
                    <li class="flex items-start gap-3"><span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-emerald-100 text-emerald-700"><x-landing.icon name="check" class="size-4" /></span><span class="font-semibold text-navy-800">{{ $point }}</span></li>
                @endforeach
            </ul>
        </div>
        <div class="relative" data-reveal style="--d: 120ms" aria-hidden="true">
            <div class="absolute -inset-6 -z-10 rounded-[2.5rem] bg-gradient-to-br from-brand-100 to-navy-100 blur-2xl"></div>
            <div class="rounded-3xl border border-navy-100 bg-white p-4 shadow-2xl">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2 font-extrabold"><span class="lp-pulse grid size-8 place-items-center rounded-xl bg-brand-600 text-white"><x-landing.icon name="bell" class="size-4" /></span>{{ __('Live orders') }}</div>
                    <span class="badge bg-emerald-50 text-emerald-700">● {{ __('Online') }}</span>
                </div>
                <div class="grid grid-cols-3 gap-2 text-xs" data-board data-orders="{{ json_encode([
                    [__('Table 3'), __('2× Spanish latte'), __('extra shot')],
                    [__('Table 7'), __('Mixed grill'), __('no onions')],
                    [__('Takeaway #41'), __('3× Shish tawook'), __('spicy')],
                    [__('Table 12'), __('Fattoush + Kunafa'), ''],
                    [__('Table 1'), __('Iced caramel macchiato'), __('large, oat milk')],
                ], JSON_UNESCAPED_UNICODE) }}">
                    @foreach ([['new', __('New'), 'bg-brand-50 text-brand-700'], ['prep', __('Preparing'), 'bg-amber-50 text-amber-700'], ['ready', __('Ready'), 'bg-emerald-50 text-emerald-700']] as [$col, $label, $tone])
                        <div class="rounded-2xl bg-navy-50/70 p-2">
                            <div class="mb-2 rounded-lg px-2 py-1 text-center font-bold {{ $tone }}">{{ $label }}</div>
                            <div class="space-y-2" data-board-col="{{ $col }}" style="min-height: 150px"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============ PRINTED MENU ============ --}}
<section id="print" class="relative overflow-hidden bg-gradient-to-b from-navy-50 to-white py-20">
    <div class="mx-auto max-w-7xl px-4">
        <div class="mx-auto max-w-3xl text-center" data-reveal>
            <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-sm font-bold text-brand-700 shadow-sm"><x-landing.icon name="printer" class="size-4" />{{ __('Printed menu') }}</span>
            <h2 class="mt-3 text-3xl font-extrabold sm:text-4xl">{{ __('A printed menu without a designer') }}</h2>
            <p class="mt-3 text-lg text-navy-600">{{ __('Change a price in the dashboard, export a print-ready PDF in your colours and logo, and send it straight to the print shop. Every price change is a new PDF in one click, with no designer fees each time.') }}</p>
        </div>

        <div class="mt-12 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]" data-print-demo>
            {{-- the dashboard side --}}
            <div class="space-y-4" data-reveal>
                @foreach ([
                    ['pencil', __('1. Edit items and prices'), __('Try it: change a price below and watch the paper.')],
                    ['file', __('2. Export the PDF'), __('A4 or A5, in your colours, with your logo and a QR code to the online menu.')],
                    ['printer', __('3. Send it to the print shop'), __('The file is print-ready. No designer, no waiting, no fees every time.')],
                ] as $i => [$icon, $title, $text])
                    <div class="flex gap-4 rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600"><x-landing.icon :name="$icon" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-bold">{{ $title }}</h3>
                            <p class="text-sm text-navy-500">{{ $text }}</p>
                            @if ($i === 0)
                                <div class="mt-3 space-y-2">
                                    @foreach ([['latte', __('Spanish latte'), 75], ['mocha', __('Mocha'), 80], ['cake', __('Cheesecake'), 90]] as [$k, $label, $price])
                                        <label class="flex items-center justify-between gap-3 rounded-xl bg-navy-50 px-3 py-1.5 text-sm">
                                            <span class="font-semibold">{{ $label }}</span>
                                            <span class="flex items-center gap-1"><input type="number" min="1" max="9999" value="{{ $price }}" data-price-input="{{ $k }}" class="field min-h-9 w-20 text-center font-bold" dir="ltr" aria-label="{{ $label }}"><span class="text-xs text-navy-500">{{ __('EGP') }}</span></span>
                                        </label>
                                    @endforeach
                                </div>
                            @elseif ($i === 1)
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <div class="inline-flex rounded-xl border border-navy-100 bg-navy-50 p-1 text-sm font-bold" role="group" aria-label="{{ __('Paper size') }}">
                                        <button type="button" data-paper="a4" aria-pressed="true" class="min-h-9 rounded-lg px-3 aria-pressed:bg-white aria-pressed:shadow-sm">A4</button>
                                        <button type="button" data-paper="a5" aria-pressed="false" class="min-h-9 rounded-lg px-3 aria-pressed:bg-white aria-pressed:shadow-sm">A5</button>
                                    </div>
                                    <div class="flex items-center gap-1.5" role="group" aria-label="{{ __('Your colour') }}">
                                        @foreach (['#e8642c', '#1e2d4f', '#0f766e', '#9f1239'] as $c)
                                            <button type="button" data-accent="{{ $c }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" class="size-8 rounded-full ring-offset-2 aria-pressed:ring-2 aria-pressed:ring-navy-800" style="background: {{ $c }}" aria-label="{{ $c }}"></button>
                                        @endforeach
                                    </div>
                                    <button type="button" data-export class="btn-navy min-h-9 text-sm"><x-landing.icon name="file" class="size-4" /><span data-export-label>{{ __('Export PDF') }}</span></button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- the paper --}}
            <div class="order-first flex justify-center lg:order-none lg:sticky lg:top-24" data-reveal style="--d: 120ms">
                <div class="relative">
                    <div class="lp-paper relative w-[min(100%,470px)] rounded-sm bg-white p-7 text-navy-900 shadow-[0_30px_60px_-30px_rgba(20,31,56,.5)] ring-1 ring-navy-100 sm:p-9" data-paper-sheet style="aspect-ratio: 210 / 297">
                        <div class="acc-bg absolute inset-x-0 top-0 h-2"></div>
                        <div class="text-center">
                            <div class="acc-border mx-auto grid size-14 place-items-center rounded-full border-2 text-2xl">☕</div>
                            <div class="mt-2 text-2xl font-extrabold">{{ __('Corner Cafe') }}</div>
                            <div class="acc text-xs font-bold tracking-[.3em]">MENU · {{ __('Menu') }}</div>
                        </div>
                        <div class="mt-6 grid grid-cols-2 gap-x-6 gap-y-5 text-[13px]">
                            @foreach ([
                                [__('Hot coffee'), [['latte', __('Spanish latte'), 75], ['mocha', __('Mocha'), 80], [null, __('Espresso'), 40]]],
                                [__('Cold drinks'), [[null, __('Iced caramel macchiato'), 85], [null, __('Fresh lemon mint'), 55]]],
                                [__('Desserts'), [['cake', __('Cheesecake'), 90], [null, __('Brownies'), 60]]],
                                [__('Bakery'), [[null, __('Butter croissant'), 45], [null, __('Cheese croissant'), 55]]],
                            ] as [$cat, $rows])
                                <div>
                                    <div class="acc acc-border mb-2 border-b pb-1 text-sm font-extrabold">{{ $cat }}</div>
                                    @foreach ($rows as [$k, $label, $price])
                                        <div class="flex items-end leading-snug">
                                            <span class="font-semibold">{{ $label }}</span><span class="lead"></span><b @if ($k) data-price-out="{{ $k }}" @endif class="rounded px-0.5">{{ $price }}</b>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <div class="absolute inset-x-7 bottom-6 flex items-center justify-between border-t border-navy-100 pt-3 text-[11px] text-navy-500 sm:inset-x-9">
                            <span>{{ __('Prices in EGP') }} · {{ __('Scan for the full menu') }}</span>
                            <span class="acc"><x-landing.icon name="qr" class="size-9" /></span>
                        </div>
                    </div>
                    <div class="absolute -top-3 end-2 rounded-full bg-navy-800 px-3 py-1 text-xs font-bold text-white shadow-lg" data-paper-label>A4 · PDF</div>
                    <div class="lp-toast absolute inset-x-6 -bottom-5 flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-xl" data-export-toast>
                        <x-landing.icon name="check" class="size-5" />{{ __('PDF ready. Send it to the print shop.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============ CAPABILITIES ============ --}}
<section id="features" class="py-20">
    <div class="mx-auto max-w-7xl px-4">
        <div class="mx-auto max-w-3xl text-center" data-reveal>
            <span class="text-sm font-bold tracking-wide text-brand-600">{{ __('Features') }}</span>
            <h2 class="mt-2 text-3xl font-extrabold sm:text-4xl">{{ __('Everything in one place, nothing you do not need') }}</h2>
            <p class="mt-3 text-navy-500">{{ __('Each feature shows who it helps most and where you get it.') }}</p>
        </div>
        <div class="mt-8 flex flex-wrap justify-center gap-2" role="group" aria-label="{{ __('Show features for') }}">
            @foreach (['all' => __('All'), 'cafe' => __('Cafe'), 'restaurant' => __('Restaurant')] as $k => $label)
                <button type="button" data-filter="{{ $k }}" aria-pressed="{{ $k === 'all' ? 'true' : 'false' }}" class="min-h-10 rounded-full border border-navy-100 bg-white px-4 text-sm font-bold text-navy-700 transition hover:border-navy-300 aria-pressed:border-navy-800 aria-pressed:bg-navy-800 aria-pressed:text-white">{{ $label }}</button>
            @endforeach
        </div>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($features as [$icon, $key, $title, $text, $for])
                <article class="card group flex flex-col transition duration-300 hover:-translate-y-1 hover:border-brand-200 hover:shadow-lg" data-feature="{{ $for }}" data-reveal style="--d: {{ ($loop->index % 4) * 60 }}ms">
                    <div class="flex items-start justify-between gap-2">
                        <div class="grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white"><x-landing.icon :name="$icon" /></div>
                        @if ($w = $where($key))<span class="badge bg-navy-50 text-navy-600">{{ $w }}</span>@endif
                    </div>
                    <h3 class="mt-4 font-bold">{{ $title }}</h3>
                    <p class="mt-1.5 flex-1 text-sm leading-relaxed text-navy-500">{{ $text }}</p>
                    <div class="mt-4 flex flex-wrap gap-1.5 border-t border-dashed border-navy-100 pt-3 text-xs font-semibold">
                        @if ($for === 'cafe')
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-amber-800"><x-landing.icon name="cup" class="size-3.5" />{{ __('Great for cafes') }}</span>
                        @elseif ($for === 'restaurant')
                            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-sky-800"><x-landing.icon name="plate" class="size-3.5" />{{ __('Great for restaurants') }}</span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800"><x-landing.icon name="layers" class="size-3.5" />{{ __('Great for both') }}</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ PRICING ============ --}}
<section id="pricing" class="bg-gradient-to-b from-white via-navy-50/70 to-white py-20">
    <div class="mx-auto max-w-7xl px-4">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <span class="text-sm font-bold tracking-wide text-brand-600">{{ __('Pricing') }}</span>
            <h2 class="mt-2 text-3xl font-extrabold sm:text-4xl">{{ __('Clear prices in Egyptian pounds') }}</h2>
            <p class="mt-3 text-navy-500">{{ __('Every plan starts with a :days-day free trial. No setup fees, no commission on your orders.', ['days' => $trial]) }}</p>
        </div>

        <div class="mt-8 flex flex-col items-center gap-4">
            <div class="inline-flex rounded-2xl border border-navy-100 bg-white p-1.5 shadow-sm" role="group" aria-label="{{ __('Billing period') }}">
                @foreach ($cycles as $m => $pct)
                    <button type="button" data-cycle="{{ $m }}" aria-pressed="{{ $m === 1 ? 'true' : 'false' }}" class="flex min-h-10 items-center gap-1.5 rounded-xl px-3 text-sm font-bold text-navy-600 transition aria-pressed:bg-navy-800 aria-pressed:text-white sm:px-4">
                        {{ $m === 1 ? __('Monthly') : cycle_label($m) }}
                        @if ($pct > 0)<span class="rounded-full bg-emerald-100 px-1.5 text-xs text-emerald-800" dir="ltr">−{{ money($pct) }}%</span>@endif
                    </button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center justify-center gap-2 text-sm">
                <span class="text-navy-500">{{ __('Not sure? Show me the plans for a') }}</span>
                <span class="inline-flex gap-2">
                <button type="button" data-aud-set="cafe" aria-pressed="true" class="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-navy-100 bg-white px-3 font-bold text-navy-700"><x-landing.icon name="cup" class="size-4" />{{ __('Cafe') }}</button>
                <button type="button" data-aud-set="restaurant" aria-pressed="false" class="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-navy-100 bg-white px-3 font-bold text-navy-700"><x-landing.icon name="plate" class="size-4" />{{ __('Restaurant') }}</button>
                </span>
            </div>
        </div>

        <div class="mt-10 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($plans as $p)
                @php
                    $isRec = in_array($p->code, $audiences['cafe']['plans'], true);
                @endphp
                <div @class(['relative flex flex-col rounded-3xl border bg-white p-6 transition duration-300 hover:-translate-y-1', 'border-brand-500 shadow-xl shadow-brand-600/10 ring-1 ring-brand-500' => $p->is_featured, 'border-navy-100 shadow-sm' => !$p->is_featured, 'is-recommended' => $isRec])
                     data-plan-code="{{ $p->code }}" data-monthly="{{ (float) $p->monthly_price }}" data-discounts="{{ json_encode((object) ($p->cycle_discounts ?? [])) }}" data-reveal style="--d: {{ $loop->index * 70 }}ms">
                    <div class="absolute -top-3 start-6 flex gap-1.5">
                        @if ($p->is_featured)<span class="badge bg-brand-600 text-white shadow">{{ __('Most popular') }}</span>@endif
                        <span class="rec-flag badge bg-navy-800 text-white shadow"><span class="only-cafe">{{ __('Fits a cafe') }}</span><span class="only-restaurant">{{ __('Fits a restaurant') }}</span></span>
                    </div>
                    <h3 class="text-xl font-extrabold">{{ $p->displayName() }}</h3>
                    <p class="mt-0.5 min-h-8 text-xs font-bold text-brand-700">{{ $bestFor[$p->code] ?? '' }}</p>
                    <p class="mt-2 min-h-12 text-sm text-navy-500">{{ tr($p->tagline, $p->tagline_en) }}</p>
                    <div class="mt-4 flex items-end gap-1.5">
                        @if ($p->original_price)<span class="mb-1 text-navy-400 line-through">{{ money($p->original_price) }}</span>@endif
                        <span class="text-4xl font-extrabold tracking-tight" data-price>{{ money($p->monthly_price) }}</span>
                        <span class="mb-1 text-sm text-navy-500">{{ __('EGP / month') }}</span>
                    </div>
                    <p class="mt-1 min-h-5 text-xs text-navy-500" data-billed>{{ __('Billed monthly') }}</p>
                    <div class="mt-5 flex-1 text-sm">@include('partials.plan-features', ['plan' => $p])</div>
                    <a href="{{ route('register') }}" class="{{ $p->is_featured ? 'btn-primary' : 'btn-ghost' }} mt-6 min-h-12 w-full">{{ __('Start free') }}</a>
                </div>
            @endforeach
        </div>

        {{-- full comparison --}}
        <div class="mt-14" data-reveal>
            <h3 class="text-center text-2xl font-extrabold">{{ __('Compare the plans') }}</h3>
            <p class="mt-1 text-center text-sm text-navy-500">{{ __('Everything each plan includes, side by side.') }} <span class="sm:hidden">{{ __('Swipe the table to see every plan.') }}</span></p>
            <div class="relative mt-6 overflow-x-auto rounded-3xl border border-navy-100 bg-white shadow-sm">
                <table class="lp-compare w-full min-w-[540px] text-xs sm:min-w-[640px] sm:text-sm">
                    <thead>
                        <tr class="border-b border-navy-100">
                            <th class="w-36 p-3 text-start text-xs font-bold text-navy-500 sm:w-[34%] sm:p-4">{{ __('Plan') }}</th>
                            @foreach ($plans as $p)
                                <th @class(['p-3 text-center sm:p-4', 'bg-brand-50' => $p->is_featured])>
                                    <div class="font-extrabold text-navy-900">{{ $p->displayName() }}</div>
                                    <div class="text-xs font-semibold whitespace-nowrap text-navy-500">{{ money($p->monthly_price) }} {{ __('EGP') }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="grp"><td colspan="{{ $plans->count() + 1 }}" class="bg-navy-50 px-4 py-2 text-xs font-extrabold text-navy-700">{{ __('Limits') }}</td></tr>
                        @foreach (\App\Models\Plan::LIMITS as $lk => $_)
                            <tr class="border-t border-navy-50">
                                <td class="px-4 py-2.5 text-navy-700">{{ \App\Models\Plan::label($lk) }}</td>
                                @foreach ($plans as $p)
                                    @php
                                        $v = $matrix[$p->id]['limits'][$lk];
                                    @endphp
                                    <td @class(['px-4 py-2.5 text-center font-bold', 'bg-brand-50/60' => $p->is_featured])>{{ $v === null ? __('Unlimited') : ($v === 0 ? '—' : $v) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        @foreach ($groups as $g => $gl)
                            @php
                                $keys = array_keys(array_filter(\App\Models\Plan::FEATURES, fn ($f) => $f[2] === $g));
                            @endphp
                            @continue(empty($keys))
                            <tr class="grp"><td colspan="{{ $plans->count() + 1 }}" class="bg-navy-50 px-4 py-2 text-xs font-extrabold text-navy-700">{{ app()->getLocale() === 'en' ? $gl[1] : $gl[0] }}</td></tr>
                            @foreach ($keys as $fk)
                                <tr class="border-t border-navy-50">
                                    <td class="px-4 py-2.5 text-navy-700">{{ \App\Models\Plan::label($fk) }}</td>
                                    @foreach ($plans as $p)
                                        <td @class(['px-4 py-2.5 text-center', 'bg-brand-50/60' => $p->is_featured])>
                                            @if (!empty($matrix[$p->id]['features'][$fk]))
                                                <span class="inline-grid size-6 place-items-center rounded-full bg-emerald-100 text-emerald-700"><x-landing.icon name="check" class="size-4" /></span><span class="sr-only">{{ __('Included') }}</span>
                                            @else
                                                <span class="text-navy-200" aria-hidden="true">—</span><span class="sr-only">{{ __('Not included') }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                        <tr class="border-t border-navy-100">
                            <td class="p-4"></td>
                            @foreach ($plans as $p)
                                <td @class(['p-3 text-center', 'bg-brand-50/60' => $p->is_featured])><a href="{{ route('register') }}" class="{{ $p->is_featured ? 'btn-primary' : 'btn-ghost' }} w-full text-xs">{{ __('Start free') }}</a></td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        @if ($addons->isNotEmpty())
            <div class="mt-16" data-reveal>
                <div class="mx-auto max-w-2xl text-center">
                    <h3 class="text-2xl font-extrabold">{{ __('Add-ons for bigger restaurants') }}</h3>
                    <p class="mt-2 text-navy-500">{{ __('Start with the menu. When you grow, add only what you need, on top of your plan.') }}</p>
                </div>
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($addons as $addon)
                        @php
                            $bundle = $addon->key === 'ops_bundle';
                            $minPlan = $addon->min_plan ? $planByCode->get($addon->min_plan) : null;
                        @endphp
                        <div @class(['relative flex flex-col rounded-3xl border p-5 transition hover:-translate-y-0.5 hover:shadow-lg', 'border-navy-800 bg-navy-800 text-white sm:col-span-2' => $bundle, 'border-navy-100 bg-white' => !$bundle])>
                            @if ($bundle)<span class="badge absolute -top-3 start-5 bg-brand-600 text-white">{{ __('Best value') }}</span>@endif
                            <h4 class="text-lg font-bold">{{ $addon->displayName() }}</h4>
                            <p @class(['mt-1 flex-1 text-sm', 'text-white/70' => $bundle, 'text-navy-500' => !$bundle])>{{ tr($addon->description, $addon->description_en) }}</p>
                            <div class="mt-4"><b class="text-2xl">+{{ money($addon->monthly_price) }}</b> <span @class(['text-sm', 'text-white/70' => $bundle, 'text-navy-500' => !$bundle])>{{ __('EGP / month') }}</span></div>
                            @if ($addon->per_branch || $minPlan)
                                <div class="mt-3 flex flex-wrap gap-1.5 text-xs font-semibold">
                                    @if ($addon->per_branch)<span @class(['rounded-full px-2 py-0.5', 'bg-white/10' => $bundle, 'bg-navy-50 text-navy-600' => !$bundle])>{{ __('Per branch, :pct% off each extra branch', ['pct' => $extraBranchOff]) }}</span>@endif
                                    @if ($minPlan)<span @class(['rounded-full px-2 py-0.5', 'bg-white/10' => $bundle, 'bg-brand-50 text-brand-700' => !$bundle])>{{ __('With :plan or higher', ['plan' => $minPlan->displayName()]) }}</span>@endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>

{{-- ============ FAQ ============ --}}
<section id="faq" class="py-20">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 lg:grid-cols-[1fr_2fr]">
        <div class="lg:sticky lg:top-24 lg:self-start" data-reveal>
            <span class="text-sm font-bold tracking-wide text-brand-600">{{ __('FAQ') }}</span>
            <h2 class="mt-2 text-3xl font-extrabold sm:text-4xl">{{ __('Frequently asked questions') }}</h2>
            <p class="mt-3 text-navy-500">{{ __('Still have a question? Ask us and we reply the same day.') }}</p>
        </div>
        <div class="space-y-3" data-reveal>
            @foreach ($faq as [$q, $a])
                <details name="faq" class="lp-faq group rounded-2xl border border-navy-100 bg-white px-5 transition open:border-brand-200 open:shadow-md" @if ($loop->first) open @endif>
                    <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 py-3 font-bold">
                        {{ $q }}
                        <span class="lp-faq-icon grid size-8 shrink-0 place-items-center rounded-full bg-navy-50 text-xl leading-none text-brand-600">+</span>
                    </summary>
                    <p class="pb-5 leading-relaxed text-navy-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ FINAL CTA ============ --}}
<section class="px-4 pb-20">
    <div class="relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] bg-navy-800 px-6 py-14 text-center text-white sm:px-12" data-reveal>
        <div class="pointer-events-none absolute -top-24 -start-24 size-72 rounded-full bg-brand-600/30 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 -end-24 size-72 rounded-full bg-brand-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="relative">
            <x-logo :size="44" :light="true" :text="false" />
            <h2 class="mt-4 text-3xl font-extrabold sm:text-4xl">{{ __('Your menu can be live today') }}</h2>
            <p class="mx-auto mt-3 max-w-xl text-white/70">{{ __('Sign up, add your items and print the codes. If you need help, we set it up with you.') }}</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('register') }}" class="btn-primary min-h-13 px-8 text-base shadow-lg shadow-brand-600/30">{{ __('Start your :days-day free trial', ['days' => $trial]) }}</a>
                <a href="#pricing" class="btn min-h-13 border border-white/20 px-6 text-base text-white hover:bg-white/10">{{ __('See prices') }}</a>
            </div>
        </div>
    </div>
</section>

</div>
@endsection
