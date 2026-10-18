@extends('layouts.base', ['viteEntries' => ['resources/js/pos.js']])
@php
    $cc = ($mode ?? 'pos') === 'callcenter';
@endphp
@section('title', ($cc ? __('Call center') : __('POS')) . ' · ' . tenant()->name)
@section('body_class', 'bg-navy-50 text-navy-900 overflow-hidden')

@section('body')
<div class="dash-app flex h-dvh flex-col">
    {{-- top bar --}}
    <header class="flex flex-wrap items-center gap-2 border-b border-navy-100 bg-white px-3 py-2">
        <a href="{{ route('dash.home') }}" class="btn-ghost min-h-9 px-3">← {{ __('Dashboard') }}</a>
        <div class="font-bold">{{ tenant()->name }} @if ($cc)<span class="badge bg-brand-100 text-brand-700">{{ __('Call center') }}</span>@endif</div>
        @if ($branches->count() > 1)
            <form class="ms-2">
                <select name="branch" class="field min-h-9 py-0" onchange="this.form.submit()">
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach
                </select>
            </form>
        @endif
        <div class="ms-auto flex flex-wrap items-center gap-2 whitespace-nowrap max-md:-mx-3 max-md:w-full max-md:flex-nowrap max-md:overflow-x-auto max-md:px-3 max-md:pb-1 max-md:[scrollbar-width:none] [&>*]:shrink-0">
            @if ($data['shifts'] && !$cc)
                <a href="{{ route('dash.shifts') }}" @class(['badge min-h-9 px-3', 'bg-emerald-100 text-emerald-800' => $shift, 'bg-amber-100 text-amber-800' => !$shift])>
                    {{ $shift ? __('Shift open since :time', ['time' => local_time($shift->opened_at, 'h:i A')]) : __('No open shift') }}
                </a>
            @endif
            @if (!$cc && !empty($data['walletManage']))
                <button type="button" class="btn-ghost min-h-9 px-3" data-topup-open>💳 {{ __('Top up balance') }}</button>
                <button type="button" class="btn-ghost min-h-9 px-3" data-gift-open>🎁 {{ __('Gift card') }}</button>
            @endif
            <button type="button" class="btn-ghost min-h-9 px-3" data-open-orders>{{ __('Open orders') }} <span class="badge bg-brand-600 text-white" data-open-count>0</span></button>
            <button type="button" @class(['btn-ghost min-h-9 px-3', 'hidden' => $cc]) data-customer-display title="{{ __('Opens a window to drag onto the screen facing the guest') }}">🖥 {{ __('Customer display') }}</button>
        </div>
    </header>

    @if ($needsShift)
        <div class="bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800">
            {{ __('Open a shift to take payments. You can still send orders to the kitchen.') }}
            <a href="{{ route('dash.shifts') }}" class="underline">{{ __('Open shift') }}</a>
        </div>
    @endif

    <div class="flex min-h-0 flex-1">
        {{-- menu --}}
        <section class="flex min-w-0 flex-1 flex-col">
            <div class="flex gap-2 overflow-x-auto border-b border-navy-100 bg-white px-3 py-2" data-cats></div>
            <div class="p-3"><input type="search" class="field" placeholder="{{ __('Search items') }}" data-search></div>
            <div class="grid flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto p-3 pt-0 sm:grid-cols-3 xl:grid-cols-5" data-items></div>
            {{-- phones: the order opens as a sheet from this bar --}}
            <div class="border-t border-navy-100 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:hidden" data-ticket-bar>
                <button type="button" class="btn-primary w-full justify-between" data-ticket-open aria-controls="pos-ticket" aria-expanded="false">
                    <span class="flex items-center gap-2">🧾 {{ __('The order') }} <span class="badge bg-white/20 text-white" data-ticket-count>0</span></span>
                    <span class="font-extrabold" data-ticket-total></span>
                </button>
            </div>
        </section>
        <div class="pos-ticket-backdrop fixed inset-0 z-30 bg-navy-900/50 md:hidden" data-ticket-backdrop aria-hidden="true"></div>

        {{-- ticket --}}
        <aside id="pos-ticket" class="pos-ticket flex w-[22rem] shrink-0 flex-col overflow-y-auto border-s border-navy-100 bg-white max-md:fixed max-md:inset-x-0 max-md:bottom-0 max-md:z-40 max-md:max-h-[88dvh] max-md:w-full max-md:rounded-t-3xl max-md:border-s-0 max-md:shadow-2xl lg:w-[24rem]" data-ticket>
            <div class="flex items-center justify-between border-b border-navy-100 px-4 py-2 md:hidden">
                <span class="font-extrabold">🧾 {{ __('The order') }}</span>
                <button type="button" class="grid size-11 place-items-center rounded-xl text-navy-600 hover:bg-navy-50" data-ticket-close aria-label="{{ __('Close') }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            @if ($cc)
                {{-- caller: lookup by mobile, saved addresses, last orders --}}
                <div class="space-y-2 border-b border-navy-100 p-3 text-sm">
                    <div class="flex gap-2">
                        <input class="field min-h-10 flex-1 text-lg" data-customer-phone placeholder="{{ __('Caller mobile') }}" maxlength="20" dir="ltr" inputmode="tel" autofocus>
                        <button type="button" class="btn-primary min-h-10 px-4" data-lookup>{{ __('Search') }}</button>
                    </div>
                    <div class="hidden rounded-lg bg-navy-50 p-2 text-xs" data-caller></div>
                    <input class="field min-h-9" data-customer-name placeholder="{{ __('Customer name') }}" maxlength="100">
                    <input class="field min-h-9" data-phone2 placeholder="{{ __('Another phone (optional)') }}" maxlength="20" dir="ltr">
                </div>
                <div class="grid grid-cols-2 gap-1 p-3 pb-2">
                    <button type="button" class="btn min-h-10 rounded-lg" data-type="delivery">{{ __('Delivery') }}</button>
                    <button type="button" class="btn min-h-10 rounded-lg" data-type="takeaway">{{ __('Pickup') }}</button>
                </div>
                @include('partials.leaflet')
                <div class="space-y-2 px-3 pb-2 text-sm" data-delivery-box>
                    <div class="flex flex-wrap gap-1" data-addresses></div>
                    <button type="button" class="btn-ghost min-h-9 w-full" data-map-toggle>📍 {{ __('Pin on the map') }} <span class="text-xs text-emerald-700" data-pinned></span></button>
                    <div class="hidden h-56 overflow-hidden rounded-xl border border-navy-100" dir="ltr" data-cc-map></div>
                    <select class="field min-h-9" data-zone>
                        <option value="">{{ __('Choose the delivery zone') }}</option>
                    </select>
                    <textarea class="field min-h-16" rows="2" data-address maxlength="300" placeholder="{{ __('Street, building, floor, apartment, landmark') }}"></textarea>
                    <input class="field min-h-9" data-address-label maxlength="40" placeholder="{{ __('Address name, e.g. Home or Work (optional)') }}">
                    <p class="hidden text-xs font-semibold text-amber-700" data-min-warning></p>
                </div>
            @else
                <div class="grid grid-cols-2 gap-1 p-3">
                    <button type="button" class="btn min-h-10 rounded-lg" data-type="takeaway">{{ __('Takeaway') }}</button>
                    <button type="button" class="btn min-h-10 rounded-lg" data-type="dine_in">{{ __('Dine-in') }}</button>
                </div>
            @endif
            <div class="hidden px-3 pb-2" data-table-box>
                <select class="field" data-table><option value="">{{ __('Choose a table') }}</option></select>
            </div>
            <ul class="min-h-24 flex-1 divide-y divide-navy-50 overflow-y-auto px-3" data-lines></ul>
            <div class="space-y-2 border-t border-navy-100 p-3 text-sm">
                @if ($cc)
                    <input class="field min-h-9" data-notes placeholder="{{ __('Notes for the order') }}" maxlength="500">
                @else
                    <details>
                        <summary class="cursor-pointer text-navy-500">{{ __('Customer & notes') }}</summary>
                        <div class="mt-2 space-y-2">
                            <input class="field min-h-9" data-customer-name placeholder="{{ __('Customer name') }}" maxlength="100">
                            <input class="field min-h-9" data-customer-phone placeholder="{{ __('Mobile') }}" maxlength="20" dir="ltr" inputmode="tel">
                            <input class="field min-h-9" data-notes placeholder="{{ __('Notes for the order') }}" maxlength="500">
                        </div>
                    </details>
                @endif
                @if ($data['canDiscount'])
                    <div class="flex items-center gap-2">
                        <span class="text-navy-500">{{ __('Discount') }}</span>
                        <input class="field min-h-9 w-24" type="number" min="0" step="0.5" data-discount value="0">
                        <select class="field min-h-9 w-20" data-discount-kind><option value="amount">{{ __('EGP') }}</option><option value="percent">%</option></select>
                    </div>
                @endif
                @if (! $cc && ! empty($data['coupons']))
                    <div class="space-y-1" data-coupon-box>
                        <div class="flex items-center gap-2">
                            <span class="text-navy-500">{{ __('Voucher') }}</span>
                            <input class="field min-h-9 flex-1 uppercase" data-coupon maxlength="30" dir="ltr" autocomplete="off" placeholder="{{ __('Voucher code') }}">
                            <button type="button" class="btn-ghost min-h-9 px-3" data-coupon-apply>{{ __('Apply') }}</button>
                        </div>
                        <p class="hidden text-xs" data-coupon-info></p>
                    </div>
                @endif
                <div class="space-y-0.5" data-totals></div>
                @if ($cc)
                    <button type="button" class="btn-primary w-full" data-send>{{ __('Place order') }}</button>
                @else
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button type="button" class="btn-ghost" data-send>{{ __('Send to kitchen') }}</button>
                        <button type="button" class="btn-primary" data-pay>{{ __('Pay') }}</button>
                    </div>
                @endif
                <button type="button" class="min-h-10 w-full text-center text-xs font-semibold text-red-600" data-clear>{{ __('Clear') }}</button>
            </div>
        </aside>
    </div>
