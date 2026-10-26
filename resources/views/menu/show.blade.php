@extends('layouts.base', ['viteEntries' => ['resources/js/menu.js']])
@php
    // the shareable link of one item: its name, description and photo in the title and the link preview
    $share = $opened ? [
        'title' => $opened->displayName() . ' | ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name),
        'description' => (string) tx($opened, 'description'),
        'image' => $profile->imageUrl($opened->image),
    ] : null;
    $pro = $display === 'pro';
@endphp
@section('title', $share['title'] ?? $profile->title())
@section('body_class', 'm-text')

@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $currency = tr($settings['currency'], $settings['currency_en']);
    $featured = $categories->flatMap->items->where('is_featured', true)->take(8);
    // the order-type chip and sheet: the general link with more than one way to order
    // (with 2+ branches: the ways of all branches, a way the chosen branch lacks is greyed out in the sheet)
    $sheetWays ??= $ways;
    $waysMenu = ! $table && count($sheetWays) > 1;
    $wayChips = ['dine' => '🍽 ' . __('In the place'), 'takeaway' => '🛍 ' . __('Takeaway'), 'delivery' => '🛵 ' . __('Delivery')];
    $logoClass = $logoTile ? 'rounded-2xl bg-white p-1 shadow-lg' : 'drop-shadow-[0_2px_6px_rgba(0,0,0,.35)]';
@endphp

