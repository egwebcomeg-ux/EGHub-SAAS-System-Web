<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Table :name', ['name' => $session->table?->name]) }}</title>
    @fonts
    {{-- One 80mm bill for every order of the table (same print CSS as the order receipt). --}}
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        body { width: 74mm; margin: 0 auto; font-family: 'Cairo', Tahoma, sans-serif; font-size: 13px; color: #000; }
        h1 { font-size: 16px; margin: 0; text-align: center; }
        .c { text-align: center; } .muted { color: #444; font-size: 11px; }
        .big { font-size: 26px; font-weight: 800; text-align: center; margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        td.n { text-align: end; white-space: nowrap; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        .opt { font-size: 11px; color: #333; } .tot td { font-weight: 800; font-size: 15px; }
        @media screen { body { padding: 12px; background: #fff; } }
    </style>
</head>
<body onload="window.print()">
    @if ($settings['logo'])<div class="c"><img src="/storage/{{ $settings['logo'] }}" alt="" style="height:48px"></div>@endif
    <h1>{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
    <div class="c muted">{{ tr($session->branch->name, $session->branch->name_en) }}@if ($session->branch->phone) · {{ $session->branch->phone }}@endif</div>
    <div class="big">{{ __('Table :name', ['name' => $session->table?->name]) }}</div>
    <div class="c">
        {{ __('Orders') }}: {{ $bill['orders']->map(fn ($o) => '#'.$o->number)->implode(' · ') }}
        <div class="muted">{{ local_time($session->opened_at) }}@if ($session->closed_at) → {{ local_time($session->closed_at, 'h:i A') }}@endif</div>
    </div>
    <hr>
    <table>
        @foreach ($bill['lines'] as $line)
            <tr>
                <td><b>{{ $line['qty'] }}×</b> {{ $line['name'] }}
                    @if ($line['options'])<div class="opt">{{ $line['options'] }}</div>@endif
                </td>
                <td class="n">{{ money($line['total']) }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <table>
        @if ($bill['discount'] > 0 || $bill['service'] > 0 || $bill['tax'] > 0)<tr><td>{{ __('Subtotal') }}</td><td class="n">{{ money($bill['subtotal']) }}</td></tr>@endif
        @if ($bill['discount'] > 0)<tr><td>{{ __('Discount') }}</td><td class="n">−{{ money($bill['discount']) }}</td></tr>@endif
        @if ($bill['service'] > 0)<tr><td>{{ __('Service') }}</td><td class="n">{{ money($bill['service']) }}</td></tr>@endif
        @if ($bill['tax'] > 0)<tr><td>{{ __('Tax') }}</td><td class="n">{{ money($bill['tax']) }}</td></tr>@endif
        <tr class="tot"><td>{{ __('Total') }}</td><td class="n">{{ money($bill['total']) }} {{ tr($settings['currency'], $settings['currency_en']) }}</td></tr>
        @if ($bill['due'] > 0.004 && $bill['paid'] > 0)
            <tr><td>{{ __('Paid') }}</td><td class="n">{{ money($bill['paid']) }}</td></tr>
            <tr><td>{{ __('Due') }}</td><td class="n">{{ money($bill['due']) }}</td></tr>
        @endif
    </table>
    <div class="c muted" style="margin-top:6px">
        @php($methods = $bill['orders']->where('payment_status', 'paid')->pluck('payment_method')->filter()->unique())
        {{ $bill['due'] > 0.004 ? __('Unpaid') : __('Paid') . ($methods->isNotEmpty() ? ' · ' . $methods->map(fn ($m) => \App\Billing\PaymentMethods::label($m))->implode(' + ') : '') }}
    </div>
    <hr>
    <div class="c">{{ __('Thank you for your visit!') }}</div>
    <div class="c muted">{{ tenant()->url() }}</div>
</body>
</html>