</div>

{{-- options --}}
<dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-options-dialog>
    <form method="dialog" class="p-5">
        <h3 class="mb-3 text-lg font-extrabold" data-options-title></h3>
        <div class="space-y-4" data-options-body></div>
        <input class="field mt-4" data-line-notes placeholder="{{ __('Notes for the kitchen') }}" maxlength="200">
        <div class="mt-4 flex gap-2">
            <button value="cancel" class="btn-ghost flex-1">{{ __('Cancel') }}</button>
            <button type="button" class="btn-primary flex-1" data-options-add>{{ __('Add') }}</button>
        </div>
    </form>
</dialog>

{{-- payment --}}
<dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-pay-dialog>
    <form method="dialog" class="p-5">
        <h3 class="text-lg font-extrabold">{{ __('Payment') }}</h3>
        <div class="my-3 text-center text-4xl font-extrabold" data-pay-total></div>
        <div class="grid grid-cols-2 gap-2" data-methods>
            @foreach (['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')] as $key => $label)
                <button type="button" class="btn-ghost" data-method="{{ $key }}">{{ $label }}</button>
            @endforeach
            @if (!empty($data['wallet']))
                <button type="button" class="btn-ghost col-span-2" data-method="credit">💳 {{ __('From the guest\'s balance') }}</button>
            @endif
        </div>
        @if (!empty($data['wallet']))
            <div class="mt-4 hidden space-y-2" data-credit-box>
                <label class="field-label" for="credit-phone">{{ __('Guest mobile') }}</label>
                <div class="flex gap-2">
                    <input class="field flex-1 text-lg" id="credit-phone" maxlength="20" dir="ltr" inputmode="tel" data-credit-phone>
                    <button type="button" class="btn-ghost px-4" data-credit-lookup>{{ __('Search') }}</button>
                </div>
                <div class="rounded-xl bg-navy-50 p-3 text-sm font-semibold" data-credit-info>{{ __('Enter the guest\'s mobile to see the balance.') }}</div>
            </div>
        @endif
        <div class="mt-4 space-y-2" data-cash-box>
            <label class="field-label">{{ __('Cash received') }}</label>
            <input class="field text-lg" type="number" min="0" step="1" data-tendered>
            <div class="flex flex-wrap gap-2" data-quick></div>
            <div class="text-lg font-bold" data-change></div>
        </div>
        <p class="mt-3 hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-pay-error></p>
        <div class="mt-4 flex gap-2">
            <button value="cancel" class="btn-ghost flex-1">{{ __('Cancel') }}</button>
            <button type="button" class="btn-primary flex-1" data-pay-confirm>{{ __('Confirm payment') }}</button>
        </div>
    </form>
