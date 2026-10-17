<!doctype html>
<html lang="{{ \App\Support\MenuLocales::pageLocale() }}" dir="{{ \App\Support\MenuLocales::pageDir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'EgMenu')</title>
    <link rel="icon" href="{{ versioned_asset('favicon.svg') }}" type="image/svg+xml">
    @php
        $pwa = \App\Services\Pwa\Pwa::forRequest();
    @endphp
    @include('partials.pwa-head')
    @if (request()->attributes->get('canonical'))
        <link rel="canonical" href="{{ request()->attributes->get('canonical') }}">
    @endif
    @if (request()->attributes->get('noindex'))
        <meta name="robots" content="noindex">
    @endif
    @fonts
    @vite(array_merge(['resources/css/app.css', 'resources/js/app.js'], $viteEntries ?? []))
    @stack('head')
</head>
<body class="@yield('body_class', 'bg-navy-50 text-navy-900') min-h-screen font-sans antialiased">
    @yield('body')
    @include('partials.pwa-scripts')
    @stack('scripts')
</body>
</html>