@push('head')
@include('menu.partials.seo-head', ['profile' => $profile, 'schemas' => $schemas, 'share' => $share])
@include('menu.partials.pixels')
{{-- theme-color, manifest and the home-screen icon come from partials.pwa-head (layouts.base) --}}
@if ($fontCss = \App\Support\MenuFonts::cssUrl($settings['font']))
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="{{ $fontCss }}">
@endif
@include('menu.partials.theme', ['theme' => $theme, 'season' => $season])
<style>
    body { font-family: '{{ $settings['font'] }}', 'Cairo', system-ui, sans-serif; }
    .m-qty { display: grid; place-items: center; min-width: 3rem; height: 3rem; border-radius: .9rem; font-size: 1.35rem; font-weight: 900; }
    .m-way-on { background: var(--menu-accent); color: var(--menu-accent-text); border-color: transparent; }
    /* photo placeholder: the theme accent on a surface-2 tile */
    .m-ph { background: var(--menu-surface-2); color: var(--menu-accent); box-shadow: inset 0 0 0 1px var(--menu-border); }
    .m-chip { display: inline-flex; min-height: 2.25rem; align-items: center; gap: .35rem; border-radius: 999px; padding: 0 .85rem;
        font-size: .85rem; font-weight: 800; background: rgba(255, 255, 255, .14); color: #fff; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .28); }
    /* the light header (setting header_background): the page's surface, the brand's text colour, a hairline under it */
    .m-header-light { background: var(--menu-surface); color: var(--menu-text); box-shadow: inset 0 -1px 0 var(--menu-border); }
    .m-header-light h1 { color: var(--menu-secondary); }
    .m-header-light .text-white\/75, .m-header-light .text-white\/80, .m-header-light .text-white\/90 { color: var(--menu-muted); }
    .m-header-light .border-white\/30 { border-color: var(--menu-border); }
    .m-header-light [data-logo] { filter: none; }
    .m-header-light .m-chip { background: var(--menu-surface-2); color: var(--menu-text); box-shadow: inset 0 0 0 1px var(--menu-border); }
    .m-goal-track { height: .55rem; border-radius: 999px; background: var(--menu-surface-2); box-shadow: inset 0 0 0 1px var(--menu-border); overflow: hidden; }
    .m-goal-fill { height: 100%; border-radius: inherit; background: var(--menu-accent); transition: width .4s ease; }
    .m-tag { background: color-mix(in srgb, var(--menu-text) 8%, transparent); }
    .m-filter { background: var(--menu-surface); color: var(--menu-text); box-shadow: inset 0 0 0 1px var(--menu-border); }
    .m-filter[aria-pressed="true"] { background: var(--menu-accent); color: var(--menu-accent-text); box-shadow: none; }
    /* professional display: the item sheet as a full page */
    .m-item-page { height: 100dvh; max-height: none; width: 100%; max-width: none; margin: 0; border: 0; }
    /* the scrollable rows (category chips, banners, recommended) without a scrollbar line */
    .m-noscroll { scrollbar-width: none; }
    .m-noscroll::-webkit-scrollbar { display: none; }
    /* ticker under the header: one short line at a time (App\Support\MenuTicker) */
    .m-ticker-bar { background: color-mix(in srgb, var(--menu-accent) 10%, var(--menu-bg)); color: var(--menu-text); border-bottom: 1px solid var(--menu-border); }
    .m-ticker-line { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: .4rem; padding: 0 .75rem; width: 100%;
        font-size: .85rem; font-weight: 700; color: inherit; opacity: 0; transform: translateY(45%); pointer-events: none; transition: opacity .5s ease, transform .5s ease; }
    .m-ticker-line.is-on { opacity: 1; transform: none; pointer-events: auto; }
    .m-ticker-line.is-out { transform: translateY(-45%); }
    .m-ticker-go, .m-ticker-next { color: var(--menu-accent); }
    [dir="rtl"] .m-ticker-go svg, [dir="rtl"] .m-ticker-next svg { transform: scaleX(-1); }
    /* banner slideshow dots */
    .m-dot { display: grid; place-items: center; min-width: 1.5rem; height: 1.5rem; }
    .m-dot span { width: .45rem; height: .45rem; border-radius: 999px; background: color-mix(in srgb, var(--menu-muted) 50%, transparent); transition: width .3s ease, background-color .3s ease; }
    .m-dot[aria-current="true"] span { width: 1.1rem; background: var(--menu-accent); }
    /* quick "+" on the items, the cart count bump and the small toast after adding */
    .m-qa { box-shadow: 0 2px 8px rgba(0, 0, 0, .2), 0 0 0 2px var(--menu-surface); transition: transform .15s ease; }
    .m-qa::before { content: ''; position: absolute; inset: -6px; }
    .m-qa:active { transform: scale(.9); }
    .m-quick-off .m-qa { display: none; }
    .m-bump { animation: m-bump .4s ease; }
    @keyframes m-bump { 40% { transform: scale(1.3); } }
    .m-toast { position: fixed; inset-inline: 0; bottom: 6.25rem; z-index: 40; margin-inline: auto; width: max-content; max-width: calc(100% - 2rem);
        border-radius: 999px; padding: .55rem 1rem; font-size: .85rem; font-weight: 700; background: var(--menu-text); color: var(--menu-bg);
        box-shadow: 0 6px 20px rgba(0, 0, 0, .25); opacity: 0; transform: translateY(.5rem); pointer-events: none; transition: opacity .25s ease, transform .25s ease; }
    .m-toast.is-on { opacity: 1; transform: none; }
    /* the big search bar on top (16px text so phones do not zoom in) */
    .m-search { display: flex; align-items: center; gap: .6rem; min-height: 3.25rem; border-radius: 999px; padding-inline: 1.1rem;
        background: var(--menu-surface); color: var(--menu-text); box-shadow: 0 0 0 1.5px color-mix(in srgb, var(--menu-accent) 45%, var(--menu-border)), 0 4px 14px rgba(0, 0, 0, .06); }
    .m-search:focus-within { box-shadow: 0 0 0 2.5px var(--menu-accent), 0 4px 14px rgba(0, 0, 0, .08); }
    .m-search input { flex: 1; min-width: 0; min-height: 3rem; background: transparent; border: 0; outline: 0; font-size: 1rem; color: inherit; }
    .m-search input::placeholder { color: var(--menu-muted); opacity: 1; }
    .m-search svg { color: var(--menu-accent); }
    /* category row as circles (setting menu_category_style = circles): a photo or icon, the active one ringed */
    [data-cat-nav] a.m-cat-circle { display: flex; flex-direction: column; align-items: center; gap: .3rem; width: 4.75rem; padding: .15rem 0; background: transparent !important; border: 0 !important; color: var(--menu-text); }
    [data-cat-nav] a.m-cat-circle.hidden { display: none; }
    .m-cat-ring { position: relative; display: grid; place-items: center; width: 3.75rem; height: 3.75rem; border-radius: 999px; overflow: hidden;
        background: var(--menu-surface-2); color: var(--menu-accent); box-shadow: inset 0 0 0 1px var(--menu-border); transition: box-shadow .2s ease; }
    .m-cat-ring img { width: 100%; height: 100%; object-fit: cover; }
    .m-cat-circle .m-cat-name { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .75rem; font-weight: 700; }
    .m-cat-circle[aria-current="true"] .m-cat-ring { box-shadow: 0 0 0 3px var(--menu-accent); }
    .m-cat-circle[aria-current="true"] .m-cat-name { color: var(--menu-accent); }
    /* how many items of a category match the search, on its chip or circle */
    .m-cat-count { display: none; min-width: 1.25rem; height: 1.25rem; border-radius: 999px; padding: 0 .3rem; font-size: .7rem; font-weight: 800; line-height: 1.25rem; text-align: center;
        background: var(--menu-accent); color: var(--menu-accent-text); }
    [data-searching] .m-cat-count { display: inline-block; }
    .m-cat-circle .m-cat-count { position: absolute; top: 0; inset-inline-end: .35rem; }
    [data-searching] [data-cat-link][data-matches="0"] { opacity: .45; }
    /* bold cards: the price in an accent pill, room for the round "+" next to it */
    .m-bold-price { display: inline-flex; align-items: center; min-height: 2.25rem; border-radius: .9rem; padding: 0 .85rem; font-weight: 900;
        background: var(--menu-accent); color: var(--menu-accent-text); }
    .m-bold-room { padding-inline-start: 3.4rem; }
    .m-quick-off .m-bold-room { padding-inline-start: 0; }
    /* the heart of favourites (this phone only) */
    .m-fav { background: color-mix(in srgb, var(--menu-surface) 88%, transparent); color: var(--menu-muted); box-shadow: 0 1px 4px rgba(0, 0, 0, .18); }
    .m-fav::before { content: ''; position: absolute; inset: -6px; }
    .m-fav[aria-pressed="true"] { color: var(--menu-accent); }
    .m-fav[aria-pressed="true"] svg, [data-sheet-fav][aria-pressed="true"] svg { fill: currentColor; }
    /* a banner linked to an item on a real offer: old price struck, new price, "order now" */
    .m-offer-cta { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; border-radius: 999px; padding: 0 1.1rem; font-weight: 800;
        background: var(--menu-accent); color: var(--menu-accent-text); box-shadow: 0 4px 12px rgba(0, 0, 0, .25); }
    /* professional item page: a hero in the accent with the photo in a big circle */
    .m-hero { background: var(--menu-accent); color: var(--menu-accent-text); border-end-start-radius: 2.5rem; border-end-end-radius: 2.5rem; }
    .m-hero-circle { display: grid; place-items: center; width: min(15rem, 62vw); aspect-ratio: 1; border-radius: 999px; overflow: hidden;
        background: var(--menu-surface-2); color: var(--menu-accent); box-shadow: 0 0 0 6px color-mix(in srgb, var(--menu-accent-text) 35%, transparent), 0 14px 30px rgba(0, 0, 0, .25); }
    .m-hero-circle img { width: 100%; height: 100%; object-fit: cover; }
    .m-best-badge { display: inline-flex; align-items: center; gap: .3rem; border-radius: 999px; padding: .3rem .8rem; font-size: .8rem; font-weight: 800;
        background: var(--menu-surface); color: var(--menu-accent); box-shadow: 0 4px 12px rgba(0, 0, 0, .15); }
    /* the cart: line photos, the price card and the hint under a send button that cannot send yet */
    .m-line-ph { display: grid; place-items: center; width: 3.25rem; height: 3.25rem; flex-shrink: 0; border-radius: .9rem; overflow: hidden; background: var(--menu-surface-2); color: var(--menu-accent); box-shadow: inset 0 0 0 1px var(--menu-border); }
    .m-line-ph img { width: 100%; height: 100%; object-fit: cover; }
    .m-bill { background: color-mix(in srgb, var(--menu-accent) 7%, var(--menu-surface)); border: 1px solid color-mix(in srgb, var(--menu-accent) 25%, var(--menu-border)); }
    .m-label { display: flex; align-items: center; gap: .35rem; margin-bottom: .3rem; font-size: .85rem; font-weight: 700; color: var(--menu-text); }
    .m-label svg { width: 1rem; height: 1rem; color: var(--menu-accent); flex-shrink: 0; }
    .m-send:disabled { opacity: .55; cursor: not-allowed; }
    /* header brand pieces (setting menu_header_brand): the pattern at 7% fading down, the character peeking in */
    .m-brand-pattern { position: absolute; inset: 0; pointer-events: none; background-size: 200px; background-repeat: repeat; opacity: .07;
        -webkit-mask-image: linear-gradient(to bottom, #000 40%, transparent); mask-image: linear-gradient(to bottom, #000 40%, transparent); }
    /* low on the end side, under the language button and beside the order chips, never on top of a control */
    .m-brand-peek { position: absolute; bottom: -12px; inset-inline-end: -12px; width: 64px; transform: rotate(-12deg); pointer-events: none;
        animation: m-peek-bob 4s ease-in-out infinite; }
    /* drawn for Arabic pages (as on the Scratch menu); English pages get it mirrored on the other side */
    [dir="ltr"] .m-brand-peek { transform: rotate(12deg) scaleX(-1); animation-name: m-peek-bob-ltr; }
    @keyframes m-peek-bob { 50% { transform: rotate(-6deg) translateY(4px); } }
    @keyframes m-peek-bob-ltr { 50% { transform: rotate(6deg) translateY(4px) scaleX(-1); } }
    @media (min-width: 640px) { .m-brand-peek { width: 96px; inset-inline-end: 3%; bottom: -14px; } }
    @media (prefers-reduced-motion: reduce) {
        .m-ticker-line, .m-toast, .m-dot span, .m-cat-ring { transition: none; }
        .m-bump, .m-brand-peek { animation: none; }
    }
</style>
@if ($season)
    @include('menu.partials.season-style', ['season' => $season])
@endif
@if ($intro !== 'off' || $firstSheet)
    @include('menu.partials.intro-head')
@endif
@endpush

@section('body')
@include('menu.partials.intro')
<header @class(['relative overflow-hidden', ($headerBackground ?? 'brand') === 'light' ? 'm-header-light' : 'm-secondary']) data-menu-header data-header-background="{{ $headerBackground ?? 'brand' }}">
    @if ($season && $season->decoration)
        <div class="m-season-deco" data-season-deco="{{ $season->pattern() }}" aria-hidden="true"></div>
    @endif
    @if ($settings['cover'])
        <img src="/storage/{{ $settings['cover'] }}" alt="" class="relative h-40 w-full object-cover opacity-90 sm:h-56" @if ($season?->banner) data-season-banner @endif>
    @endif
    @if (($settings['menu_header_brand'] ?? '0') === '1')
        {{-- the brand pattern fading down the header and the character peeking in (setting menu_header_brand) --}}
        @if ($headerPattern = \App\Services\BrandAssets::url($settings['intro_pattern'] ?? ''))
            <div class="m-brand-pattern" style="background-image: url('{{ $headerPattern }}')" aria-hidden="true" data-brand-pattern></div>
        @endif
        @if ($headerPeek = \App\Services\BrandAssets::url($settings['intro_mascot'] ?? ''))
            <img src="{{ $headerPeek }}" alt="" class="m-brand-peek" aria-hidden="true" data-brand-peek>
        @endif
    @endif
    <div class="relative mx-auto flex max-w-2xl items-center gap-3 px-4 py-4">
        @if ($settings['logo'])
            <img src="/storage/{{ $settings['logo'] }}" alt="{{ $brand }}" class="h-14 max-h-16 w-auto min-w-14 max-w-36 shrink-0 object-contain sm:h-16 {{ $logoClass }} {{ $settings['cover'] ? '-mt-10' : '' }}" data-logo data-logo-tile="{{ $logoTile ? 'white' : 'none' }}">
        @endif
        @php
            $showName = \App\Support\MenuHeader::showsName($settings);
        @endphp
        <div class="min-w-0 flex-1">
            {{-- the name and tagline next to the logo (setting header_show_name); hidden, the name stays for screen readers --}}
            <h1 @class(['truncate text-xl font-black', 'sr-only' => ! $showName]) data-header-name="{{ $showName ? 'shown' : 'hidden' }}">{{ $brand }}</h1>
            @if ($showName && ($t = tr($settings['tagline'], $settings['tagline_en'])))<p class="truncate text-sm text-white/75" data-header-tagline>{{ $t }}</p>@endif
        </div>
        @if ($accountEntry ?? false)
            {{-- "حسابي": the remembered guest's page, or the sheet with the ways in --}}
            @php
                $accountIcon = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
            @endphp
            @if ($accountUrl)
                <a href="{{ $accountUrl }}" class="m-chip shrink-0" data-account-entry="remembered">{!! $accountIcon !!} {{ __('My account') }}</a>
            @else
                <button type="button" class="m-chip shrink-0" data-account-entry="sheet" data-account-open>{!! $accountIcon !!} {{ __('My account') }}</button>
            @endif
        @endif
        @include('menu.partials.lang-switch')
        @if (! $table && \App\Services\Reservations\ReservationService::enabled())
            <a href="{{ route('menu.reserve', $branches->count() > 1 ? ['branch' => $branch->id] : []) }}" class="m-primary inline-flex min-h-11 shrink-0 items-center rounded-full px-3 text-sm font-bold shadow" data-reserve-link>{{ __('Book a table') }}</a>
        @endif
    </div>
    {{-- how the guest orders and from which branch (a scanned table fixes both), then at the end small round buttons:
         the place's page (about) and its social accounts --}}
    <div class="relative mx-auto flex max-w-2xl flex-wrap items-center gap-2 px-4 pb-3" data-header-chips>
            @if ($table)
                <span class="m-primary inline-flex min-h-9 items-center rounded-full px-3 text-sm font-bold" data-table-chip>🍽 {{ __('Table :name', ['name' => $table->name]) }}@if ($branches->count() > 1) · {{ tr($branch->name, $branch->name_en) }}@endif</span>
            @else
                @if ($waysMenu)
                    <button type="button" class="m-chip" data-way-chip aria-label="{{ __('How will you order?') }}"><span data-way-chip-label>{{ count($ways) > 1 ? __('How will you order?') : $wayChips[$ways[0] ?? $sheetWays[0]] }}</span> <span aria-hidden="true">▾</span></button>
                @endif
                @if ($branchPicker)
                    <button type="button" class="m-chip" data-branch-open>📍 <span class="max-w-40 truncate">{{ tr($branch->name, $branch->name_en) }}</span> <span aria-hidden="true">▾</span></button>
                @endif
            @endif
            <div class="ms-auto flex items-center gap-1.5" data-header-links>
                <a href="{{ route('menu.about') }}" aria-label="{{ __('About the place') }}" title="{{ __('About the place') }}" data-about-link
                   class="inline-grid size-8 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.01"/></svg></a>
                @include('menu.partials.social-links', ['style' => 'header'])
            </div>
    </div>
    @if ($openNow !== null)
        <div class="relative mx-auto max-w-2xl px-4 pb-3">
            <span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-emerald-500/90 text-white' => $openNow, 'bg-white/20 text-white/90' => ! $openNow]) data-open-now>{{ $openNow ? __('Open now') : __('Closed now') }}@if ($branch->working_hours) · <span dir="auto">{{ $branch->working_hours }}</span>@endif</span>
        </div>
    @endif
</header>
@if ($ticker)
    {{-- one short line at a time: offers, cart goal, the owner's lines, the season greeting (App\Support\MenuTicker) --}}
    <div class="m-ticker-bar" role="region" aria-label="{{ __('News of the place') }}" data-ticker data-ticker-mode="{{ $tickerMoves ? 'rotate' : 'static' }}" data-ticker-seconds="{{ \App\Support\MenuTicker::SECONDS }}">
        <div class="mx-auto flex max-w-2xl items-center">
            <div class="relative h-9 min-w-0 flex-1 overflow-hidden">
                @foreach ($ticker as $line)
                    @php
                        $lineAttrs = 'data-ticker-line="' . e($line['kind']) . '"' . ($loop->first ? '' : ' aria-hidden="true" tabindex="-1"');
                        $lineClass = 'm-ticker-line' . ($loop->first ? ' is-on' : '');
                        $linked = $line['item'] || $line['category'];
                    @endphp
                    @if ($line['item'])
                        <button type="button" class="{{ $lineClass }}" data-item="{{ $line['item'] }}" {!! $lineAttrs !!}>
                    @elseif ($line['category'])
                        <a href="#cat-{{ $line['category'] }}" class="{{ $lineClass }}" {!! $lineAttrs !!}>
                    @else
                        <span class="{{ $lineClass }}" {!! $lineAttrs !!}>
                    @endif
                        <span aria-hidden="true">{{ $line['icon'] }}</span>
                        <span class="truncate">{{ $line['text'] }}</span>
                        @if ($linked)<span class="m-ticker-go shrink-0" aria-hidden="true"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></span>@endif
                    @if ($line['item'])
                        </button>
                    @elseif ($line['category'])
                        </a>
                    @else
                        </span>
                    @endif
                @endforeach
            </div>
            @if (count($ticker) > 1)
                {{-- standing still (the slideshow moves, or reduced motion): the guest steps through the lines --}}
                <button type="button" @class(['m-ticker-next grid h-9 w-10 shrink-0 place-items-center', 'hidden' => $tickerMoves]) data-ticker-next aria-label="{{ __('Next line') }}">
                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                </button>
            @endif
        </div>
    </div>
@endif

@if ($busy)
    <div class="mx-auto max-w-2xl px-4 pt-4" data-busy-notice>
        <div class="flex items-start gap-3 rounded-2xl bg-amber-50 p-3 text-amber-900 ring-1 ring-amber-200">
            <span class="text-xl">⏳</span>
            <div class="text-sm">
                <b class="block">{{ __("We're busy right now") }} · {{ __('until :time', ['time' => $busy['until']]) }}</b>
                <span>{{ $busy['message'] }}</span>
            </div>
        </div>
    </div>
@endif

@if ($categories->isNotEmpty())
    {{-- a greeting by the hour and the big search bar near the top (it filters the menu as the guest types) --}}
    <section class="mx-auto max-w-2xl px-4 pt-4" data-menu-greeting>
        <p class="text-lg font-extrabold leading-tight" data-greeting>{{ $greeting }}</p>
        <p class="m-muted text-sm">{{ __('What would you like to order today?') }}</p>
        <label class="m-search mt-3" data-search-bar>
            <svg viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
            <span class="sr-only">{{ __('Search the menu') }}</span>
            <input type="search" enterkeyhint="search" autocomplete="off" placeholder="{{ __('Search for your favourite dish or drink') }}" data-search>
        </label>
    </section>
@endif

@if ($banners->isNotEmpty())
    {{--
        Banner slideshow: CSS scroll-snap (swipe), dots, and with 2+ banners an autoplay (setting banner_autoplay) that
        pauses while touched, hovered, focused, off screen or in a hidden tab, and never runs under reduced motion
        (resources/js/menu.js). While it autoplays the ticker stands still (App\Support\MenuTicker).
    --}}
    @php
        $bannerCount = $banners->count();
    @endphp
    <section class="mx-auto max-w-2xl px-4 pt-4" data-banners aria-roledescription="carousel" aria-label="{{ __('Highlights') }}" @if ($bannerAutoplay) data-banner-autoplay="{{ \App\Support\MenuTicker::BANNER_SECONDS }}" @endif>
        <div class="m-noscroll flex snap-x snap-mandatory gap-3 overflow-x-auto" data-banner-track>
            @foreach ($banners as $banner)
                @php
                    $title = tr($banner->title, $banner->title_en);
                    $type = $banner->linkType();
                    $itemOk = $type === 'item' && $available->has($banner->menu_item_id);
                    $categoryOk = $type === 'category' && $shownCategories->has($banner->category_id);
                    $classes = 'relative block shrink-0 snap-start overflow-hidden rounded-2xl bg-black/5 text-start shadow-sm ' . ($bannerCount > 1 ? 'w-[88%] sm:w-[70%]' : 'w-full');
                    $slideAttrs = 'data-banner-slide aria-roledescription="slide" aria-label="' . e(__(':n of :total', ['n' => $loop->iteration, 'total' => $bannerCount])) . '"';
                    // linked to an item on a running offer (a real discount of MenuCatalog, never a typed "was" price)
                    $offerItem = $itemOk ? $available->get($banner->menu_item_id) : null;
                    $offerItem = $offerItem && $offerItem->offer_from && ! $offerItem->is_sold_out ? $offerItem : null;
                @endphp
                @if ($offerItem)
                    <div class="{{ $classes }}" data-banner="{{ $banner->id }}" data-banner-offer="{{ $offerItem->id }}" {!! $slideAttrs !!}>
                        <button type="button" class="block w-full" data-item="{{ $offerItem->id }}" aria-label="{{ $title ?: $offerItem->displayName() }}">
                            <img src="/storage/{{ $banner->image }}" alt="" loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async" class="aspect-[16/7] w-full object-cover">
                        </button>
                        <div class="pointer-events-none absolute inset-0 flex flex-col justify-end gap-0.5 bg-gradient-to-t from-black/80 via-black/35 to-transparent p-3 pe-36 text-white">
                            <span class="w-fit rounded-full bg-white/90 px-2 py-0.5 text-[11px] font-extrabold text-neutral-900">🔥 {{ __('Special offer') }} <span dir="ltr">-{{ $offerItem->offer_percent }}%</span></span>
                            <span class="line-clamp-1 font-extrabold">{{ $title ?: $offerItem->displayName() }}</span>
                            <span class="flex items-baseline gap-2" data-offer-prices>
                                <b class="text-xl font-black">{{ money($offerItem->price) }} {{ $currency }}</b>
                                <s class="text-sm text-white/75">{{ money($offerItem->offer_from) }} {{ $currency }}</s>
                            </span>
                        </div>
                        <button type="button" class="m-offer-cta absolute end-3 bottom-3" data-offer-order="{{ $offerItem->id }}">{{ $quickAdd ? __('Order now') : __('View') }}</button>
                    </div>
                    @continue
                @endif
                @if ($itemOk)
                    <button type="button" class="{{ $classes }}" data-item="{{ $banner->menu_item_id }}" data-banner="{{ $banner->id }}" {!! $slideAttrs !!}>
                @elseif ($categoryOk)
                    <a href="#cat-{{ $banner->category_id }}" class="{{ $classes }}" data-banner="{{ $banner->id }}" {!! $slideAttrs !!}>
                @elseif ($type === 'url')
                    <a href="{{ $banner->link_url }}" target="_blank" rel="noopener" class="{{ $classes }}" data-banner="{{ $banner->id }}" {!! $slideAttrs !!}>
                @else
                    <div class="{{ $classes }}" data-banner="{{ $banner->id }}" {!! $slideAttrs !!}>
                @endif
                        <img src="/storage/{{ $banner->image }}" alt="{{ $title }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async" class="aspect-[16/7] w-full object-cover">
                        @if ($title)<span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-3 pt-8 text-sm font-extrabold text-white">{{ $title }}</span>@endif
                @if ($itemOk)
                    </button>
                @elseif ($categoryOk || $type === 'url')
                    </a>
                @else
                    </div>
                @endif
            @endforeach
        </div>
        @if ($bannerCount > 1)
            <div class="mt-1 flex justify-center" data-banner-dots>
                @foreach ($banners as $banner)
                    <button type="button" class="m-dot" data-banner-dot="{{ $loop->index }}" aria-label="{{ __('Banner :n of :total', ['n' => $loop->iteration, 'total' => $bannerCount]) }}" @if ($loop->first) aria-current="true" @endif><span></span></button>
                @endforeach
            </div>
        @endif
    </section>
@endif

@if ($categories->isNotEmpty())
    {{--
        The category row stays on top while scrolling: chips (default) or circles (setting menu_category_style) with the
        category photo or its icon (App\Support\ItemPlaceholder::forCategory). While searching, each shows how many of
        its items match. "Favourites" appears first once the guest keeps one on this phone (resources/js/menu.js).
    --}}
    <nav class="sticky top-0 z-20 border-b m-border bg-white/95 backdrop-blur" data-cat-nav data-cat-style="{{ $categoryStyle }}" aria-label="{{ __('Categories') }}">
        @if ($categoryStyle === 'circles')
            <div class="m-noscroll mx-auto flex max-w-2xl gap-1 overflow-x-auto px-3 pt-3 pb-2" data-cat-chips>
                <a href="#favs" data-cat-link="favs" class="m-cat-circle hidden shrink-0" data-cat-circle data-fav-chip>
                    <span class="m-cat-ring text-2xl" aria-hidden="true">❤️</span>
                    <span class="m-cat-name">{{ __('Favourites') }}</span>
                </a>
                @foreach ($categories as $c)
                    <a href="#cat-{{ $c->id }}" data-cat-link="cat-{{ $c->id }}" class="m-cat-circle shrink-0" data-cat-circle>
                        <span class="m-cat-ring">
                            @if ($c->image)
                                <img src="/storage/{{ $c->image }}" alt="" loading="lazy" decoding="async">
                            @else
                                {!! \App\Support\ItemPlaceholder::svg($categoryIcons[$c->id], 'size-[52%]') !!}
                            @endif
                            <span class="m-cat-count" data-cat-count aria-hidden="true"></span>
                        </span>
                        <span class="m-cat-name">{{ tx($c, 'name') }}</span>
                    </a>
                @endforeach
            </div>
        @else
            <div class="m-noscroll mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 pt-3 pb-3" data-cat-chips>
                <a href="#favs" data-cat-link="favs" class="hidden shrink-0 rounded-full m-surface-2 px-4 py-2 text-sm font-semibold transition" data-fav-chip>❤️ {{ __('Favourites') }}</a>
                @foreach ($categories as $c)
                    <a href="#cat-{{ $c->id }}" data-cat-link="cat-{{ $c->id }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-full m-surface-2 px-4 py-2 text-sm font-semibold transition">{{ tx($c, 'name') }}<span class="m-cat-count" data-cat-count aria-hidden="true"></span></a>
                @endforeach
            </div>
        @endif
        @if ($badgeChips)
            {{-- the owner's quick filters (App\Support\ItemBadges): one tap shows only the items with that label --}}
            <div class="m-noscroll mx-auto -mt-1 flex max-w-2xl gap-2 overflow-x-auto px-4 pb-3" role="group" aria-label="{{ __('Filter the menu') }}" data-badge-chips>
                @foreach ($badgeChips as $chip)
                    <button type="button" class="m-filter shrink-0 rounded-full px-3 py-1.5 text-xs font-bold" data-badge-filter="{{ $chip['key'] }}" aria-pressed="false">{{ $chip['icon'] }} {{ $chip['label'] }}</button>
                @endforeach
            </div>
        @endif
    </nav>
@endif

{{-- running offers show in the ticker under the header; m-quick-off hides the quick "+" while the cart cannot order --}}
{{-- the floating 🛎 of a scanned table: room under the list so it never sits on the last card's "+" --}}
<main @class(['mx-auto max-w-2xl px-4 pt-5', 'pb-32' => ! $calls, 'pb-52' => $calls, 'm-quick-off' => ! $quickOn]) data-menu-main>
    @if ($quickAdd)
        {{-- "اطلب تاني": the last order placed from this phone (localStorage, resources/js/menu.js fills and shows it) --}}
        <section class="m-surface m-border mb-6 hidden rounded-2xl border p-3 shadow-sm" data-last-order aria-labelledby="last-order-title">
            <div class="flex items-center gap-3">
                <span class="m-line-ph text-xl" aria-hidden="true">🧾</span>
                <div class="min-w-0 flex-1">
                    <p id="last-order-title" class="font-extrabold">{{ __('Your last order') }}</p>
                    <p class="m-muted line-clamp-2 text-sm" data-last-order-lines></p>
                </div>
                <button type="button" class="m-primary min-h-11 shrink-0 rounded-full px-4 text-sm font-extrabold" data-last-order-again>{{ __('Order it again') }}</button>
            </div>
        </section>
    @endif

    {{-- the guest's favourites on this phone: filled and shown by resources/js/menu.js --}}
    <section id="favs" class="mb-6 hidden scroll-mt-36" data-favs aria-labelledby="favs-title">
        <h2 id="favs-title" class="mb-3 text-lg font-extrabold">❤️ {{ __('Favourites') }}</h2>
        <div class="m-noscroll flex gap-3 overflow-x-auto pb-2" data-favs-list></div>
    </section>

    @php
        // "الأكثر طلباً" from real sales of this branch; without enough of them the owner's featured items
        $topItems = $bestSellers->isNotEmpty() ? $bestSellers : $featured;
    @endphp
    @if ($topItems->isNotEmpty())
        <section class="mb-6" @if ($bestSellers->isNotEmpty()) data-best-sellers @else data-featured @endif data-top-row>
            <h2 class="mb-3 text-lg font-extrabold">{{ $bestSellers->isNotEmpty() ? __('Most ordered') . ' 🔥' : '⭐ ' . __('Recommended') }}</h2>
            <div class="m-noscroll flex gap-3 overflow-x-auto pb-2">
                @foreach ($topItems as $item)
                    <button type="button" data-item="{{ $item->id }}" class="w-36 shrink-0 overflow-hidden rounded-2xl border m-border m-surface text-start shadow-sm">
                        @include('menu.items.photo', ['class' => 'aspect-square w-full', 'iconClass' => 'size-[38%]', 'phIcon' => $itemIcons[$item->id] ?? $categoryIcons[$item->category_id] ?? 'plate'])
                        <div class="p-2">
                            <div class="line-clamp-2 text-sm font-bold">{{ $item->displayName() }}</div>
                            <div class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
                                <span class="m-primary-text font-extrabold">{{ money($item->price) }} {{ $currency }}</span>
                                @if ($item->offer_from)<s class="m-muted text-xs">{{ money($item->offer_from) }}</s>@endif
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    @forelse ($categories as $c)
        <section id="cat-{{ $c->id }}" class="mb-8 scroll-mt-36" data-category>
            <h2 class="mb-1 text-xl font-extrabold" style="color: var(--menu-secondary)">{{ tx($c, 'name') }}</h2>
            @if ($d = tx($c, 'description'))<p class="mb-3 text-sm m-muted">{{ $d }}</p>@endif
            @php
                // the layout of this category: its own, the menu setting or the theme's (App\Support\MenuLayouts)
                $catLayout = $layouts[$c->id];
                $layoutView = \App\Support\MenuLayouts::view($catLayout);
            @endphp
            <div class="mt-3 {{ $layoutView['wrapper'] }}" data-layout="{{ $catLayout }}">
                @foreach ($c->items as $item)
                    @php
                        $qa = $quickAdd ? \App\Support\MenuLayouts::quickAdd($item) : null;
                        $qaSpot = $qa ? \App\Support\MenuLayouts::quickSpot($catLayout, $item, $placeholders) : null;
                        $favSpot = \App\Support\MenuLayouts::favSpot($catLayout, $item, $placeholders);
                    @endphp
                    <div class="relative" data-search-text="{{ mb_strtolower($item->name . ' ' . $item->name_en . ' ' . $item->description . ' ' . $item->displayName()) }}" data-badges="{{ implode(' ', $item->badgeKeys()) }}">
                        @include('menu.items.' . $layoutView['blade'], $layoutView['vars'] + ['item' => $item, 'phIcon' => $itemIcons[$item->id] ?? $categoryIcons[$c->id], 'qaPad' => match ($qaSpot) { 'row' => 'pe-12', 'bold' => 'bold', default => '' }])
                        @if ($qa)
                            @include('menu.items.quick-add', ['item' => $item, 'qa' => $qa, 'spot' => $qaSpot])
                        @endif
                        @if ($favSpot)
                            @include('menu.items.fav', ['item' => $item, 'spot' => $favSpot])
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <p class="py-16 text-center m-muted">{{ __('The menu is being prepared. Please check back soon.') }}</p>
    @endforelse

    @if ($loyalty)
        <a href="{{ $cardToken ? route('menu.card', $cardToken) : route('menu.card.join') }}"
           class="mb-6 flex items-center gap-4 rounded-2xl p-4 text-white shadow-md" style="background: {{ $loyalty->card_design['bg'] ?? '#B5451B' }}">
            <x-stamp-icon :icon="$loyalty->card_design['icon'] ?? 'cup'" :logo="$settings['logo']" class="size-10 shrink-0 text-white" />
            <span class="flex-1">
                <span class="block font-extrabold">{{ $cardToken ? __('My loyalty card') : __('Get your free loyalty card') }}</span>
                <span class="block text-sm text-white/80">{{ $loyalty->reward_label }} · {{ $loyalty->type === 'points' ? '' : __(':n stamps', ['n' => $loyalty->stamps_required]) }}</span>
            </span>
            <span aria-hidden="true">←</span>
        </a>
    @endif

    <p class="hidden py-10 text-center m-muted" data-no-results>{{ __('No items match your search.') }}</p>

    @if ($feedback)
        @include('partials.rate-us')
    @endif

    <footer class="mt-10 space-y-3 text-center text-sm m-muted">
        @include('menu.partials.social-links', ['style' => 'footer'])
        @if ($branch->address)<div>{{ $branch->address }}@if ($branch->working_hours) · {{ $branch->working_hours }}@endif</div>@endif
        @if (\App\Services\KnowledgeBase::hasPublic())
            {{-- the knowledge base's public answers live on the about page: a quiet link down here, never a banner on top --}}
            <div><a href="{{ route('menu.about') }}#faq" class="font-semibold underline underline-offset-2" data-faq-link>{{ __('Frequently asked questions') }}</a></div>
        @endif
        {{-- the menu as an app on the phone: hidden by partials/pwa-scripts once it runs installed --}}
        <div><button type="button" class="inline-flex min-h-10 items-center gap-2 rounded-full border px-4 font-semibold" style="border-color: var(--menu-border); color: var(--menu-text)" data-pwa-open hidden>
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM12 18h.01M12 6v7M9 10l3 3 3-3"/></svg>
            {{ __('Add the menu to your phone') }}
        </button></div>
        @include('menu.partials.powered-by')
    </footer>
</main>

@php
    $wayLabels = ['dine' => '🍽 ' . __('In the place'), 'takeaway' => '🛍 ' . __('Takeaway'), 'delivery' => '🛵 ' . __('Delivery')];
@endphp
{{-- Cart button --}}
<div class="fixed inset-x-0 bottom-0 z-30 hidden p-4" data-cart-bar>
    <button type="button" class="m-primary mx-auto flex min-h-14 w-full max-w-2xl items-center justify-between rounded-2xl px-5 font-bold shadow-xl" data-open-cart>
        <span><span class="me-2 rounded-full bg-black/15 px-2 py-0.5" data-cart-count>0</span><span data-cart-label>{{ $data['mode'] === 'list' ? __('My order') : __('View order') }}</span></span>
        <span data-cart-total></span>
    </button>
</div>
{{-- "added" feedback of the quick "+" --}}
<div class="m-toast" role="status" aria-live="polite" data-toast></div>

{{--
    Item sheet. Simple display: a sheet from the bottom. Professional display (setting menu_display = pro): the same
    dialog as a full page — big photo, back and share on top, the add bar fixed at the bottom — with its own address
    /item/{id} (resources/js/menu.js pushes it, the browser back closes it and the menu keeps its scroll).
--}}
<dialog @class(['m-0 w-full max-w-none p-0', 'mt-auto bg-transparent backdrop:bg-black/50 sm:m-auto sm:max-w-lg' => ! $pro, 'm-item-page m-surface h-dvh max-h-none backdrop:bg-black' => $pro]) data-item-sheet @if ($pro) data-item-page @endif aria-labelledby="sheet-name">
    <form method="dialog" @class(['m-surface', 'max-h-[90dvh] overflow-y-auto rounded-t-3xl sm:rounded-3xl' => ! $pro, 'h-full overflow-y-auto' => $pro]) data-item-form>
        @if ($pro)
            <div class="pointer-events-none fixed inset-x-0 top-0 z-10 mx-auto flex max-w-2xl items-center justify-between p-3">
                <button value="cancel" class="pointer-events-auto grid size-11 place-items-center rounded-full bg-black/45 text-white backdrop-blur" aria-label="{{ __('Back to the menu') }}" data-sheet-back>
                    <svg viewBox="0 0 24 24" class="size-5 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>
                </button>
                <span class="pointer-events-auto flex gap-2">
                    <button type="button" class="grid size-11 place-items-center rounded-full bg-black/45 text-white backdrop-blur aria-pressed:bg-[var(--menu-surface)] aria-pressed:text-[var(--menu-accent)]" aria-pressed="false" aria-label="{{ __('Keep in my favourites') }}" data-sheet-fav>
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 7.5 2.8C19.5 15.4 12 20 12 20Z"/></svg>
                    </button>
                    <button type="button" class="grid size-11 place-items-center rounded-full bg-black/45 text-white backdrop-blur" aria-label="{{ __('Share this item') }}" data-sheet-share>
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/></svg>
                    </button>
                </span>
            </div>
        @endif
        @if ($pro)
            {{-- professional item page: a hero in the accent with the photo (or the themed icon) in a big circle --}}
            <div class="relative" data-sheet-media>
                <div class="m-hero px-5 pt-16 pb-7" data-sheet-hero>
                    <div class="mx-auto flex max-w-2xl flex-col items-center gap-3">
                        <div class="m-hero-circle">
                            <img alt="" class="hidden" data-sheet-image>
                            <span class="hidden size-full place-items-center text-6xl font-black" data-sheet-ph aria-hidden="true"></span>
                        </div>
                        <span class="m-best-badge -mt-6 hidden" data-sheet-best>🔥 {{ __('Most ordered') }}</span>
                    </div>
                </div>
                {{-- item video (uploads: muted loop inline, poster = photo; links: embed loaded on tap) --}}
                <div class="mx-auto mt-4 hidden aspect-video w-full max-w-2xl bg-black sm:rounded-2xl" data-sheet-video></div>
                <button type="button" class="m-surface absolute bottom-3 start-3 hidden min-h-11 items-center gap-2 rounded-full px-4 text-sm font-extrabold shadow-lg" data-sheet-video-play>▶ {{ __('Watch the video') }}</button>
            </div>
        @else
            <div class="relative" data-sheet-media>
                <img alt="" class="hidden aspect-video w-full object-cover" data-sheet-image>
                {{-- item video (uploads: muted loop inline, poster = photo; links: embed loaded on tap) --}}
                <div class="hidden aspect-video w-full bg-black" data-sheet-video></div>
                <button type="button" class="m-primary absolute bottom-3 start-3 hidden min-h-10 items-center gap-2 rounded-full px-4 text-sm font-extrabold shadow-lg" data-sheet-video-play>▶ {{ __('Watch the video') }}</button>
            </div>
        @endif
        <div @class(['p-5', 'mx-auto max-w-2xl pb-28' => $pro]) data-sheet-body>
            <div class="flex items-start justify-between gap-3">
                <h3 id="sheet-name" @class(['font-extrabold', 'text-xl' => ! $pro, 'text-2xl' => $pro]) data-sheet-name></h3>
                @unless ($pro)
                    <span class="flex shrink-0 items-center gap-1">
                        <button type="button" class="m-fav relative grid size-10 place-items-center rounded-full" aria-pressed="false" aria-label="{{ __('Keep in my favourites') }}" data-sheet-fav>
                            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 7.5 2.8C19.5 15.4 12 20 12 20Z"/></svg>
                        </button>
                        <button value="cancel" class="m-muted grid size-11 place-items-center text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
                    </span>
                @endunless
            </div>
            @unless ($pro)
                <span class="m-tag mt-1 hidden w-fit items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold" data-sheet-best>🔥 {{ __('Most ordered') }}</span>
            @endunless
            <p class="m-primary-text mt-1 flex flex-wrap items-center gap-2 text-lg font-extrabold" data-sheet-price></p>
            <div class="mt-2 hidden flex-wrap gap-1" data-sheet-badges></div>
            <p @class(['m-muted mt-2', 'leading-relaxed' => $pro]) data-sheet-desc></p>
            <p class="m-muted mt-1 text-xs" data-sheet-cal></p>
            <div class="mt-2 hidden flex-wrap items-center gap-1.5 text-xs" data-sheet-allergens></div>
            <div class="mt-3 hidden rounded-xl bg-brand-50 p-3 text-sm" data-sheet-combo></div>
            <div class="mt-4 space-y-5" data-sheet-groups></div>
            <label class="mt-5 block" data-sheet-notes-box>
                <span class="field-label">{{ __('Notes for the kitchen') }}</span>
                <input class="field" maxlength="200" data-sheet-notes placeholder="{{ __('e.g. no sugar') }}">
            </label>
            <div @class(['flex items-center gap-3', 'mt-5' => ! $pro, 'm-surface m-border fixed inset-x-0 bottom-0 z-10 border-t px-5 py-3' => $pro]) data-sheet-actions>
                <div @class(['flex w-full items-center gap-3', 'mx-auto max-w-2xl' => $pro])>
                    <div class="m-stepper">
                        <button type="button" data-qty="-1" aria-label="−">−</button>
                        <span data-sheet-qty aria-live="polite">1</span>
                        <button type="button" data-qty="1" aria-label="+">+</button>
                    </div>
                    <button type="button" class="m-primary min-h-12 flex-1 rounded-xl font-bold" data-add-to-cart>
                        {{ __('Add') }} · <span data-sheet-total></span>
                    </button>
                </div>
            </div>
            <p class="mt-3 hidden text-center font-bold text-red-600" data-sheet-soldout>{{ __('Sold out') }}</p>
        </div>
    </form>
</dialog>

{{-- "Goes well with" after adding an item --}}
<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/40 sm:m-auto sm:max-w-lg" data-pair-sheet>
    <form method="dialog" class="m-surface rounded-t-3xl p-5 sm:rounded-3xl">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-lg font-extrabold">{{ __('Goes well with') }}</h3>
            <button value="cancel" class="m-muted text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
        </div>
        <div class="space-y-2" data-pair-list></div>
        <button value="cancel" class="btn-ghost mt-4 w-full">{{ __('No, thanks') }}</button>
    </form>
</dialog>

@if ($popup)
{{--
    The banner the owner marked "show as soon as the menu opens". It waits until the intro and the first-open sheet
    are closed (data-popup-after), so two layers never stack; once per visit unless the owner chose otherwise.
--}}
<dialog class="m-surface m-auto w-[min(26rem,92vw)] overflow-hidden rounded-3xl p-0 backdrop:bg-black/50" data-popup="{{ $popup->id }}" @if ($firstSheet) data-popup-after="first-sheet" @endif aria-labelledby="popup-title">
    <form method="dialog">
        <img alt="" class="hidden aspect-[16/7] w-full object-cover" data-popup-image>
        <div class="p-5 text-center">
            @if ($popupTitle = tr($popup->title, $popup->title_en))
                <h3 id="popup-title" class="text-xl font-extrabold" data-popup-title>{{ $popupTitle }}</h3>
            @endif
            <div @class(['grid gap-2', 'mt-4' => $popupTitle])>
                @if ($popupLink)
                    <button type="button" class="m-primary min-h-12 rounded-xl font-bold" data-popup-action>{{ isset($popupLink['item']) ? __('Order now') : __('View') }}</button>
                @endif
                <button value="cancel" class="m-muted min-h-11 text-sm">{{ __('Close') }}</button>
            </div>
        </div>
    </form>
</dialog>
@endif

@if ($calls)
    {{-- call the waiter / ask for the bill (scanned table, plan with table ordering) --}}
    <button type="button" class="m-surface m-ring fixed bottom-24 start-4 z-30 grid size-14 place-items-center rounded-full text-2xl shadow-lg ring-1" data-call-open aria-label="{{ __('Call the waiter') }}">🛎</button>
    <dialog class="m-surface m-auto w-full max-w-xs rounded-3xl p-0 backdrop:bg-black/50" data-call-sheet>
        <form method="dialog" class="space-y-3 p-5 text-center">
            <h3 class="text-lg font-extrabold">{{ __('Table :name', ['name' => $table->name]) }}</h3>
            <button type="button" class="m-primary min-h-12 w-full rounded-2xl font-bold" data-call="waiter">🛎 {{ __('Call the waiter') }}</button>
            <button type="button" class="min-h-12 w-full rounded-2xl bg-emerald-600 font-bold text-white" data-call="bill">🧾 {{ __('The bill, please') }}</button>
            <p class="hidden text-sm font-semibold text-emerald-700" data-call-done></p>
            <button value="cancel" class="m-muted text-sm">{{ __('Close') }}</button>
        </form>
    </dialog>
@endif

@if (($accountEntry ?? false) && ! $accountUrl)
    {{-- "حسابي" on a phone that does not know the guest: the account link, or mobile + a previous order number --}}
    <dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-account-sheet aria-labelledby="account-sheet-title">
        <div class="m-surface max-h-[90dvh] overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl">
            <div class="mb-1 flex items-center justify-between">
                <h3 id="account-sheet-title" class="text-xl font-extrabold">{{ __('My account') }}</h3>
                <button type="button" class="m-muted grid size-11 place-items-center text-2xl leading-none" data-account-close aria-label="{{ __('Close') }}">×</button>
            </div>
            <p class="mb-4 text-sm m-muted">{{ __('Your orders, order again in one tap, your addresses and your points, on any phone.') }}</p>
            @include('menu.partials.account-sign-in', ['idSuffix' => 'sheet'])
        </div>
    </dialog>
@endif

{{-- Cart sheet: an order (table / takeaway / delivery) or, in the hall, a list to show the waiter --}}
<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-cart-sheet>
    <form method="dialog" class="m-surface max-h-[90dvh] overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl" data-cart-panel>
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-xl font-extrabold" data-cart-title>{{ $data['mode'] === 'list' ? __('My order') : __('Your order') }}</h3>
            <button value="cancel" class="m-muted text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
        </div>
        @if (! $table && count($ways) > 1)
            {{-- how the guest orders (also chosen on the "how will you order?" sheet). Nothing is picked for them: until they
                 choose, the order fields and the send button wait (resources/js/menu.js) --}}
            <p class="mb-2 text-sm font-extrabold" data-way-ask>{{ __('How will you order?') }}</p>
            <div class="m-surface-2 mb-3 grid gap-1 rounded-2xl p-1" style="grid-template-columns: repeat({{ count($ways) }}, minmax(0, 1fr))" data-way-switch>
                @foreach ($ways as $way)
                    <button type="button" class="min-h-11 rounded-xl border border-transparent text-sm font-bold" data-way="{{ $way }}" @if ($way !== 'dine') data-otype="{{ $way }}" @endif>{{ $wayLabels[$way] }}</button>
                @endforeach
            </div>
        @endif
        @if ($hall['mode'] === 'list')
            <p @class(['m-surface-2 mb-3 rounded-xl p-3 text-sm font-semibold', 'hidden' => $data['mode'] !== 'list']) data-hall-notice>📋 {{ $hall['notice'] }}</p>
            @if ($hall['scanHint'])
                <p @class(['m-muted mb-3 text-xs', 'hidden' => $data['mode'] !== 'list']) data-scan-hint>{{ __('Show this list to the waiter. To order from your phone, scan the QR code on your table.') }}</p>
            @endif
        @endif
        @if ($goal)
            {{-- cart goal progress; the reward itself is applied by the server when the order is placed --}}
            <div class="m-surface-2 m-border mb-3 hidden rounded-2xl border p-3" data-goal-box>
                <p class="text-sm font-extrabold" data-goal-text></p>
                <div class="m-goal-track mt-2" data-goal-track><div class="m-goal-fill" style="width: 0%" data-goal-fill></div></div>
            </div>
        @endif
        <ul class="m-divide divide-y" data-cart-lines></ul>
        {{-- the bill: subtotal, delivery fee, discounts, total, the expected time and how to reach the place --}}
        <div class="m-bill mt-3 rounded-2xl p-3 text-sm" data-bill>
            <div class="space-y-1" data-cart-summary></div>
            <p class="m-border mt-2 hidden items-center gap-1.5 border-t pt-2 font-semibold" data-cart-eta></p>
            @if ($canOrder && ($data['phone'] !== '' || $data['whatsapp']))
                <p class="m-muted mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" data-cart-contact>
                    <span>{{ __('To reach the place:') }}</span>
                    @if ($data['phone'] !== '')<a href="tel:{{ preg_replace('/[^0-9+]/', '', $data['phone']) }}" class="m-primary-text inline-flex min-h-11 items-center gap-1 font-bold" dir="ltr">📞 {{ $data['phone'] }}</a>@endif
                    @if ($data['whatsapp'])<a href="https://wa.me/{{ $data['whatsapp'] }}" target="_blank" rel="noopener" class="m-primary-text inline-flex min-h-11 items-center gap-1 font-bold">💬 {{ __('WhatsApp') }}</a>@endif
                </p>
            @endif
        </div>
        @if ($hall['mode'] !== 'off')
            <div @class(['mt-4 grid gap-2', 'hidden' => ! in_array($data['mode'], ['list', 'table'], true)]) data-hall-tools>
                @if ($hall['mode'] === 'list')
                    <button type="button" class="m-primary min-h-14 w-full rounded-2xl text-lg font-extrabold" data-show-waiter>🧾 {{ __('Show the waiter') }}</button>
                    @if ($calls)
                        <button type="button" class="m-surface-2 m-border min-h-12 w-full rounded-2xl border font-bold" data-call="waiter" data-hall-call>🛎 {{ __('Call the waiter') }}</button>
                        <p class="hidden text-center text-sm font-semibold text-emerald-700" data-hall-call-done></p>
                    @endif
                @endif
                @if ($hall['prices'])
                    <button type="button" class="btn-ghost w-full" data-open-split>➗ {{ __('Split the bill') }}</button>
                @endif
            </div>
        @endif
        @if ($canOrder)
            <div @class(['hidden' => ! in_array($data['mode'], ['table', 'online'], true)]) data-order-fields>
                @if ($online)
                    {{-- ordering from the menu link: pickup or delivery --}}
                    <div class="mt-4 space-y-3" data-online>
                        @if (count($ways) < 2)
                            <div class="grid grid-cols-2 gap-2">
                                @if ($online['takeaway'])<button type="button" class="m-border min-h-11 rounded-xl border font-bold" data-otype="takeaway">🛍 {{ __('Takeaway') }}</button>@endif
                                @if ($online['delivery'])<button type="button" class="m-border min-h-11 rounded-xl border font-bold" data-otype="delivery">🛵 {{ __('Delivery') }}</button>@endif
                            </div>
                        @endif
                        <div class="hidden space-y-3" data-delivery-fields>
                            <label class="block">
                                <span class="m-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/></svg> {{ __('Your area') }}</span>
                                <select class="field" data-zone>
                                    <option value="">{{ __('Choose your area') }}</option>
                                    @foreach ($online['zones'] as $z)<option value="{{ $z['id'] }}">{{ $z['name'] }} · {{ money($z['fee']) }} {{ tr($settings['currency'], $settings['currency_en']) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="m-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/></svg> {{ __('The address in detail') }}</span>
                                <textarea class="field min-h-16 py-2" maxlength="300" data-address placeholder="{{ __('Street, building, floor, apartment, landmark') }}"></textarea>
                            </label>
                            {{-- optional pin on the map: the driver navigates straight to it (with drawn zones it picks the zone too) --}}
                            <div class="space-y-2" data-pin-box>
                                <button type="button" class="m-border flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border font-bold" data-pin-open>🗺️ <span data-pin-open-text>{{ __('Pin my location on the map') }}</span></button>
                                <div class="hidden items-center justify-between gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 ring-1 ring-emerald-200" data-pin-done>
                                    <span>📍 {{ __('Your location is pinned: the driver comes straight to it.') }}</span>
                                    <button type="button" class="shrink-0 font-bold underline" data-pin-clear>{{ __('Remove') }}</button>
                                </div>
                                <p class="hidden rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" data-pin-outside>{{ __('Sorry, we do not deliver to your location yet.') }}</p>
                            </div>
                        </div>
                        @if ($online['scheduled'])
                            {{-- as soon as possible by default; a set time only for the guest who asks for one --}}
                            <details class="text-sm" data-schedule-box>
                                <summary class="m-primary-text cursor-pointer py-1 font-bold">⏰ {{ __('Want it at a set time?') }}</summary>
                                <label class="mt-2 block">
                                    <span class="field-label">{{ __('When? (leave empty for as soon as possible)') }}</span>
                                    <input class="field" type="datetime-local" data-scheduled dir="ltr">
                                </label>
                            </details>
                        @endif
                        <p class="m-muted text-xs">{{ __('You pay on pickup or delivery.') }}</p>
                    </div>
                @endif
                <div class="mt-4 grid gap-3">
                    <label class="block">
                        <span class="m-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg> {{ $online ? __('Your name') : __('Your name (optional)') }}</span>
                        <input class="field" maxlength="100" autocomplete="name" data-customer-name placeholder="{{ __('e.g. Ahmed') }}">
                    </label>
                    <label class="block">
                        <span class="m-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg> {{ $online ? __('Mobile number') : __('Mobile (optional, for offers & loyalty)') }}</span>
                        <input class="field" maxlength="20" inputmode="tel" autocomplete="tel" dir="ltr" data-customer-phone placeholder="01xxxxxxxxx">
                    </label>
                    {{-- the name and mobile remembered on this phone from the last order (resources/js/menu.js) --}}
                    <button type="button" class="m-primary-text hidden min-h-9 justify-self-start text-xs font-bold underline underline-offset-2" data-guest-forget>{{ __('Not you? Clear my details') }}</button>
                    {{-- optional extras folded away so the cart stays short: a coupon code and notes --}}
                    <details class="m-surface-2 rounded-xl px-3" data-order-extras>
                        <summary class="m-primary-text min-h-11 cursor-pointer content-center text-sm font-bold">+ {{ __('A coupon code or notes') }}</summary>
                        <div class="grid gap-3 pb-3">
                            @if (\App\Models\Coupon::query()->where('is_active', true)->exists())
                                {{-- applied before sending: the discount line and the new total show in the bill (resources/js/menu.js) --}}
                                <div>
                                    <div class="flex gap-2">
                                        <input class="field min-w-0 flex-1 uppercase" maxlength="30" dir="ltr" autocomplete="off" data-coupon placeholder="{{ __('Coupon code (optional)') }}">
                                        <button type="button" class="m-primary min-h-11 shrink-0 rounded-xl px-4 text-sm font-bold" data-coupon-apply>{{ __('Apply') }}</button>
                                    </div>
                                    <p class="mt-1 hidden text-xs font-bold" role="status" data-coupon-status></p>
                                </div>
                            @endif
                            <textarea class="field min-h-16 py-2" maxlength="500" data-order-notes placeholder="{{ __('Notes for the order') }}"></textarea>
                        </div>
                    </details>
                </div>
                @if ($data['wallet'])
                    <label class="mt-3 flex items-center gap-3 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900 ring-1 ring-emerald-200">
                        <input type="checkbox" class="size-5 shrink-0" data-pay-wallet>
                        <span>
                            <b class="block">{{ __('You have a balance of :amount', ['amount' => money($data['wallet']['balance']) . ' ' . $data['currency']]) }}</b>
                            <span class="block" data-wallet-note>{{ __('Pay from my balance') }}</span>
                        </span>
                    </label>
                @endif
                <p class="mt-3 hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-order-error></p>
                <button type="button" class="m-primary m-send mt-4 min-h-14 w-full rounded-2xl text-lg font-bold" data-send-order aria-describedby="send-help">{{ __('Send order') }}</button>
                {{-- while something is missing the button waits and this line says exactly what (resources/js/menu.js) --}}
                <p id="send-help" class="m-muted mt-2 hidden text-center text-sm font-semibold" data-send-help aria-live="polite"></p>
            </div>
        @endif
    </form>
</dialog>

@if ($hall['mode'] === 'list')
    {{-- the list in big type for the waiter (Desoky / Gold read-out) --}}
    <dialog class="m-surface m-0 h-dvh max-h-none w-full max-w-none p-0 backdrop:bg-black" data-waiter-view aria-label="{{ __('Show the waiter') }}">
        {{-- the order shown to the waiter: a compact header, a list that scrolls, the total and buttons always on screen --}}
        <div class="mx-auto flex h-full max-w-xl flex-col">
            <div class="m-border flex items-center justify-between gap-3 border-b px-5 pt-5 pb-3">
                <div class="min-w-0">
                    <div class="truncate text-lg font-black">{{ $brand }}</div>
                    <div class="m-muted text-sm"><span data-waiter-count></span> · <span data-waiter-time></span></div>
                </div>
                @if ($table)
                    <div class="m-primary shrink-0 rounded-2xl px-4 py-2 text-xl font-black">{{ __('Table :name', ['name' => $table->name]) }}</div>
                @endif
            </div>
            <ul class="m-divide flex-1 divide-y overflow-y-auto px-5" data-waiter-lines></ul>
            <div class="m-border border-t px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <div class="text-lg" data-waiter-total></div>
                <div class="mt-3 grid gap-2 @if ($calls) grid-cols-2 @endif">
                    @if ($calls)
                        <button type="button" class="m-primary min-h-12 w-full rounded-2xl font-extrabold" data-call="waiter" data-hall-call>🛎 {{ __('Call the waiter') }}</button>
                    @endif
                    <button type="button" class="btn-ghost min-h-12 w-full" data-waiter-close>{{ __('Back') }}</button>
                </div>
            </div>
        </div>
    </dialog>
@endif

@if ($hall['mode'] !== 'off' && $hall['prices'])
    {{-- split the bill (pure client side, estimate) --}}
    <dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-split-sheet>
        <form method="dialog" class="m-surface max-h-[92dvh] overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-xl font-extrabold">➗ {{ __('Split the bill') }}</h3>
                <button value="cancel" class="m-muted text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
            </div>
            <div class="space-y-4" data-split-body></div>
        </form>
    </dialog>
@endif

@if ($online && $online['delivery'])
    {{-- delivery step: the area first (main area, then its street / sub-area), with the fee, minimum and time --}}
    @php
        $zoneRoots = collect($online['zones'])->whereNull('parent')->values();
        $zoneKids = collect($online['zones'])->whereNotNull('parent')->groupBy('parent');
        // sub-areas whose main area is not offered on its own still get listed
        $orphans = collect($online['zones'])->whereNotNull('parent')->reject(fn ($z) => $zoneRoots->contains('id', $z['parent']));
        $zoneMap = ($settings['delivery_zone_mode'] ?? 'manual') === 'map' && collect($online['zones'])->contains(fn ($z) => $z['polygon'] !== null);
        $zoneFacts = function (array $z) use ($currency) {
            $facts = [__('Delivery :fee', ['fee' => money($z['fee']) . ' ' . $currency])];
            if ($z['min'] > 0) {
                $facts[] = __('Minimum :amount', ['amount' => money($z['min']) . ' ' . $currency]);
            }
            if ($z['eta'] ?? null) {
                $facts[] = __('About :n min', ['n' => $z['eta']]);
            }

            return implode(' · ', $facts);
        };
    @endphp
    <dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-zone-sheet aria-labelledby="zone-sheet-title">
        <form method="dialog" class="m-surface max-h-[90dvh] overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl">
            <div class="mb-1 flex items-center justify-between">
                <h3 id="zone-sheet-title" class="text-xl font-extrabold">🛵 {{ __('Where should we deliver?') }}</h3>
                <button value="cancel" class="m-muted text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
            </div>
            <p class="m-muted mb-3 text-sm">{{ __('Choose your area to see the delivery fee, the minimum order and the time.') }}</p>
            @if ($zoneMap)
                <button type="button" class="m-primary mb-3 min-h-12 w-full rounded-2xl font-extrabold" data-zone-detect>📍 {{ __('Detect my area') }}</button>
            @endif
            <div class="mb-3 hidden rounded-2xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" data-zone-outside>
                <b class="block">{{ __('Sorry, we do not deliver to your location yet.') }}</b>
                <span>{{ __('Choose the nearest area from the list, or ask us on WhatsApp.') }}</span>
                @if ($data['whatsapp'])
                    <a href="https://wa.me/{{ $data['whatsapp'] }}" target="_blank" rel="noopener" class="mt-2 flex min-h-11 items-center justify-center rounded-xl bg-emerald-600 font-bold text-white" data-zone-whatsapp>💬 {{ __('Ask on WhatsApp') }}</a>
                @endif
            </div>
            <div data-zone-step="root">
                <div class="space-y-2">
                    @foreach ($zoneRoots->concat($orphans) as $z)
                        @php
                            $kids = $zoneKids->get($z['id'], collect());
                        @endphp
                        <button type="button" class="m-border flex min-h-14 w-full items-center justify-between gap-3 rounded-2xl border p-3 text-start" @if ($kids->isNotEmpty()) data-zone-open="{{ $z['id'] }}" @else data-zone-pick="{{ $z['id'] }}" @endif>
                            <span class="min-w-0">
                                <span class="block font-extrabold">{{ $z['name'] }}</span>
                                <span class="m-muted block text-xs">{{ $kids->isNotEmpty() ? __(':n streets / areas', ['n' => $kids->count()]) : $zoneFacts($z) }}</span>
                            </span>
                            <span class="m-primary-text shrink-0 text-lg" aria-hidden="true">‹</span>
                        </button>
                    @endforeach
                </div>
            </div>
            @foreach ($zoneKids as $parentId => $kids)
                <div class="hidden" data-zone-step="{{ $parentId }}">
                    <button type="button" class="m-primary-text mb-2 text-sm font-bold" data-zone-back>→ {{ __('All areas') }}</button>
                    @if ($kids->count() > 6)
                        <input type="search" class="field mb-2" placeholder="{{ __('Search for your street') }}" data-zone-search>
                    @endif
                    <div class="space-y-2">
                        @foreach ($kids as $z)
                            <button type="button" class="m-border flex min-h-14 w-full items-center justify-between gap-3 rounded-2xl border p-3 text-start" data-zone-pick="{{ $z['id'] }}" data-zone-text="{{ mb_strtolower($z['name']) }}">
                                <span class="min-w-0">
                                    <span class="block font-extrabold">{{ \Illuminate\Support\Str::afterLast($z['name'], '› ') }}</span>
                                    <span class="m-muted block text-xs">{{ $zoneFacts($z) }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </form>
    </dialog>

    {{-- "pin my location": a draggable pin on the map (Leaflet loads on demand), "use my current location", confirm --}}
    <dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-pin-sheet aria-labelledby="pin-sheet-title">
        <form method="dialog" class="m-surface max-h-[92dvh] overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl">
            <div class="mb-1 flex items-center justify-between">
                <h3 id="pin-sheet-title" class="text-xl font-extrabold">📍 {{ __('Where exactly are you?') }}</h3>
                <button value="cancel" class="m-muted text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
            </div>
            <p class="m-muted mb-3 text-sm">{{ __('Drag the pin to your door, or tap the map. The driver navigates straight to it.') }}</p>
            <div class="h-72 overflow-hidden rounded-2xl bg-black/5" dir="ltr" data-pin-map></div>
            <p class="m-muted mt-2 hidden text-center text-sm" data-pin-loading>{{ __('Loading the map…') }}</p>
            <p class="mt-2 hidden rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" data-pin-sheet-outside>{{ __('This point is outside our delivery areas. Move the pin closer, or choose your area from the list.') }}</p>
            <p class="mt-2 hidden rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 ring-1 ring-emerald-200" data-pin-zone></p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <button type="button" class="m-border min-h-12 rounded-2xl border font-bold" data-pin-locate>🎯 {{ __('Use my current location') }}</button>
                <button type="button" class="m-primary min-h-12 rounded-2xl font-extrabold" data-pin-confirm>✓ {{ __('Confirm the location') }}</button>
            </div>
        </form>
    </dialog>
@endif

<script type="application/json" id="menu-data">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@php
    $i18n = [
        'required' => __('Required'), 'chooseOne' => __('Choose one'), 'chooseUpTo' => __('Choose up to :n', ['n' => '__N__']),
        'optional' => __('Optional'), 'subtotal' => __('Subtotal'), 'service' => __('Service'), 'tax' => __('Tax'), 'total' => __('Total'),
        'remove' => __('Remove'), 'sending' => __('Sending…'), 'pickRequired' => __('Please choose: :group', ['group' => '__G__']),
        'cal' => __(':n kcal', ['n' => '__N__']), 'empty' => __('Your cart is empty.'),
        'comboHas' => __('This combo has:'), 'save' => __('You save :amount', ['amount' => '__A__']), 'add' => __('Add'),
        'view' => __('View'), 'order' => __('Order now'),
        'deliveryFee' => __('Delivery fee'), 'minOrder' => __('Minimum order for this zone: :amount', ['amount' => '__A__']),
        'chooseZone' => __('Choose your area'), 'enterAddress' => __('Write the delivery address.'),
        'phoneRequired' => __('Enter your mobile number so the restaurant can reach you.'),
        'payFromBalance' => __('Pay from my balance'), 'notEnoughBalance' => __('Not enough balance for this order.'),
        'contains' => __('Contains:'), 'spicy' => __('Spicy'),
        'myOrder' => __('My order'), 'yourOrder' => __('Your order'), 'waiterCount' => __(':lines items · :pieces pieces', ['lines' => '__L__', 'pieces' => '__Q__']), 'viewOrder' => __('View order'),
        'people' => __('How many are you?'), 'names' => __('Your names (optional)'), 'person' => __('Person :n', ['n' => '__N__']),
        'equal' => __('Equally'), 'byItems' => __('By what each one ordered'), 'whoOrdered' => __('Who ordered what?'),
        'whoHint' => __('Tap the people under each item. A shared item is divided between the people you pick.'),
        'unclaimed' => __(':n item(s) not given to anyone yet are shared by everyone.', ['n' => '__N__']),
        'eachPays' => __('Each one pays'), 'food' => __('Food'), 'billTotal' => __('Bill total'),
        'goalItemLeft' => __('Add :left more and get :reward free'), 'goalOffLeft' => __('Add :left more and get :reward off'),
        'goalItemDone' => __('Congrats! Your free :reward was added'), 'goalOffDone' => __('Congrats! :reward off your order'),
        'goalListDone' => __('You reached the goal! Tell the waiter about your :reward.'),
        'goalCoupon' => __('The gift does not combine with a coupon code.'), 'goalFree' => __('Free'),
        'couponLine' => __('Coupon :code', ['code' => '__C__']), 'couponApplied' => __('Coupon applied: you save :amount', ['amount' => '__A__']),
        'couponChecking' => __('Checking the code…'),
        'wayChips' => $wayChips, 'wayAsk' => __('How will you order?'), 'chooseWay' => __('Choose how you will order first.'),
        'noDeliveryHere' => __('The branch :branch does not deliver.', ['branch' => '__B__']),
        'noDeliveryTry' => __('The branch :branch does not deliver — try :other.', ['branch' => '__B__', 'other' => '__O__']),
        'switchBranch' => __('Switch to :branch', ['branch' => '__B__']), 'wayNotHere' => __('This branch does not offer this.'),
        'current' => __('Current'), 'nearest' => __('Nearest'), 'lastVisit' => __('Last visit'), 'noBranchMatch' => __('No branch matches your search.'),
        // open / closed labels of the branch rows only when the switcher is there (the header shows the state otherwise)
        'openNow' => $branchPicker ? __('Open now') : '', 'closedNow' => $branchPicker ? __('Closed now') : '', 'onMap' => __('Map'), 'km' => __(':n km', ['n' => '__N__']),
        'locating' => __('Finding your location…'), 'noLocation' => __('We could not get your location. Choose the branch from the list.'),
        'choose' => __('Choose'),
        'added' => __('Added to your order: :name', ['name' => '__N__']),
        'linkCopied' => __('Link copied'),
        'missing' => __('To send, add: :list', ['list' => '__L__']), 'missingName' => __('your name'), 'missingPhone' => __('your mobile'),
        'missingZone' => __('your area'), 'missingAddress' => __('the address'),
        'pinOutside' => __('The pinned location is outside our delivery areas.'), 'pinInZone' => __('Your pin is in :zone', ['zone' => '__Z__']),
        'pinChange' => __('Change my location on the map'), 'pinNoLocation' => __('We could not get your location. Drag the pin to your door.'),
        'pinMapFailed' => __('The map could not load. Write the address in detail instead.'),
        'missingMin' => __('Add :left more to reach the minimum order of your area (:min)', ['left' => '__A__', 'min' => '__M__']),
        'etaPickup' => __('Ready for pickup in about :n minutes', ['n' => '__N__']), 'etaDelivery' => __('At your door in about :n minutes', ['n' => '__N__']),
        'offersSaved' => __('You save :amount with the offers', ['amount' => '__A__']),
        'reordered' => __('Added :n item(s) from your order', ['n' => '__N__']),
        'reorderSkipped' => __(':n item(s) are not available now and were skipped', ['n' => '__N__']),
        'reorderNone' => __('The items of that order are not available now.'),
        'favOn' => __('Saved in your favourites'), 'favOff' => __('Removed from your favourites'),
        'favAdd' => __('Keep in my favourites'), 'favRemove' => __('Remove from my favourites'),
        'splitNote' => __('An estimate to help you split. The restaurant\'s receipt is the official bill; service and tax follow what each one ate.'),
    ];
@endphp
<script type="application/json" id="menu-i18n">{!! json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