</dialog>

@if (!$cc && !empty($data['walletManage']))
    {{-- wallet top-up --}}
    <dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-topup-dialog>
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">{{ __('Top up balance') }}</h3>
            <div class="flex gap-2">
                <input class="field flex-1 text-lg" maxlength="20" dir="ltr" inputmode="tel" placeholder="{{ __('Guest mobile') }}" data-topup-phone>
                <button type="button" class="btn-ghost px-4" data-topup-lookup>{{ __('Search') }}</button>
            </div>
            <div class="hidden rounded-xl bg-navy-50 p-3 text-sm font-semibold" data-topup-info></div>
            <input class="field" maxlength="100" placeholder="{{ __('Customer name') }}" data-topup-name>
            <input class="field text-lg" type="number" min="1" step="1" placeholder="{{ __('Amount') }}" data-topup-amount>
            <div class="flex flex-wrap gap-2">
                @foreach ([100, 200, 500, 1000] as $v)<button type="button" class="btn-ghost min-h-9 px-3" data-topup-quick="{{ $v }}">{{ $v }}</button>@endforeach
            </div>
            <div class="grid grid-cols-2 gap-2" data-topup-methods>
                @foreach (['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')] as $key => $label)
                    <button type="button" class="btn-ghost" data-topup-method="{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-topup-error></p>
            <div class="hidden rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800" data-topup-done></div>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Close') }}</button>
                <button type="button" class="btn-primary flex-1" data-topup-confirm>{{ __('Add to balance') }}</button>
            </div>
        </form>
    </dialog>

    {{-- gift card sale --}}
    <dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-gift-dialog>
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">{{ __('Sell a gift card') }}</h3>
            <input class="field text-lg" type="number" min="1" step="1" placeholder="{{ __('Amount') }}" data-gift-amount>
            <div class="flex flex-wrap gap-2">
                @foreach ([100, 200, 300, 500] as $v)<button type="button" class="btn-ghost min-h-9 px-3" data-gift-quick="{{ $v }}">{{ $v }}</button>@endforeach
            </div>
            <input class="field" maxlength="20" dir="ltr" inputmode="tel" placeholder="{{ __('Recipient mobile (optional)') }}" data-gift-phone>
            <textarea class="field min-h-16 py-2" maxlength="200" placeholder="{{ __('Message on the card (optional)') }}" data-gift-message></textarea>
            <div class="grid grid-cols-2 gap-2">
                @foreach (['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')] as $key => $label)
                    <button type="button" class="btn-ghost" data-gift-method="{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-gift-error></p>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Close') }}</button>
                <button type="button" class="btn-primary flex-1" data-gift-confirm>{{ __('Sell & print') }}</button>
            </div>
        </form>
    </dialog>
