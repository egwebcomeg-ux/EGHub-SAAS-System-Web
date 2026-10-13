<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'EgMenu')</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="@yield('body_class', 'bg-navy-50 text-navy-900') min-h-screen font-sans antialiased">
    @yield('body')
    @stack('scripts')
</body>
</html>
