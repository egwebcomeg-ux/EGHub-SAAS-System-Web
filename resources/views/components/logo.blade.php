@props(['size' => 32, 'text' => true, 'light' => false])
{{--
    EgMenu logo: the "egmenu" wordmark — "eg" orange, "menu" blue (white on dark backgrounds). Vector paths, so it is
    sharp at any size and needs no web font. :size is the visual height in px of the old mark, kept for callers; the
    wordmark is drawn a little shorter so it sits at the same weight in headers. :text="false" draws "eg" only.
    A logo uploaded in admin › الهوية والشكل (App\Support\PlatformBrand) replaces the wordmark: the light-background
    one, or on dark backgrounds (:light) the dark-background one when there is one. The drawn wordmark follows the
    platform brand colors (the defaults are the colors above).
--}}
@php
    $egColor = \App\Support\PlatformBrand::get('brand_color_primary') ?: '#F26522';
    $menuColor = $light ? '#ffffff' : (\App\Support\PlatformBrand::get('brand_color_secondary') ?: '#005BA6');
    $height = round($size * 0.95);
    $uploadedLogo = $text ? (($light ? \App\Support\PlatformBrand::url('brand_logo_on_dark') : null) ?? \App\Support\PlatformBrand::url('brand_logo')) : null;
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center leading-none']) }} dir="ltr">
    @if ($uploadedLogo)
        <img src="{{ $uploadedLogo }}" alt="{{ \App\Support\PlatformBrand::siteName() }}" style="height: {{ $height }}px" class="block w-auto max-w-[12rem] shrink-0 object-contain">
    @elseif ($text)
        <svg height="{{ $height }}" viewBox="0 20 610 140" class="block w-auto shrink-0" role="img" aria-label="egmenu">
            <g fill="{{ $egColor }}"><path transform="translate(0 0)" d="M85 64.5 C85 41.5 68 24 45 24 C20.5 24 4 42.5 4 67 C4 91.5 20.5 110 46 110 C65.5 110 79.5 98.5 83.5 83 L69 83 C66 91 57.5 97 46 97 C28.5 97 19 83.5 18 69.5 L84.5 69.5 C84.8 67.8 85 66.2 85 64.5 Z M18 57 C19.5 44 28.5 37 44.5 37 C60.5 37 69.5 44 71 57 L18 57 Z"/><path transform="translate(17 0)" d="M165 26 L151.5 26 L151.5 37.5 C145.5 28.5 134.5 24 121.5 24 C97 24 78 43 78 67 C78 91 97 110 121.5 110 C134.5 110 145.5 105.5 151.5 96.5 L151.5 115 C151.5 131.5 140 142 121.5 142 C107 142 96 135 91.5 124 L77.5 124 C82.5 142.5 99 155 121.5 155 C148 155 165.5 138.5 165.5 115 L165.5 26 Z M122 97 C104.5 97 92.5 83.5 92.5 67 C92.5 50.5 104.5 37 122 37 C139.5 37 151.5 50.5 151.5 67 C151.5 83.5 139.5 97 122 97 Z"/></g>
            <g fill="{{ $menuColor }}"><path transform="translate(31 0)" d="M178 26 L191.5 26 L191.5 38.5 C197.5 29 207.5 24 220 24 C231 24 240 28.5 245.5 37 C252 28.5 262.5 24 274.5 24 C293 24 305 35.5 305 57 L305 108 L291 108 L291 58.5 C291 43.5 282.5 36.5 271 36.5 C258.5 36.5 250 44.5 250 59 L250 108 L236 108 L236 58.5 C236 43.5 227.5 36.5 216 36.5 C203.5 36.5 192 44.5 192 59 L192 108 L178 108 L178 26 Z"/><path transform="translate(43 0)" d="M386 64.5 C386 41.5 369 24 346 24 C321.5 24 305 42.5 305 67 C305 91.5 321.5 110 347 110 C366.5 110 380.5 98.5 384.5 83 L370 83 C367 91 358.5 97 347 97 C329.5 97 320 83.5 319 69.5 L385.5 69.5 C385.8 67.8 386 66.2 386 64.5 Z M319 57 C320.5 44 329.5 37 345.5 37 C361.5 37 370.5 44 372 57 L319 57 Z"/><path transform="translate(43 0)" d="M399 26 L412.5 26 L412.5 38.5 C418.5 29 428.5 24 442 24 C461.5 24 473 36 473 57.5 L473 108 L459 108 L459 58.5 C459 44 450.5 36.5 438 36.5 C425 36.5 413 45 413 60 L413 108 L399 108 L399 26 Z"/><path transform="translate(43 0)" d="M486 26 L500 26 L500 76.5 C500 90.5 508.5 97.5 521 97.5 C534 97.5 546 89 546 74 L546 26 L560 26 L560 108 L546.5 108 L546.5 95.5 C540.5 105 530.5 110 517 110 C497.5 110 486 98 486 76.5 L486 26 Z"/></g>
        </svg>
    @else
        <svg height="{{ $height }}" viewBox="0 20 186 140" class="block w-auto shrink-0" role="img" aria-label="egmenu">
            <g fill="{{ $egColor }}"><path transform="translate(0 0)" d="M85 64.5 C85 41.5 68 24 45 24 C20.5 24 4 42.5 4 67 C4 91.5 20.5 110 46 110 C65.5 110 79.5 98.5 83.5 83 L69 83 C66 91 57.5 97 46 97 C28.5 97 19 83.5 18 69.5 L84.5 69.5 C84.8 67.8 85 66.2 85 64.5 Z M18 57 C19.5 44 28.5 37 44.5 37 C60.5 37 69.5 44 71 57 L18 57 Z"/><path transform="translate(17 0)" d="M165 26 L151.5 26 L151.5 37.5 C145.5 28.5 134.5 24 121.5 24 C97 24 78 43 78 67 C78 91 97 110 121.5 110 C134.5 110 145.5 105.5 151.5 96.5 L151.5 115 C151.5 131.5 140 142 121.5 142 C107 142 96 135 91.5 124 L77.5 124 C82.5 142.5 99 155 121.5 155 C148 155 165.5 138.5 165.5 115 L165.5 26 Z M122 97 C104.5 97 92.5 83.5 92.5 67 C92.5 50.5 104.5 37 122 37 C139.5 37 151.5 50.5 151.5 67 C151.5 83.5 139.5 97 122 97 Z"/></g>
        </svg>
    @endif
</span>