@endif

{{-- open orders --}}
<dialog class="m-auto w-full max-w-lg rounded-2xl p-0 backdrop:bg-black/50" data-open-dialog>
    <form method="dialog" class="p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-lg font-extrabold">{{ __('Open orders') }}</h3>
            <button value="cancel" class="grid size-11 place-items-center rounded-xl text-2xl leading-none text-navy-500 hover:bg-navy-50" aria-label="{{ __('Close') }}">×</button>
        </div>
        <ul class="max-h-[60dvh] divide-y divide-navy-50 overflow-y-auto" data-open-list></ul>
    </form>
</dialog>

{{-- done --}}
<dialog class="m-auto w-full max-w-sm rounded-2xl p-0 text-center backdrop:bg-black/50" data-done-dialog>
    <form method="dialog" class="p-6">
        <div class="text-sm text-navy-500">{{ __('Order') }}</div>
        <div class="text-5xl font-extrabold" data-done-number></div>
        <div class="mt-2 font-semibold text-emerald-700" data-done-status></div>
        <div class="mt-5 grid grid-cols-2 gap-2">
            <button type="button" class="btn-ghost" data-print="customer">{{ __('Print receipt') }}</button>
            <button type="button" class="btn-ghost" data-print="kitchen">{{ __('Kitchen ticket') }}</button>
        </div>
        <button value="ok" class="btn-primary mt-3 w-full">{{ __('New order') }}</button>
    </form>
