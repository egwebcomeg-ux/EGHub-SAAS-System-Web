<!doctype html>
<html lang="{{ \App\Support\MenuLocales::pageLocale() }}" dir="{{ \App\Support\MenuLocales::pageDir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (request()->attributes->get(\App\Services\PageCache::CANDIDATE))
        @include('partials.csrf-on-demand')
    @endif
    <title>@yield('title', \App\Support\PlatformBrand::siteName())</title>
    @php
        $pwa = \App\Services\Pwa\Pwa::forRequest();
    @endphp
    @include('partials.pwa-head')
    @if (request()->attributes->get('canonical'))
        <link rel="canonical" href="{{ request()->attributes->get('canonical') }}">
        {{-- a restaurant page shown in several languages (set by IdentifyTenantBySubdomain) --}}
        @foreach ((array) request()->attributes->get('alternates', []) as $alternateLang => $alternateUrl)
            <link rel="alternate" hreflang="{{ $alternateLang }}" href="{{ $alternateUrl }}">
        @endforeach
    @endif
    @if (request()->attributes->get('noindex'))
        <meta name="robots" content="noindex">
    @endif
    @fonts
    @php
        // inside the EgHub restaurant / waiter / driver apps the signed-in staff member's phone registers for push notifications
        $nativePush = auth()->check() && request()->getHost() === 'app.'.(config('tenancy.central_domains')[0] ?? 'localhost');
    @endphp
    @if ($nativePush)
        <meta name="eg-push-register" content="{{ route('dash.push.register') }}" data-unregister="{{ route('dash.push.unregister') }}" data-user="{{ auth()->id() }}">
    @endif
    @vite(array_merge(['resources/css/app.css'], $viteEntries ?? [], $nativePush ? ['resources/js/native-push.js'] : []))
    @include('partials.platform-brand')
    @stack('head')
</head>
<body class="@yield('body_class', 'bg-navy-50 text-navy-900') min-h-screen font-sans antialiased">
    @yield('body')
    @include('partials.support-access-banner')
    @include('partials.pwa-scripts')
    @stack('scripts')
</body>
</html>
