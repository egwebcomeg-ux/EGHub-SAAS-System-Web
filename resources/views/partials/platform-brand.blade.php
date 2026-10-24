{{--
    The platform look chosen in admin › الهوية والشكل (App\Support\PlatformBrand), set by layouts.base: the uploaded
    favicon and the brand colors as CSS custom properties over the built Tailwind theme. Nothing is printed while the
    colors are the EgHub defaults. Guest menus (menu.*, path.*, custom.*) keep the restaurant's own colors.
--}}
@php
    $platformBrandCss = request()->routeIs('menu.*', 'path.*', 'custom.*') ? '' : \App\Support\PlatformBrand::cssVariables();
    $platformFavicon = \App\Support\PlatformBrand::url('brand_favicon');
@endphp
@if ($platformFavicon)
    <link rel="icon" href="{{ $platformFavicon }}" type="image/webp">
@else
    <link rel="icon" href="{{ versioned_asset('favicon.svg') }}" type="image/svg+xml">
@endif
@if ($platformBrandCss !== '')
    <style data-platform-brand>:root{ {{ $platformBrandCss }} }</style>
@endif
