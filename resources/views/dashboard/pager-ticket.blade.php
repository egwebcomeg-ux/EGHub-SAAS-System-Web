<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>#{{ $number }}</title>
    @fonts
    {{-- The guest pager ticket on 80mm thermal paper: the number and the QR of the pager page, nothing else. --}}
    <style>
        @page { size: 80mm auto; margin: 3mm; }
        body { width: 74mm; margin: 0 auto; font-family: 'Cairo', Tahoma, sans-serif; font-size: 13px; color: #000; text-align: center; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #444; font-size: 11px; }
        .big { font-size: 46px; font-weight: 900; line-height: 1.1; margin: 4px 0; }
        .scan { font-size: 15px; font-weight: 800; margin-top: 4px; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        @media screen { body { padding: 12px; background: #fff; } }
    </style>
</head>
<body onload="window.print()" data-pager-ticket>
    <h1>{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
    @if ($branch)<div class="muted">{{ tr($branch->name, $branch->name_en) }}</div>@endif
    <hr>
    <div class="muted">{{ __('Order number') }}</div>
    <div class="big" dir="ltr">#{{ $number }}</div>
    <div class="scan">📣 {{ __('Scan so we can call you when your order is ready') }}</div>
    <div style="margin-top:4px">{!! \App\Services\Qr::svg($url, 200) !!}</div>
    <div class="muted">{{ __('Keep the page open on your phone. No app needed.') }}</div>
    <hr>
    <div class="muted">{{ local_time($at) }}</div>
</body>
</html>
