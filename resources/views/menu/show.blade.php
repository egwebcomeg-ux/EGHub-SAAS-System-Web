@extends('layouts.base', ['viteEntries' => ['resources/js/menu.js']])
@section('title', $profile->title())
@section('body_class', 'text-navy-900')

@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $currency = tr($settings['currency'], $settings['currency_en']);
    $featured = $categories->flatMap->items->where('is_featured', true)->take(8);
    $theme = \App\Services\Entitlements::current()->allows('menu_themes') && in_array($settings['menu_theme'], \App\Support\MenuThemes::keys(), true)
        ? $settings['menu_theme'] : 'classic';
    $layout = \App\Support\MenuThemes::layout($theme);
@endphp

@push('head')
@include('menu.partials.seo-head', ['profile' => $profile, 'schemas' => $schemas])
<meta name="theme-color" content="{{ $color('color_secondary', '#1E2D4F') }}">
@if ($settings['font'] !== 'Cairo')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ urlencode($settings['font']) }}:wght@400;600;700;800&display=swap">
@endif
<style>
    :root { --menu-primary: {{ $color('color_primary', '#E8642C') }}; --menu-secondary: {{ $color('color_secondary', '#1E2D4F') }}; --menu-bg: {{ $color('color_bg', '#FFFFFF') }}; }
    body { background: var(--menu-bg); font-family: '{{ $settings['font'] }}', 'Cairo', system-ui, sans-serif; }
    @include('menu.themes.styles', ['theme' => $theme])
    .m-primary { background: var(--menu-primary); color: #fff; }
    .m-primary-text { color: var(--menu-primary); }
    .m-secondary { background: var(--menu-secondary); color: #fff; }
</style>
@endpush

@section('body')
<header class="m-secondary relative">
    @if ($settings['cover'])
        <img src="/storage/{{ $settings['cover'] }}" alt="" class="h-40 w-full object-cover opacity-90 sm:h-56">
    @endif
    <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-4">
        @if ($settings['logo'])
            <img src="/storage/{{ $settings['logo'] }}" alt="{{ $brand }}" class="size-14 shrink-0 rounded-2xl bg-white object-contain p-1 {{ $settings['cover'] ? '-mt-10 shadow-lg' : '' }}">
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-xl font-black">{{ $brand }}</h1>
            @if ($t = tr($settings['tagline'], $settings['tagline_en']))<p class="truncate text-sm text-white/75">{{ $t }}</p>@endif
            <a href="{{ route('menu.about') }}" class="text-xs font-semibold text-white/80 underline underline-offset-2" data-about-link>{{ __('About the place') }}</a>
        </div>
        @if ($settings['english_enabled'] === '1')
            <a href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}" class="shrink-0 rounded-full border border-white/30 px-3 py-1.5 text-sm font-semibold">{{ app()->getLocale() === 'ar' ? 'EN' : 'ع' }}</a>
        @endif
    </div>
    @if ($table)
        <div class="mx-auto max-w-2xl px-4 pb-4">
            <span class="m-primary rounded-full px-3 py-1 text-sm font-bold">{{ __('Table :name', ['name' => $table->name]) }}@if ($branches->count() > 1) · {{ tr($branch->name, $branch->name_en) }}@endif</span>
        </div>
    @elseif ($branches->count() > 1)
        <div class="mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 pb-4">
            @foreach ($branches as $b)
                <a href="?branch={{ $b->slug }}" @class(['shrink-0 rounded-full px-3 py-1 text-sm font-semibold', 'm-primary' => $b->id === $branch->id, 'border border-white/30' => $b->id !== $branch->id])>{{ tr($b->name, $b->name_en) }}</a>
            @endforeach
        </div>
    @endif
</header>

@if ($categories->isNotEmpty())
    <nav class="sticky top-0 z-20 border-b border-black/5 bg-white/95 backdrop-blur" data-cat-nav>
        <div class="mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 py-3">
            @foreach ($categories as $c)
                <a href="#cat-{{ $c->id }}" data-cat-link="cat-{{ $c->id }}" class="shrink-0 rounded-full bg-navy-50 px-4 py-2 text-sm font-semibold transition">{{ tr($c->name, $c->name_en) }}</a>
            @endforeach
        </div>
        <div class="mx-auto max-w-2xl px-4 pb-3">
            <input type="search" class="field" placeholder="{{ __('Search the menu') }}" data-search>
        </div>
    </nav>
@endif

<main class="mx-auto max-w-2xl px-4 pt-5 pb-32">
    @foreach ($offers as $offer)
        <div class="mb-3 flex items-center gap-3 rounded-2xl bg-emerald-50 p-3 text-emerald-900 ring-1 ring-emerald-200">
            <span class="rounded-xl bg-emerald-600 px-2.5 py-1 text-sm font-extrabold text-white" dir="ltr">-{{ $offer->discount_percent }}%</span>
            <span class="text-sm font-semibold">{{ tr($offer->title, $offer->title_en) }}
                @if ($offer->ends_at)<span class="font-normal text-emerald-800">· {{ __('until :time', ['time' => local_time(today()->setTimeFromTimeString($offer->ends_at), 'h:i A')]) }}</span>@endif
            </span>
        </div>
    @endforeach
    @if ($featured->isNotEmpty())
        <section class="mb-6" data-featured>
            <h2 class="mb-3 text-lg font-extrabold">⭐ {{ __('Recommended') }}</h2>
            <div class="flex gap-3 overflow-x-auto pb-2">
                @foreach ($featured as $item)
                    <button type="button" data-item="{{ $item->id }}" class="w-36 shrink-0 overflow-hidden rounded-2xl border border-black/5 bg-white text-start shadow-sm">
                        @if ($item->image)<img src="/storage/{{ $item->image }}" alt="" loading="lazy" class="aspect-square w-full object-cover">@endif
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
            <h2 class="mb-1 text-xl font-extrabold" style="color: var(--menu-secondary)">{{ tr($c->name, $c->name_en) }}</h2>
            @if ($d = tr($c->description, $c->description_en))<p class="mb-3 text-sm text-navy-500">{{ $d }}</p>@endif
            <div @class(['mt-3', 'grid grid-cols-2 gap-3' => $layout === 'grid', 'divide-y divide-black/10' => $layout === 'minimal', 'space-y-3' => in_array($layout, ['classic', 'cafe'], true)])>
                @foreach ($c->items as $item)
                    @include('menu.items.' . $layout, ['item' => $item])
                @endforeach
            </div>
        </section>
    @empty
        <p class="py-16 text-center text-navy-500">{{ __('The menu is being prepared. Please check back soon.') }}</p>
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

    <p class="hidden py-10 text-center text-navy-500" data-no-results>{{ __('No items match your search.') }}</p>

    @if ($feedback)
        @include('partials.rate-us')
    @endif

    <footer class="mt-10 space-y-3 text-center text-sm text-navy-500">
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

{{-- Cart button --}}
<div class="fixed inset-x-0 bottom-0 z-30 hidden p-4" data-cart-bar>
    <button type="button" class="m-primary mx-auto flex min-h-14 w-full max-w-2xl items-center justify-between rounded-2xl px-5 font-bold shadow-xl" data-open-cart>
        <span><span class="me-2 rounded-full bg-white/25 px-2 py-0.5" data-cart-count>0</span>{{ $canOrder ? __('View order') : __('My list') }}</span>
        <span data-cart-total></span>
    </button>
</div>

{{-- Item sheet --}}
<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-item-sheet>
    <form method="dialog" class="max-h-[90vh] overflow-y-auto rounded-t-3xl bg-white sm:rounded-3xl" data-item-form>
        <img alt="" class="hidden aspect-video w-full object-cover" data-sheet-image>
        <div class="p-5">
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-xl font-extrabold" data-sheet-name></h3>
                <button value="cancel" class="text-2xl leading-none text-navy-500" aria-label="{{ __('Close') }}">×</button>
            </div>
            <p class="mt-1 text-navy-500" data-sheet-desc></p>
            <p class="mt-1 text-xs text-navy-500" data-sheet-cal></p>
            <div class="mt-3 hidden rounded-xl bg-brand-50 p-3 text-sm" data-sheet-combo></div>
            <div class="mt-4 space-y-5" data-sheet-groups></div>
            <label class="mt-5 block">
                <span class="field-label">{{ __('Notes for the kitchen') }}</span>
                <input class="field" maxlength="200" data-sheet-notes placeholder="{{ __('e.g. no sugar') }}">
            </label>
            <div class="mt-5 flex items-center gap-3">
                <div class="flex items-center rounded-xl border border-navy-100">
                    <button type="button" class="size-11 text-xl" data-qty="-1">−</button>
                    <span class="w-8 text-center font-bold" data-sheet-qty>1</span>
                    <button type="button" class="size-11 text-xl" data-qty="1">+</button>
                </div>
                <button type="button" class="m-primary min-h-12 flex-1 rounded-xl font-bold" data-add-to-cart>
                    {{ __('Add') }} · <span data-sheet-total></span>
                </button>
            </div>
            <p class="mt-3 hidden text-center font-bold text-red-600" data-sheet-soldout>{{ __('Sold out') }}</p>
        </div>
    </form>
</dialog>

{{-- "Goes well with" after adding an item --}}
<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/40 sm:m-auto sm:max-w-lg" data-pair-sheet>
    <form method="dialog" class="rounded-t-3xl bg-white p-5 sm:rounded-3xl">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-lg font-extrabold">{{ __('Goes well with') }}</h3>
            <button value="cancel" class="text-2xl leading-none text-navy-500" aria-label="{{ __('Close') }}">×</button>
        </div>
        <div class="space-y-2" data-pair-list></div>
        <button value="cancel" class="btn-ghost mt-4 w-full">{{ __('No, thanks') }}</button>
    </form>
</dialog>

{{-- Popup (offer / featured item) --}}
<dialog class="m-auto w-[min(26rem,92vw)] overflow-hidden rounded-3xl p-0 backdrop:bg-black/50" data-popup>
    <form method="dialog">
        <img alt="" class="hidden aspect-[4/3] w-full object-cover" data-popup-image>
        <div class="p-5 text-center">
            <h3 class="text-xl font-extrabold" data-popup-title></h3>
            <p class="mt-1 text-navy-500" data-popup-body></p>
            <div class="mt-4 grid gap-2">
                <button type="button" class="m-primary min-h-12 rounded-xl font-bold" data-popup-action></button>
                <button value="cancel" class="text-sm text-navy-500">{{ __('Close') }}</button>
            </div>
        </div>
    </form>
</dialog>

@if ($table && $canOrder)
    {{-- call the waiter / ask for the bill --}}
    <button type="button" class="fixed bottom-24 start-4 z-30 grid size-14 place-items-center rounded-full bg-white text-2xl shadow-lg ring-1 ring-black/5" data-call-open aria-label="{{ __('Call the waiter') }}">🛎</button>
    <dialog class="m-auto w-full max-w-xs rounded-3xl p-0 backdrop:bg-black/50" data-call-sheet>
        <form method="dialog" class="space-y-3 p-5 text-center">
            <h3 class="text-lg font-extrabold">{{ __('Table :name', ['name' => $table->name]) }}</h3>
            <button type="button" class="m-primary min-h-12 w-full rounded-2xl font-bold" data-call="waiter">🛎 {{ __('Call the waiter') }}</button>
            <button type="button" class="min-h-12 w-full rounded-2xl bg-emerald-600 font-bold text-white" data-call="bill">🧾 {{ __('The bill, please') }}</button>
            <p class="hidden text-sm font-semibold text-emerald-700" data-call-done></p>
            <button value="cancel" class="text-sm text-navy-500">{{ __('Close') }}</button>
        </form>
    </dialog>
@endif

{{-- Cart sheet --}}
<dialog class="m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-black/50 sm:m-auto sm:max-w-lg" data-cart-sheet>
    <form method="dialog" class="max-h-[90vh] overflow-y-auto rounded-t-3xl bg-white p-5 sm:rounded-3xl">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-xl font-extrabold">{{ $canOrder ? __('Your order') : __('My list') }}</h3>
            <button value="cancel" class="text-2xl leading-none text-navy-500" aria-label="{{ __('Close') }}">×</button>
        </div>
        @unless ($canOrder)
            <p class="mb-3 rounded-xl bg-navy-50 p-3 text-sm text-navy-700">{{ __('Show this list to the waiter. To order from your phone, scan the QR code on your table.') }}</p>
        @endunless
        <ul class="divide-y divide-navy-50" data-cart-lines></ul>
        <div class="mt-3 space-y-1 border-t border-navy-100 pt-3 text-sm" data-cart-summary></div>
        @if ($canOrder)
            @if ($online)
                {{-- ordering from the menu link: pickup or delivery --}}
                <div class="mt-4 space-y-3" data-online>
                    <div class="grid grid-cols-2 gap-2">
                        @if ($online['takeaway'])<button type="button" class="min-h-11 rounded-xl border border-navy-100 font-bold" data-otype="takeaway">🛍 {{ __('Pickup') }}</button>@endif
                        @if ($online['delivery'])<button type="button" class="min-h-11 rounded-xl border border-navy-100 font-bold" data-otype="delivery">🛵 {{ __('Delivery') }}</button>@endif
                    </div>
                    <div class="hidden space-y-3" data-delivery-fields>
                        <select class="field" data-zone>
                            <option value="">{{ __('Choose your area') }}</option>
                            @foreach ($online['zones'] as $z)<option value="{{ $z['id'] }}">{{ $z['name'] }} · {{ money($z['fee']) }} {{ tr($settings['currency'], $settings['currency_en']) }}</option>@endforeach
                        </select>
                        <textarea class="field min-h-16 py-2" maxlength="300" data-address placeholder="{{ __('Street, building, floor, apartment, landmark') }}"></textarea>
                    </div>
                    @if ($online['scheduled'])
                        <label class="block text-sm">
                            <span class="field-label">{{ __('When? (leave empty for as soon as possible)') }}</span>
                            <input class="field" type="datetime-local" data-scheduled dir="ltr">
                        </label>
                    @endif
                    <p class="text-xs text-navy-500">{{ __('Ready in about :n minutes. You pay on pickup or delivery.', ['n' => $online['prep']]) }}</p>
                </div>
            @endif
            <div class="mt-4 grid gap-3">
                <input class="field" maxlength="100" data-customer-name placeholder="{{ $online ? __('Your name') : __('Your name (optional)') }}">
                <input class="field" maxlength="20" inputmode="tel" dir="ltr" data-customer-phone placeholder="{{ $online ? __('Mobile number (required)') : __('Mobile (optional, for offers & loyalty)') }}">
                <textarea class="field min-h-16 py-2" maxlength="500" data-order-notes placeholder="{{ __('Notes for the order') }}"></textarea>
            </div>
            <p class="mt-3 hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-order-error></p>
            <button type="button" class="m-primary mt-4 min-h-14 w-full rounded-2xl text-lg font-bold" data-send-order>{{ __('Send order') }}</button>
        @endif
    </form>
</dialog>

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
    ];
@endphp
<script type="application/json" id="menu-i18n">{!! json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
