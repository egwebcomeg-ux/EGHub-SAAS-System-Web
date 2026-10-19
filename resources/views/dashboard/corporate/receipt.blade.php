<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Corporate voucher') }} · {{ $contract->name }}</title>
    @fonts
    {{-- One voucher per 80mm receipt (the printer cuts between them). --}}
    <style>
        @include('partials.receipt-80mm-style')
        .slip { break-after: page; page-break-after: always; }
        .slip:last-child { break-after: auto; page-break-after: auto; }
        .copy { display: inline-block; border: 2px solid #000; font-weight: 900; font-size: 16px; padding: 0 8px; margin: 4px 0; }
        .terms { font-size: 11px; text-align: center; }
        @media screen { .slip { border-bottom: 2px dashed #999; padding-bottom: 12px; margin-bottom: 16px; } }
    </style>
</head>
<body onload="window.print()">
    @php
        $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
        $currency = tr($settings['currency'], $settings['currency_en']);
    @endphp
    @foreach ($cards as $c)
        @php($v = $c['voucher'])
        <section class="slip" data-print-voucher="{{ $v->serial }}" @if ($c['copy']) data-copy @endif>
            @if ($settings['logo'])<div class="c"><img src="/storage/{{ $settings['logo'] }}" alt="" style="height:44px"></div>@endif
            <h1>{{ $brand }}</h1>
            @if ($c['copy'])<div class="c"><span class="copy">{{ __('Reprint copy') }}</span></div>@endif
            <hr>
            <div class="c" style="font-weight:800;font-size:15px">🏢 {{ __('Corporate voucher') }}</div>
            <div class="c">{{ $contract->name }}</div>
            <div class="big">{{ money($v->value) }} {{ $currency }}</div>
            <div class="code">{{ $v->formattedCode() }}</div>
            <div class="qr">{!! $c['qr'] !!}</div>
            <table>
                <tr><td>{{ __('Serial') }}</td><td class="n" dir="ltr">{{ $v->serial }}</td></tr>
                <tr><td>{{ __('Valid until') }}</td><td class="n">{{ local_time($v->expires_on, 'Y/m/d') }}</td></tr>
            </table>
            <div class="terms">
                {{ $contract->mode === 'single' ? __('One use: the whole value in one order') : __('Balance: what is left stays for next time') }}
                @if ($contract->windowLabel() !== '') · {{ $contract->windowLabel() }}@endif
                @if ($contract->min_order) · {{ __('Minimum order :amount', ['amount' => money($contract->min_order)]) }}@endif
            </div>
            <hr>
            <div class="c muted">{{ __('Scan to see the balance or keep it on your mobile.') }}</div>
            <div class="c muted" dir="ltr">{{ tenant()->url() }}</div>
        </section>
    @endforeach
</body>
</html>
