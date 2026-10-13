@extends('layouts.base', ['viteEntries' => ['resources/js/pos.js']])
@php
    $cc = ($mode ?? 'pos') === 'callcenter';
@endphp
@section('title', ($cc ? __('Call center') : __('POS')) . ' · ' . tenant()->name)
@section('body_class', 'bg-navy-50 text-navy-900 overflow-hidden')

@section('body')
<div class="flex h-dvh flex-col">
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
        <div class="ms-auto flex flex-wrap items-center gap-2">
            @if ($data['shifts'] && !$cc)
                <a href="{{ route('dash.shifts') }}" @class(['badge min-h-9 px-3', 'bg-emerald-100 text-emerald-800' => $shift, 'bg-red-100 text-red-700' => !$shift])>
                    {{ $shift ? __('Shift open since :time', ['time' => local_time($shift->opened_at, 'h:i A')]) : __('No open shift') }}
                </a>
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
        </section>

        {{-- ticket --}}
        <aside class="flex w-[22rem] shrink-0 flex-col border-s border-navy-100 bg-white">
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
                <div class="space-y-2 px-3 pb-2 text-sm" data-delivery-box>
                    <div class="flex flex-wrap gap-1" data-addresses></div>
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
            <ul class="flex-1 divide-y divide-navy-50 overflow-y-auto px-3" data-lines></ul>
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
                <div class="space-y-0.5" data-totals></div>
                @if ($cc)
                    <button type="button" class="btn-primary w-full" data-send>{{ __('Place order') }}</button>
                @else
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button type="button" class="btn-ghost" data-send>{{ __('Send to kitchen') }}</button>
                        <button type="button" class="btn-primary" data-pay>{{ __('Pay') }}</button>
                    </div>
                @endif
                <button type="button" class="w-full text-center text-xs text-red-600" data-clear>{{ __('Clear') }}</button>
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
        </div>
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

{{-- open orders --}}
<dialog class="m-auto w-full max-w-lg rounded-2xl p-0 backdrop:bg-black/50" data-open-dialog>
    <form method="dialog" class="p-5">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-lg font-extrabold">{{ __('Open orders') }}</h3>
            <button value="cancel" class="text-2xl leading-none text-navy-500">×</button>
        </div>
        <ul class="max-h-[60vh] divide-y divide-navy-50 overflow-y-auto" data-open-list></ul>
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
        'ordersCount' => __(':n previous orders', ['n' => '__N__']), 'minutes' => __(':n min', ['n' => '__N__']), 'placed' => __('Order placed'),
    ];
@endphp
<script type="application/json" id="pos-data">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script type="application/json" id="pos-i18n">{!! json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
