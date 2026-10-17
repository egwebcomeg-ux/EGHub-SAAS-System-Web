@extends('layouts.base', ['viteEntries' => ['resources/js/kiosk.js']])
@section('title', $kiosk->name . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'overflow-hidden select-none bg-navy-50 text-navy-900')

@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $i18n = [
        'all' => __('All'), 'soldOut' => __('Sold out'), 'required' => __('Required'), 'pickRequired' => __('Please choose: :group', ['group' => '__G__']),
        'add' => __('Add to order'), 'emptyCart' => __('Your order is empty. Tap an item to add it.'), 'total' => __('Total'),
        'subtotal' => __('Subtotal'), 'service' => __('Service'), 'tax' => __('Tax'), 'nameFirst' => __('Write your name so we can call you.'),
        'phoneFirst' => __('Enter your mobile number.'), 'tableFirst' => __('Write your table number.'), 'error' => __('Something went wrong. Please try again.'),
        'back' => __('Back to idle in :n seconds', ['n' => '__N__']), 'items' => __(':n items', ['n' => '__N__']),
    ];
@endphp

@push('head')
<meta name="robots" content="noindex">
<style>
    :root { --k-primary: {{ $color('color_primary', '#F26522') }}; --k-secondary: {{ $color('color_secondary', '#0A2540') }}; }
    body { font-family: '{{ $settings['font'] }}', 'Cairo', system-ui, sans-serif; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
    .k-primary { background: var(--k-primary); color: #fff; }
    .k-primary-text { color: var(--k-primary); }
    .k-secondary { background: var(--k-secondary); color: #fff; }
    .k-ring { box-shadow: 0 0 0 4px var(--k-primary); }
    [data-screen] { display: none; }
    [data-screen].is-on { display: flex; }
    .k-pulse { animation: k-pulse 2.2s ease-in-out infinite; }
    @keyframes k-pulse { 50% { transform: scale(1.04); } }
    @media print {
        body * { visibility: hidden !important; }
        [data-ticket], [data-ticket] * { visibility: visible !important; }
        [data-ticket] { display: block !important; position: absolute; inset: 0 auto auto 0; width: 72mm; color: #000; }
    }
</style>
@endpush

@section('body')
{{-- idle: big start button; any touch starts --}}
<section class="k-secondary is-on h-dvh flex-col items-center justify-center gap-8 p-8 text-center" data-screen="idle">
    @if ($settings['logo'])
        <img src="/storage/{{ $settings['logo'] }}" alt="" class="size-40 rounded-[2rem] bg-white object-contain p-3 shadow-2xl">
    @endif
    <h1 class="text-5xl font-black">{{ $brand }}</h1>
    <p class="text-2xl text-white/80">{{ __('Order here and pay at the cashier') }}</p>
    <button type="button" class="k-primary k-pulse mt-4 min-h-24 rounded-[2rem] px-16 text-4xl font-black shadow-2xl" data-start>{{ __('Start your order') }}</button>
    @if ($settings['english_enabled'] === '1')
        <a href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}" class="rounded-full border border-white/30 px-6 py-3 text-xl font-bold">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</a>
    @endif
</section>

{{-- ordering --}}
<section class="h-dvh flex-col" data-screen="shop">
    <header class="k-secondary flex items-center gap-4 px-5 py-3">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 rounded-xl bg-white object-contain p-1">@endif
        <div class="text-2xl font-black">{{ $brand }}</div>
        <button type="button" class="ms-auto min-h-14 rounded-2xl border border-white/30 px-6 text-lg font-bold" data-restart>{{ __('Start over') }}</button>
    </header>
    <div class="flex min-h-0 flex-1">
        <nav class="w-44 shrink-0 space-y-2 overflow-y-auto border-e border-navy-100 bg-white p-3 lg:w-56" data-cats></nav>
        <main class="min-h-0 flex-1 overflow-y-auto p-4">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-4" data-items></div>
        </main>
    </div>
    <footer class="border-t border-navy-100 bg-white p-4">
        <button type="button" class="k-primary flex min-h-20 w-full items-center justify-between rounded-3xl px-8 text-2xl font-black shadow-lg disabled:opacity-40" data-open-cart disabled>
            <span><span class="me-3 rounded-full bg-white/25 px-4 py-1" data-cart-count>0</span>{{ __('View my order') }}</span>
            <span data-cart-total></span>
        </button>
    </footer>
</section>

{{-- order number --}}
<section class="h-dvh flex-col items-center justify-center gap-6 bg-white p-8 text-center" data-screen="done">
    <div class="text-3xl font-bold text-navy-500">{{ __('Your order number') }}</div>
    <div class="k-primary-text text-[10rem] leading-none font-black" data-done-number></div>
    <div class="text-3xl font-extrabold" data-done-name></div>
    <div class="k-secondary rounded-3xl px-10 py-6 text-4xl font-black">{{ __('Pay at the cashier') }}</div>
    <p class="text-xl text-navy-500">{{ __('We will call your number when your order is ready.') }}</p>
    <button type="button" class="k-primary mt-4 min-h-20 rounded-3xl px-16 text-3xl font-black" data-finish>{{ __('Done') }}</button>
    <p class="text-lg text-navy-400" data-done-countdown></p>
</section>

{{-- item sheet --}}
<dialog class="m-auto max-h-[92dvh] w-full max-w-2xl overflow-hidden rounded-[2rem] p-0 backdrop:bg-black/60" data-item-dialog>
    <div class="flex max-h-[92dvh] flex-col">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <img alt="" class="hidden aspect-[16/9] w-full object-cover" data-item-image>
            <div class="space-y-5 p-6">
                <div>
                    <h2 class="text-3xl font-black" data-item-name></h2>
                    <p class="mt-1 text-lg text-navy-500" data-item-description></p>
                </div>
                <div class="space-y-5" data-item-groups></div>
            </div>
        </div>
        <div class="flex items-center gap-4 border-t border-navy-100 p-5">
            <div class="flex items-center rounded-2xl border-2 border-navy-100">
                <button type="button" class="size-16 text-4xl font-bold" data-qty-step="-1">−</button>
                <b class="w-12 text-center text-3xl" data-qty>1</b>
                <button type="button" class="size-16 text-4xl font-bold" data-qty-step="1">+</button>
            </div>
            <button type="button" class="k-primary min-h-16 flex-1 rounded-2xl text-2xl font-black" data-item-add></button>
            <button type="button" class="min-h-16 rounded-2xl bg-navy-50 px-6 text-xl font-bold" data-close>✕</button>
        </div>
    </div>
</dialog>

{{-- cart drawer --}}
<dialog class="ms-auto me-0 my-0 h-dvh max-h-dvh w-full max-w-xl p-0 backdrop:bg-black/50" data-cart-dialog>
    <div class="flex h-full flex-col bg-white">
        <div class="flex items-center justify-between border-b border-navy-100 p-5">
            <h2 class="text-3xl font-black">{{ __('My order') }}</h2>
            <button type="button" class="min-h-14 rounded-2xl bg-navy-50 px-6 text-xl font-bold" data-close>✕</button>
        </div>
        <ul class="min-h-0 flex-1 divide-y divide-navy-100 overflow-y-auto px-5" data-lines></ul>
        <div class="space-y-4 border-t border-navy-100 p-5">
            <div class="space-y-1 text-lg" data-totals></div>
            <button type="button" class="k-primary min-h-20 w-full rounded-3xl text-2xl font-black" data-checkout>{{ __('Continue') }}</button>
        </div>
    </div>
</dialog>

{{-- name, order type, table --}}
<dialog class="m-auto w-full max-w-xl rounded-[2rem] p-0 backdrop:bg-black/60" data-checkout-dialog>
    <form class="space-y-5 p-6" data-checkout-form>
        <h2 class="text-3xl font-black">{{ __('Almost done') }}</h2>
        <div class="grid grid-cols-2 gap-3" data-types>
            @foreach ($kiosk->options()['order_types'] as $type)
                <button type="button" class="min-h-24 rounded-3xl border-2 border-navy-100 bg-navy-50 text-2xl font-black" data-type="{{ $type }}">
                    {{ $type === 'takeaway' ? __('Takeaway') : __('Eat here') }}
                </button>
            @endforeach
        </div>
        <label class="hidden space-y-2" data-table-box>
            <span class="text-lg font-bold">{{ __('Table number') }}</span>
            <input class="field min-h-16 text-2xl" data-table maxlength="20" inputmode="numeric" autocomplete="off">
        </label>
        <label class="block space-y-2">
            <span class="text-lg font-bold">{{ __('Your name') }}</span>
            <input class="field min-h-16 text-2xl" data-name maxlength="60" autocomplete="off" placeholder="{{ __('So we can call you when it is ready') }}">
        </label>
        @if ($kiosk->options()['require_phone'])
            <label class="block space-y-2">
                <span class="text-lg font-bold">{{ __('Mobile number') }}</span>
                <input class="field min-h-16 text-2xl" data-phone maxlength="20" dir="ltr" inputmode="tel" autocomplete="off" placeholder="01xxxxxxxxx">
            </label>
        @endif
        <p class="hidden rounded-2xl bg-red-50 p-4 text-lg font-semibold text-red-700" data-error></p>
        <div class="flex gap-3">
            <button type="button" class="min-h-20 rounded-3xl bg-navy-50 px-8 text-xl font-bold" data-close>{{ __('Back') }}</button>
            <button class="k-primary min-h-20 flex-1 rounded-3xl text-2xl font-black disabled:opacity-50" data-place>{{ __('Place my order') }}</button>
        </div>
    </form>
</dialog>

{{-- still there? --}}
<dialog class="m-auto w-full max-w-md rounded-[2rem] p-0 text-center backdrop:bg-black/60" data-idle-dialog>
    <div class="space-y-5 p-8">
        <div class="text-3xl font-black">{{ __('Are you still here?') }}</div>
        <div class="k-primary-text text-7xl font-black" data-idle-count></div>
        <button type="button" class="k-primary min-h-20 w-full rounded-3xl text-2xl font-black" data-still-here>{{ __('Yes, continue') }}</button>
    </div>
</dialog>

{{-- printed ticket (print_number) --}}
<div class="hidden p-2 text-center font-sans" data-ticket></div>

<script type="application/json" id="kiosk-data">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script type="application/json" id="kiosk-i18n">{!! json_encode($i18n + ['brand' => $brand, 'number' => __('Order'), 'payAtCashier' => __('Pay at the cashier')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
