<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Voucher receipt') }}</title>
    @fonts
    {{-- The guest's slip after vouchers were taken: 80mm, same print CSS as the other slips. --}}
    <style>
        @include('partials.receipt-80mm-style')
        .copy { display: inline-block; border: 2px solid #000; font-weight: 900; font-size: 16px; padding: 0 8px; margin: 4px 0; }
    </style>
</head>
<body onload="window.print()" data-redeem-receipt>
    @php
        $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
        $currency = tr($settings['currency'], $settings['currency_en']);
    @endphp
    @if ($settings['logo'])<div class="c"><img src="/storage/{{ $settings['logo'] }}" alt="" style="height:44px"></div>@endif
    <h1>{{ $brand }}</h1>
    <div class="c muted">{{ $s['branch'] ? tr($s['branch']->name, $s['branch']->name_en) : '' }} · {{ local_time($s['at']) }}</div>
    @if ($s['cancelled'])<div class="c"><span class="copy">{{ __('Cancelled') }}</span></div>@endif
    <hr>
    <div class="c" style="font-weight:800;font-size:15px">🏢 {{ __('Corporate vouchers taken') }}</div>
    <table>
        <tr><td>{{ $s['order'] ? __('Order #:n', ['n' => $s['order']->number]) : __('Paper bill') . ($s['reference'] ? ' ' . $s['reference'] : '') }}</td><td class="n">{{ money($s['bill']) }} {{ $currency }}</td></tr>
    </table>
    <hr>
    @foreach ($s['lines'] as $l)
        <table data-receipt-line="{{ $l['serial'] }}">
            <tr><td><b dir="ltr">{{ $l['serial'] }}</b> · {{ $l['company'] }}</td><td class="n">−{{ money($l['amount']) }}</td></tr>
            @if ($l['lost'] > 0)<tr><td class="muted">{{ __('One use: the rest is lost') }}</td><td class="n muted">{{ money($l['lost']) }}</td></tr>@endif
            <tr><td class="muted">{{ __('Balance left') }}@if ($l['balance'] > 0) · {{ __('Valid until :date', ['date' => $l['expires']]) }}@endif</td><td class="n muted">{{ money($l['balance']) }}</td></tr>
        </table>
    @endforeach
    <hr>
    <table>
        <tr><td><b>{{ __('Paid by vouchers') }}</b></td><td class="n"><b>{{ money($s['covered']) }} {{ $currency }}</b></td></tr>
        @if ($s['bill'] - $s['covered'] > 0.004)<tr><td>{{ __('Left to pay') }}</td><td class="n">{{ money($s['bill'] - $s['covered']) }} {{ $currency }}</td></tr>@endif
    </table>
    <hr>
    <div class="c muted">{{ __('Cashier') }}: {{ $s['cashier']?->name ?? '—' }}</div>
    <div class="c muted">{{ __('Thank you!') }}</div>
</body>
</html>
