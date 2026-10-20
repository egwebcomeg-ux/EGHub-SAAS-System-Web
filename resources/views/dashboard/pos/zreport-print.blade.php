<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('End of day (Z)') }} · {{ $day->format('Y-m-d') }}</title>
    @fonts
    {{-- printed on the 80mm receipt printer, or on A4 for the files: plain print CSS (no Tailwind) --}}
    <style>
        @if ($paper === 'a4')
            @page { size: A4; margin: 14mm; }
            body { font-family: 'Cairo', Tahoma, sans-serif; font-size: 13px; color: #000; margin: 0; }
            h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 18px 0 6px; }
            table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #bbb; padding: 4px 6px; text-align: start; }
            td.n, th.n { text-align: end; white-space: nowrap; } .muted { color: #555; font-size: 12px; }
            .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; } hr { display: none; } .c { text-align: start; }
        @else
            @include('partials.receipt-80mm-style')
            h2 { font-size: 13px; margin: 8px 0 2px; text-align: center; } th { text-align: start; font-size: 11px; }
            .grid { display: block; }
        @endif
    </style>
</head>
<body onload="window.print()" data-z-paper="{{ $paper }}">
    @php
        $cur = tr($settings['currency'], $settings['currency_en']);
        $label = fn (string $m) => \App\Billing\PaymentMethods::label($m);
        $methods = array_unique(array_merge(array_keys($z['by_method']), array_keys($z['refunds']['by_method'])));
    @endphp
    <h1 class="c">{{ __('End of day (Z)') }}</h1>
    <div class="c">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }} · {{ tr($branch->name, $branch->name_en) }}@if ($terminal) · {{ $terminal->label() }}@endif</div>
    <div class="c muted">{{ $day->format('Y/m/d') }} · {{ __('Printed :time', ['time' => local_time(now(), 'h:i A')]) }} · {{ auth()->user()->name }}</div>
    <hr>
    <div class="grid">
        <div>
            <h2>{{ __('By payment method') }}</h2>
            <table>
                <tr><th>{{ __('Payment method') }}</th><th class="n">{{ __('Sales') }}</th><th class="n">{{ __('Refunds') }}</th><th class="n">{{ __('Net') }}</th></tr>
                @foreach ($methods as $m)
                    <tr><td>{{ $label($m) }}</td><td class="n">{{ money($z['by_method'][$m] ?? 0) }}</td><td class="n">{{ money($z['refunds']['by_method'][$m] ?? 0) }}</td><td class="n">{{ money($z['net_by_method'][$m] ?? 0) }}</td></tr>
                @endforeach
                <tr><td><b>{{ __('Total') }}</b></td><td class="n"><b>{{ money($z['gross']) }}</b></td><td class="n"><b>{{ money($z['refunds']['total']) }}</b></td><td class="n"><b>{{ money($z['net']) }} {{ $cur }}</b></td></tr>
            </table>
        </div>
        <div>
            <h2>{{ __('The day in figures') }}</h2>
            <table>
                <tr><td>{{ __('Paid orders') }}</td><td class="n">{{ $z['orders'] }}</td></tr>
                <tr><td>{{ __('Discounts') }}</td><td class="n">{{ money($z['discounts']) }}</td></tr>
                <tr><td>{{ __('Service') }}</td><td class="n">{{ money($z['service']) }}</td></tr>
                <tr><td>{{ __('Tax') }}</td><td class="n">{{ money($z['tax']) }}</td></tr>
                <tr><td>{{ __('Refunds') }} ({{ $z['refunds']['count'] }})</td><td class="n">{{ money($z['refunds']['total']) }}</td></tr>
                <tr><td>{{ __('Cancelled orders') }} ({{ $z['cancelled']['count'] }})</td><td class="n">{{ money($z['cancelled']['total']) }}</td></tr>
                <tr><td>{{ __('Not paid yet') }} ({{ $z['unpaid']['count'] }})</td><td class="n">{{ money($z['unpaid']['total']) }}</td></tr>
                <tr><td>{{ __('Cash in') }}</td><td class="n">{{ money($z['cash']['in']) }}</td></tr>
                <tr><td>{{ __('Cash out') }}</td><td class="n">{{ money($z['cash']['out']) }}</td></tr>
                <tr><td>{{ __('Of it expenses') }}</td><td class="n">{{ money($z['cash']['expenses']) }}</td></tr>
                <tr><td>{{ __('Offline sales synced') }}</td><td class="n">{{ $z['offline']['count'] }} · {{ money($z['offline']['total']) }}</td></tr>
            </table>
        </div>
    </div>
    <hr>
    <h2>{{ __('Shifts: expected and counted cash') }}</h2>
    <table>
        <tr><th>{{ __('Cashier') }}</th><th class="n">{{ __('Expected cash') }}</th><th class="n">{{ __('Counted') }}</th><th class="n">{{ __('Difference') }}</th></tr>
        @forelse ($z['shifts'] as $s)
            <tr><td>{{ $s['user'] }}@if ($s['terminal']) <span class="muted">{{ $s['terminal'] }}</span>@endif<div class="muted">{{ local_time($s['opened_at'], 'h:i A') }} – {{ $s['closed_at'] ? local_time($s['closed_at'], 'h:i A') : __('Still open') }}</div></td>
                <td class="n">{{ money($s['expected']) }}</td><td class="n">{{ $s['counted'] !== null ? money($s['counted']) : '—' }}</td><td class="n">{{ $s['difference'] !== null ? money($s['difference']) : '—' }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('No shifts this day.') }}</td></tr>
        @endforelse
    </table>
    <hr>
    <div class="c muted" style="margin-top:10px">{{ __('Signature') }}: ____________</div>
</body>
</html>
