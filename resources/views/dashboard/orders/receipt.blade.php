<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>#{{ $order->number }}</title>
    @fonts
    {{-- 80mm thermal paper: no Tailwind, plain print CSS so it renders the same in every browser. --}}
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        body { width: 74mm; margin: 0 auto; font-family: 'Cairo', Tahoma, sans-serif; font-size: 13px; color: #000; }
        h1 { font-size: 16px; margin: 0; text-align: center; }
        .c { text-align: center; } .muted { color: #444; font-size: 11px; }
        .big { font-size: 34px; font-weight: 800; text-align: center; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        td.n { text-align: end; white-space: nowrap; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        .opt { font-size: 11px; color: #333; } .tot td { font-weight: 800; font-size: 15px; }
        @media screen { body { padding: 12px; background: #fff; } }
    </style>
</head>
<body onload="window.print()">
    @if ($kitchen)
        <h1>{{ __('Kitchen ticket') }}</h1>
    @else
        @if ($settings['logo'])<div class="c"><img src="/storage/{{ $settings['logo'] }}" alt="" style="height:48px"></div>@endif
        <h1>{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
        <div class="c muted">{{ tr($order->branch->name, $order->branch->name_en) }}@if ($order->branch->phone) · {{ $order->branch->phone }}@endif</div>
    @endif
    <div class="big">#{{ $order->number }}</div>
    @if ($order->offline_ref)<div class="c muted" data-receipt-offline>({{ __('was :ref', ['ref' => $order->offline_ref]) }})</div>@endif
    <div class="c">
        {{ \App\Models\Order::TYPES[$order->type][app()->getLocale() === 'en' ? 1 : 0] }}@if ($order->table) · {{ __('Table :name', ['name' => $order->table->name]) }}@endif
        <div class="muted">{{ local_time($order->created_at) }}@if ($order->terminal) · {{ $order->terminal->label() }}@endif</div>
        @if ($order->customer_name || $order->customer_phone)<div>{{ $order->customer_name }} {{ $order->customer_phone }}</div>@endif
        @if ($order->delivery_address)
            <div style="font-weight:700">{{ $order->delivery_address['zone'] ?? '' }}</div>
            <div>{{ $order->delivery_address['text'] ?? '' }}</div>
        @endif
    </div>
    <hr>
    <table>
        @foreach ($order->items as $i)
            <tr>
                <td><b>{{ $i->quantity }}×</b> {{ $i->name }}
                    @if ($i->options)<div class="opt">{{ collect($i->options)->pluck('name')->implode('، ') }}</div>@endif
                    @if ($i->notes)<div class="opt">⚠ {{ $i->notes }}</div>@endif
                </td>
                @unless ($kitchen)<td class="n">{{ money($i->line_total) }}</td>@endunless
            </tr>
        @endforeach
    </table>
    @if ($order->notes)<hr><div>⚠ {{ $order->notes }}</div>@endif
    @unless ($kitchen)
        <hr>
        <table>
            @if ($order->discount > 0 || $order->service > 0 || $order->tax > 0 || $order->delivery_fee > 0)<tr><td>{{ __('Subtotal') }}</td><td class="n">{{ money($order->subtotal) }}</td></tr>@endif
            @if ($order->coupon_code && $order->coupon_discount > 0)
                @if ($order->discount - $order->coupon_discount > 0.004)<tr><td>{{ __('Discount') }}</td><td class="n">−{{ money($order->discount - $order->coupon_discount) }}</td></tr>@endif
                <tr><td>{{ __('Voucher :code', ['code' => $order->coupon_code]) }}</td><td class="n">−{{ money($order->coupon_discount) }}</td></tr>
            @elseif ($order->discount > 0)
                <tr><td>{{ __('Discount') }}@if ($order->coupon_code) ({{ $order->coupon_code }})@endif</td><td class="n">−{{ money($order->discount) }}</td></tr>
            @endif
            @if ($order->service > 0)<tr><td>{{ __('Service') }}</td><td class="n">{{ money($order->service) }}</td></tr>@endif
            @if ($order->tax > 0)<tr><td>{{ __('Tax') }}</td><td class="n">{{ money($order->tax) }}</td></tr>@endif
            @if ($order->delivery_fee > 0)<tr><td>{{ __('Delivery fee') }}</td><td class="n">{{ money($order->delivery_fee) }}</td></tr>@endif
            <tr class="tot"><td>{{ __('Total') }}</td><td class="n">{{ money($order->total) }} {{ tr($settings['currency'], $settings['currency_en']) }}</td></tr>
            @if ($order->corporate_amount > 0)
                <tr data-receipt-corporate><td>{{ __('Corporate voucher') }}</td><td class="n">−{{ money($order->corporate_amount) }}</td></tr>
                @if ($order->total - $order->corporate_amount > 0.004)<tr><td>{{ __('Paid by :method', ['method' => \App\Billing\PaymentMethods::label($order->payment_method ?? 'cash')]) }}</td><td class="n">{{ money($order->total - $order->corporate_amount) }}</td></tr>@endif
            @endif
        </table>
        @if ($order->payment_status === 'paid' && is_array($order->payments) && (count($order->payments) > 1 || ! empty($order->payments[0]['tendered'])))
            {{-- how it was paid, line by line (split payment), and the change given back for cash --}}
            <table data-receipt-payments>
                @foreach ($order->payments as $p)
                    <tr><td>{{ \App\Billing\PaymentMethods::label($p['method']) }}</td><td class="n">{{ money($p['amount']) }}</td></tr>
                    @if (! empty($p['tendered']))<tr class="muted"><td>{{ __('Cash received') }} {{ money($p['tendered']) }}</td><td class="n">{{ __('Change') }} {{ money($p['change'] ?? 0) }}</td></tr>@endif
                @endforeach
            </table>
        @endif
        <div class="c muted" style="margin-top:6px">
            {{ $order->payment_status === 'paid' ? __('Paid') . ' · ' . \App\Billing\PaymentMethods::label($order->payment_method ?? 'cash') : __('Unpaid') }}
        </div>
        <hr>
        <div class="c">{{ __('Thank you for your visit!') }}</div>
        {{-- the same receipt on the guest's phone (signed link, nothing personal on it) --}}
        <div class="c" style="margin-top:4px" data-receipt-qr>{!! \App\Services\Qr::svg($order->receiptUrl(), 150) !!}</div>
        <div class="c muted">{{ __('Scan to keep this receipt on your phone') }}</div>
        <div class="c muted">{{ tenant()->url() }}</div>
    @endunless
</body>
</html>
