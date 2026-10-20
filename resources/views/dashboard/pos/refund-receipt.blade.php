<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Refund') }} · {{ $refund->order->reference() }}</title>
    @fonts
    <style>
        @include('partials.receipt-80mm-style')
    </style>
</head>
<body onload="window.print()" data-refund-receipt="{{ $refund->id }}">
    @php
        $currency = tr($settings['currency'], $settings['currency_en']);
        $methods = ['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet'), 'credit' => __('Wallet balance'), 'corporate' => __('Corporate voucher')];
    @endphp
    @if ($settings['logo'])<div class="c"><img src="/storage/{{ $settings['logo'] }}" alt="" style="height:44px"></div>@endif
    <h1>{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
    <div class="c muted">{{ tr($refund->branch->name, $refund->branch->name_en) }}</div>
    <hr>
    <div class="c" style="font-weight:800;font-size:15px">↩ {{ $refund->is_full ? __('Full refund') : __('Partial refund') }}</div>
    <div class="c">{{ __('Order') }} {{ $refund->order->reference() }}</div>
    <div class="big">−{{ money($refund->amount) }} {{ $currency }}</div>
    @if ($refund->lines)
        <table>
            @foreach ($refund->lines as $l)
                <tr><td><b>{{ $l['qty'] }}×</b> {{ $l['name'] }}</td><td class="n">@isset($l['amount']){{ money($l['amount']) }}@endisset</td></tr>
            @endforeach
        </table>
        <hr>
    @endif
    <table>
        @foreach ($refund->methods as $key => $amount)
            <tr><td>{{ __('Back by :method', ['method' => $methods[$key] ?? $key]) }}</td><td class="n">{{ money($amount) }}</td></tr>
        @endforeach
        <tr><td>{{ __('Reason') }}</td><td class="n">{{ $refund->reason }}</td></tr>
        <tr><td>{{ __('Approved by') }}</td><td class="n">{{ $refund->approver?->name }}</td></tr>
        <tr><td>{{ __('Cashier') }}</td><td class="n">{{ $refund->creator?->name }}@if ($refund->terminal) · {{ $refund->terminal->code }}@endif</td></tr>
    </table>
    <div class="c muted" style="margin-top:6px">{{ local_time($refund->created_at) }}</div>
    <hr>
    <div class="c muted">{{ __('Guest signature') }}: ____________</div>
</body>
</html>
