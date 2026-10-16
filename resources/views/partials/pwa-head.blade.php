{{-- Installable-app tags of a restaurant menu or the dashboard ($pwa from App\Services\Pwa\Pwa::forRequest(), set by layouts.base). --}}
@if ($pwa)
    <link rel="manifest" href="{{ $pwa['manifest'] }}">
    <meta name="theme-color" content="{{ $pwa['theme'] }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $pwa['title'] }}">
    <link rel="apple-touch-icon" href="{{ $pwa['icon'] }}">
@endif
