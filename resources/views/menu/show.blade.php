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
    $waysMenu = ! $table && count($ways) > 1;
    $wayChips = ['dine' => '🍽 ' . __('In the place'), 'takeaway' => '🛍 ' . __('Takeaway'), 'delivery' => '🛵 ' . __('Delivery')];
    $logoClass = $logoTile ? 'rounded-2xl bg-white p-1 shadow-lg' : 'drop-shadow-[0_2px_6px_rgba(0,0,0,.35)]';
@endphp

@push('head')
@include('menu.partials.seo-head', ['profile' => $profile, 'schemas' => $schemas, 'share' => $share])
@include('menu.partials.pixels')
{{-- theme-color, manifest and the home-screen icon come from partials.pwa-head (layouts.base) --}}
@if ($settings['font'] !== 'Cairo')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ urlencode($settings['font']) }}:wght@400;600;700;800&display=swap">
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
    @media (prefers-reduced-motion: reduce) {
        .m-ticker-line, .m-toast, .m-dot span { transition: none; }
        .m-bump { animation: none; }
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
<header class="m-secondary relative overflow-hidden" data-menu-header>
    @if ($season && $season->decoration)
        <div class="m-season-deco" data-season-deco="{{ $season->pattern() }}" aria-hidden="true"></div>
    @endif
    @if ($settings['cover'])
        <img src="/storage/{{ $settings['cover'] }}" alt="" class="relative h-40 w-full object-cover opacity-90 sm:h-56" @if ($season?->banner) data-season-banner @endif>
    @endif
    <div class="relative mx-auto flex max-w-2xl items-center gap-3 px-4 py-4">
        @if ($settings['logo'])
            <img src="/storage/{{ $settings['logo'] }}" alt="{{ $brand }}" class="h-14 max-h-16 w-auto min-w-14 max-w-36 shrink-0 object-contain sm:h-16 {{ $logoClass }} {{ $settings['cover'] ? '-mt-10' : '' }}" data-logo data-logo-tile="{{ $logoTile ? 'white' : 'none' }}">
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-xl font-black">{{ $brand }}</h1>
            @if ($t = tr($settings['tagline'], $settings['tagline_en']))<p class="truncate text-sm text-white/75">{{ $t }}</p>@endif
            <a href="{{ route('menu.about') }}" class="text-xs font-semibold text-white/80 underline underline-offset-2" data-about-link>{{ __('About the place') }}</a>
        </div>
        @include('menu.partials.lang-switch')
        @if (! $table && \App\Services\Reservations\ReservationService::enabled())
            <a href="{{ route('menu.reserve', $branches->count() > 1 ? ['branch' => $branch->id] : []) }}" class="m-primary inline-flex min-h-11 shrink-0 items-center rounded-full px-3 text-sm font-bold shadow" data-reserve-link>{{ __('Book a table') }}</a>
        @endif
    </div>
    @if ($table || $waysMenu || $branchPicker)
        {{-- how the guest orders and from which branch: a scanned table fixes both --}}
        <div class="relative mx-auto flex max-w-2xl flex-wrap items-center gap-2 px-4 pb-3" data-header-chips>
            @if ($table)
                <span class="m-primary inline-flex min-h-9 items-center rounded-full px-3 text-sm font-bold" data-table-chip>🍽 {{ __('Table :name', ['name' => $table->name]) }}@if ($branches->count() > 1) · {{ tr($branch->name, $branch->name_en) }}@endif</span>
            @else
                @if ($waysMenu)
                    <button type="button" class="m-chip" data-way-chip aria-label="{{ __('How will you order?') }}"><span data-way-chip-label>{{ $wayChips[$ways[0]] }}</span> <span aria-hidden="true">▾</span></button>
                @endif
                @if ($branchPicker)
                    <button type="button" class="m-chip" data-branch-open>📍 <span class="max-w-40 truncate">{{ tr($branch->name, $branch->name_en) }}</span> <span aria-hidden="true">▾</span></button>
                @endif
            @endif
        </div>
    @endif
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
                @endphp
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
    <nav class="sticky top-0 z-20 border-b m-border bg-white/95 backdrop-blur" data-cat-nav>
        <div class="m-noscroll mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 pt-3 pb-1" data-cat-chips>
            @foreach ($categories as $c)
                <a href="#cat-{{ $c->id }}" data-cat-link="cat-{{ $c->id }}" class="shrink-0 rounded-full m-surface-2 px-4 py-2 text-sm font-semibold transition">{{ tx($c, 'name') }}</a>
            @endforeach
        </div>
        <div class="mx-auto max-w-2xl px-4 pt-2.5 pb-3">
            <input type="search" class="field" placeholder="{{ __('Search the menu') }}" data-search>
        </div>
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
<main @class(['mx-auto max-w-2xl px-4 pt-5 pb-32', 'm-quick-off' => ! $quickOn]) data-menu-main>
    @if ($featured->isNotEmpty())
        <section class="mb-6" data-featured>
            <h2 class="mb-3 text-lg font-extrabold">⭐ {{ __('Recommended') }}</h2>
            <div class="m-noscroll flex gap-3 overflow-x-auto pb-2">
                @foreach ($featured as $item)
                    <button type="button" data-item="{{ $item->id }}" class="w-36 shrink-0 overflow-hidden rounded-2xl border m-border m-surface text-start shadow-sm">
                        @include('menu.items.photo', ['class' => 'aspect-square w-full', 'iconClass' => 'size-[38%]', 'phIcon' => $settings['business_type'] === 'cafe' ? 'cup' : 'plate'])
                        <div class="p-2">
                            <div class="line-clamp-2 text-sm font-bold">{{ $item->displayName() }}</div>
                            <div class="m-primary-text text-sm font-extrabold">{{ money($item->price) }} {{ $currency }}</div>
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
                $phIcon = \App\Support\ItemPlaceholder::icon($c->name . ' ' . $c->name_en, $settings['business_type']);
            @endphp
            <div class="mt-3 {{ $layoutView['wrapper'] }}" data-layout="{{ $catLayout }}">
                @foreach ($c->items as $item)
                    @php
                        $qa = $quickAdd ? \App\Support\MenuLayouts::quickAdd($item) : null;
                        $qaSpot = $qa ? \App\Support\MenuLayouts::quickSpot($catLayout, $item, $placeholders) : null;
                    @endphp
                    <div class="relative" data-search-text="{{ mb_strtolower($item->name . ' ' . $item->name_en . ' ' . $item->description . ' ' . $item->displayName()) }}" data-badges="{{ implode(' ', $item->badgeKeys()) }}">
                        @include('menu.items.' . $layoutView['blade'], $layoutView['vars'] + ['item' => $item, 'phIcon' => $phIcon, 'qaPad' => $qaSpot === 'row' ? 'pe-12' : ''])
                        @if ($qa)
                            @include('menu.items.quick-add', ['item' => $item, 'qa' => $qa, 'spot' => $qaSpot])
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
        <div class="flex justify-center gap-4">
            @foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'] as $key => $label)
                @if ($settings[$key])<a href="{{ $settings[$key] }}" target="_blank" rel="noopener" class="font-semibold">{{ $label }}</a>@endif
            @endforeach
        </div>
        @if ($branch->address)<div>{{ $branch->address }}@if ($branch->working_hours) · {{ $branch->working_hours }}@endif</div>@endif
        @unless (\App\Services\Entitlements::current()->allows('hide_badge'))
            <a href="{{ platform_url(null) }}" class="inline-flex items-center gap-1 text-xs">{{ __('Powered by') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" /></a>
        @endunless
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
                <button type="button" class="pointer-events-auto grid size-11 place-items-center rounded-full bg-black/45 text-white backdrop-blur" aria-label="{{ __('Share this item') }}" data-sheet-share>
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/></svg>
                </button>
            </div>
        @endif
        <div @class(['relative', 'mx-auto max-w-2xl' => $pro]) data-sheet-media>
            <img alt="" @class(['hidden w-full object-cover', 'aspect-video' => ! $pro, 'aspect-[4/3] max-h-[55vh] sm:rounded-b-3xl' => $pro]) data-sheet-image>
            {{-- item video (uploads: muted loop inline, poster = photo; links: embed loaded on tap) --}}
            <div class="hidden aspect-video w-full bg-black" data-sheet-video></div>
            <button type="button" class="m-primary absolute bottom-3 start-3 hidden min-h-10 items-center gap-2 rounded-full px-4 text-sm font-extrabold shadow-lg" data-sheet-video-play>▶ {{ __('Watch the video') }}</button>
        </div>
        <div @class(['p-5', 'mx-auto max-w-2xl pb-28' => $pro]) data-sheet-body>
            <div class="flex items-start justify-between gap-3">
                <h3 id="sheet-name" @class(['font-extrabold', 'text-xl' => ! $pro, 'text-2xl' => $pro]) data-sheet-name></h3>
                @unless ($pro)
                    <button value="cancel" class="m-muted text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
                @endunless
            </div>
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

{{-- Cart sheet: an order (table / takeaway / delivery) or, in the hall, a list to show the waiter --}}
<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-cart-sheet>
    <form method="dialog" class="m-surface max-h-[90dvh] overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl" data-cart-panel>
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-xl font-extrabold" data-cart-title>{{ $data['mode'] === 'list' ? __('My order') : __('Your order') }}</h3>
            <button value="cancel" class="m-muted text-2xl leading-none" aria-label="{{ __('Close') }}">×</button>
        </div>
        @if (! $table && count($ways) > 1)
            {{-- how the guest orders (also chosen on the "how will you order?" screen) --}}
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
        <div class="m-border mt-3 space-y-1 border-t pt-3 text-sm" data-cart-summary></div>
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
                            <select class="field" data-zone>
                                <option value="">{{ __('Choose your area') }}</option>
                                @foreach ($online['zones'] as $z)<option value="{{ $z['id'] }}">{{ $z['name'] }} · {{ money($z['fee']) }} {{ tr($settings['currency'], $settings['currency_en']) }}</option>@endforeach
                            </select>
                            <textarea class="field min-h-16 py-2" maxlength="300" data-address placeholder="{{ __('Street, building, floor, apartment, landmark') }}"></textarea>
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
                        <p class="m-muted text-xs">{{ __('Ready in about :n minutes. You pay on pickup or delivery.', ['n' => $online['prep']]) }}</p>
                    </div>
                @endif
                <div class="mt-4 grid gap-3">
                    <input class="field" maxlength="100" data-customer-name placeholder="{{ $online ? __('Your name') : __('Your name (optional)') }}">
                    <input class="field" maxlength="20" inputmode="tel" dir="ltr" data-customer-phone placeholder="{{ $online ? __('Mobile number (required)') : __('Mobile (optional, for offers & loyalty)') }}">
                    {{-- optional extras folded away so the cart stays short: a coupon code and notes --}}
                    <details class="m-surface-2 rounded-xl px-3" data-order-extras>
                        <summary class="m-primary-text min-h-11 cursor-pointer content-center text-sm font-bold">+ {{ __('A coupon code or notes') }}</summary>
                        <div class="grid gap-3 pb-3">
                            @if (\App\Models\Coupon::query()->where('is_active', true)->exists())
                                <input class="field uppercase" maxlength="30" dir="ltr" autocomplete="off" data-coupon placeholder="{{ __('Coupon code (optional)') }}">
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
                <button type="button" class="m-primary mt-4 min-h-14 w-full rounded-2xl text-lg font-bold" data-send-order>{{ __('Send order') }}</button>
            </div>
        @endif
    </form>
</dialog>

@if ($hall['mode'] === 'list')
    {{-- the list in big type for the waiter (Desoky / Gold read-out) --}}
    <dialog class="m-surface m-0 h-dvh max-h-none w-full max-w-none p-0 backdrop:bg-black" data-waiter-view aria-label="{{ __('Show the waiter') }}">
        <div class="mx-auto flex min-h-full max-w-xl flex-col px-5 pt-6 pb-8">
            <div class="text-center">
                <div class="m-muted text-sm font-bold">{{ $brand }}</div>
                @if ($table)
                    <div class="m-primary mx-auto mt-2 inline-block rounded-2xl px-6 py-2 text-3xl font-black">{{ __('Table :name', ['name' => $table->name]) }}</div>
                @endif
                <div class="m-muted mt-2 text-xs" data-waiter-time></div>
            </div>
            <ul class="m-divide mt-5 flex-1 divide-y" data-waiter-lines></ul>
            <div class="m-border mt-4 border-t pt-3 text-lg" data-waiter-total></div>
            <div class="mt-6 grid gap-2">
                @if ($calls)
                    <button type="button" class="m-primary min-h-14 w-full rounded-2xl text-lg font-extrabold" data-call="waiter" data-hall-call>🛎 {{ __('Call the waiter') }}</button>
                @endif
                <button type="button" class="btn-ghost min-h-14 w-full text-lg" data-waiter-close>{{ __('Back') }}</button>
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
            if ($z['minutes']) {
                $facts[] = __('About :n min', ['n' => $z['minutes']]);
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
        'myOrder' => __('My order'), 'yourOrder' => __('Your order'), 'viewOrder' => __('View order'),
        'people' => __('How many are you?'), 'names' => __('Your names (optional)'), 'person' => __('Person :n', ['n' => '__N__']),
        'equal' => __('Equally'), 'byItems' => __('By what each one ordered'), 'whoOrdered' => __('Who ordered what?'),
        'whoHint' => __('Tap the people under each item. A shared item is divided between the people you pick.'),
        'unclaimed' => __(':n item(s) not given to anyone yet are shared by everyone.', ['n' => '__N__']),
        'eachPays' => __('Each one pays'), 'food' => __('Food'), 'billTotal' => __('Bill total'),
        'goalItemLeft' => __('Add :left more and get :reward free'), 'goalOffLeft' => __('Add :left more and get :reward off'),
        'goalItemDone' => __('Congrats! Your free :reward was added'), 'goalOffDone' => __('Congrats! :reward off your order'),
        'goalListDone' => __('You reached the goal! Tell the waiter about your :reward.'),
        'goalCoupon' => __('The gift does not combine with a coupon code.'), 'goalFree' => __('Free'),
        'wayChips' => $wayChips,
        'current' => __('Current'), 'nearest' => __('Nearest'), 'lastVisit' => __('Last visit'),
        // open / closed labels of the branch rows only when the switcher is there (the header shows the state otherwise)
        'openNow' => $branchPicker ? __('Open now') : '', 'closedNow' => $branchPicker ? __('Closed now') : '', 'onMap' => __('Map'), 'km' => __(':n km', ['n' => '__N__']),
        'locating' => __('Finding your location…'), 'noLocation' => __('We could not get your location. Choose the branch from the list.'),
        'choose' => __('Choose'),
        'added' => __('Added to your order: :name', ['name' => '__N__']),
        'linkCopied' => __('Link copied'),
        'splitNote' => __('An estimate to help you split. The restaurant\'s receipt is the official bill; service and tax follow what each one ate.'),
    ];
@endphp
<script type="application/json" id="menu-i18n">{!! json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
