<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $card ? __('Gift card') . ' ' . $card->formattedCode() : __('Wallet top-up') }}</title>
    @fonts
    {{-- 80mm thermal paper, same plain print CSS as the order receipt. --}}
    <style>
        @include('partials.receipt-80mm-style')
        .msg { font-size: 14px; text-align: center; font-style: italic; margin: 6px 0; }
    </style>
</head>
<body onload="window.print()">
    @if ($settings['logo'])<div class="c"><img src="/storage/{{ $settings['logo'] }}" alt="" style="height:48px"></div>@endif
    <h1>{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
    <hr>
    @if ($card)
        <div class="c" style="font-weight:800;font-size:16px">🎁 {{ __('Gift card') }}</div>
        <div class="big">{{ money($card->amount) }} {{ tr($settings['currency'], $settings['currency_en']) }}</div>
        @if ($card->message)<div class="msg">“{{ $card->message }}”</div>@endif
        <div class="code" data-code>{{ $card->formattedCode() }}</div>
        <div class="qr">{!! $qr !!}</div>
        <div class="c">{{ __('Scan the code, enter your mobile and the amount is added to your balance. Pay with it on your next order.') }}</div>
        <div class="c muted" dir="ltr">{{ $url }}</div>
        <hr>
        <div class="c muted">
            @if ($card->expires_at){{ __('Valid until :date', ['date' => local_time($card->expires_at, 'Y/m/d')]) }} · @endif{{ local_time($card->created_at, 'Y/m/d h:i A') }}
        </div>
    @else
        <div class="c" style="font-weight:800;font-size:16px">{{ __('Wallet top-up') }}</div>
        <div class="c">{{ $tx->customer->name }} <span dir="ltr">{{ $tx->customer->phone }}</span></div>
        <hr>
        <table>
            <tr><td>{{ __('Amount added') }}</td><td class="n">{{ money($tx->delta) }}</td></tr>
            <tr><td>{{ __('Paid by') }}</td><td class="n">{{ \App\Billing\PaymentMethods::label($tx->method ?? 'cash') }}</td></tr>
            <tr style="font-weight:800;font-size:15px"><td>{{ __('Balance now') }}</td><td class="n">{{ money($tx->balance_after) }} {{ tr($settings['currency'], $settings['currency_en']) }}</td></tr>
        </table>
        <hr>
        <div class="qr">{!! $qr !!}</div>
        <div class="c">{{ __('Scan to see your balance any time.') }}</div>
        <div class="c muted">{{ local_time($tx->created_at, 'Y/m/d h:i A') }}</div>
    @endif
    <hr>
    <div class="c muted">{{ tenant()->url() }}</div>
</body>
</html>