</dialog>

@php
    $i18n = [
        'total' => __('Total'), 'subtotal' => __('Subtotal'), 'service' => __('Service'), 'tax' => __('Tax'), 'discount' => __('Discount'),
        'all' => __('All'), 'empty' => __('Tap items to add them.'), 'required' => __('Required'), 'pickRequired' => __('Please choose: :group', ['group' => '__G__']),
        'chooseTable' => __('Choose a table.'), 'change' => __('Change: :amount', ['amount' => '__A__']), 'short' => __('Short by :amount', ['amount' => '__A__']),
        'sent' => __('Sent to the kitchen'), 'paid' => __('Paid'), 'pay' => __('Pay'), 'noOpen' => __('No open orders.'),
        'table' => __('Table :name', ['name' => '__N__']), 'takeaway' => __('Takeaway'), 'soldOut' => __('Sold out'), 'clearAsk' => __('Clear the order?'),
        'delivery' => __('Delivery'), 'deliveryFee' => __('Delivery fee'), 'phoneFirst' => __('Enter the caller mobile number.'),
        'chooseZone' => __('Choose the delivery zone.'), 'enterAddress' => __('Write the delivery address.'),
        'minOrder' => __('Minimum order for this zone: :amount', ['amount' => '__A__']), 'newCaller' => __('New customer, will be saved with the order.'),
        'outside' => __('This location is outside every delivery zone.'), 'pinned' => __('pinned'),
        'ordersCount' => __(':n previous orders', ['n' => '__N__']), 'minutes' => __(':n min', ['n' => '__N__']), 'placed' => __('Order placed'),
        'balance' => __('Balance: :amount', ['amount' => '__A__']), 'noWallet' => __('No wallet for this mobile number.'),
        'notEnough' => __('Not enough balance: the wallet has :balance.', ['balance' => '__A__']), 'newGuest' => __('New guest, the wallet is created with the top-up.'),
        'topupDone' => __('Added. New balance: :amount', ['amount' => '__A__']), 'printSlip' => __('Print slip'),
        'voucher' => __('Voucher :code', ['code' => '__C__']), 'voucherRemoved' => __('Voucher removed: the cart changed, apply it again.'),
        'chooseMethod' => __('Choose a payment method.'), 'enterAmount' => __('Enter an amount.'), 'enterPhone' => __('Enter the guest\'s mobile to see the balance.'),
    ];
@endphp
<script type="application/json" id="pos-data">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script type="application/json" id="pos-i18n">{!! json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
