{{-- Shown by the menu's service worker when there is no connection. Self-contained: no build files, they may not be cached yet. --}}
@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $brand = trim(tr($settings['brand_name'], $settings['brand_name_en'])) ?: tenant()->name;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('You are offline') }} · {{ $brand }}</title>
    <meta name="theme-color" content="{{ $color('color_secondary', '#0A2540') }}">
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: {{ $color('color_bg', '#FFFFFF') }}; color: #0A2540; font-family: 'Cairo', system-ui, -apple-system, sans-serif; text-align: center; padding: 24px; box-sizing: border-box; }
        .box { max-width: 24rem; }
        .mark { width: 72px; height: 72px; border-radius: 20px; background: {{ $color('color_secondary', '#0A2540') }}; display: grid; place-items: center; margin: 0 auto 16px; color: #fff; font-size: 32px; }
        h1 { font-size: 1.4rem; margin: 0 0 8px; }
        p { color: #5b6478; line-height: 1.6; margin: 0 0 20px; }
        a { display: inline-block; min-height: 44px; line-height: 44px; padding: 0 22px; border-radius: 14px; background: {{ $color('color_primary', '#F26522') }}; color: #fff; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <div class="mark" aria-hidden="true">📶</div>
        <h1>{{ __('You are offline') }}</h1>
        <p>{{ __('The menu of :name needs an internet connection the first time. Check your signal or Wi‑Fi and try again.', ['name' => $brand]) }}</p>
        <a href="{{ route('menu.show') }}">{{ __('Try again') }}</a>
    </div>
</body>
</html>
