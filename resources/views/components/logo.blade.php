@props(['size' => 32, 'text' => true, 'light' => false])
{{--
    EgHub logo: the "eghub" wordmark drawn after the EgWeb logo's typeface (Diamonds): true circular arcs on the font's
    own measurements, so the curves stay smooth at any size. Colours: "eg" orange, "hub" EgWeb navy (white on dark backgrounds). Vector paths, so it is sharp at any
    size and ships no font file. :size is the visual height in px of the earlier mark, kept for callers.
    :text="false" draws "eg" only.
    A logo uploaded in admin › الهوية والشكل (App\Support\PlatformBrand) replaces the wordmark: the light-background
    one, or on dark backgrounds (:light) the dark-background one when there is one. "eg" follows the platform's
    primary colour.
--}}
@php
    $egColor = \App\Support\PlatformBrand::get('brand_color_primary') ?: '#F26522';
    $hubColor = $light ? '#ffffff' : '#213368';
    $height = round($size * 0.95);
    // the typeface's x-height is short: same letter size as the earlier mark. The box has room above the ascenders so
    // the letters (not the tail of the g) sit on the header's centre line.
    $wordmarkHeight = round($height * 1.25 * 1100 / 940);
    $uploadedLogo = $text ? (($light ? \App\Support\PlatformBrand::url('brand_logo_on_dark') : null) ?? \App\Support\PlatformBrand::url('brand_logo')) : null;
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center leading-none']) }} dir="ltr">
    @if ($uploadedLogo)
        <img src="{{ $uploadedLogo }}" alt="{{ \App\Support\PlatformBrand::siteName() }}" style="height: {{ $height }}px" class="block w-auto max-w-[12rem] shrink-0 object-contain">
    @elseif ($text)
        <svg height="{{ $wordmarkHeight }}" viewBox="0 -880 2660 1100" class="block w-auto shrink-0" role="img" aria-label="EgHub">
            <g transform="scale(1 -1)"><g fill="{{ $egColor }}"><path fill-rule="evenodd" transform="translate(0 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 A250 250 0 0 1 459.7 86.0 L402.1 125.2 A181 181 0 0 0 93.6 213.9 L520.5 214 A250 250 0 0 1 271 500 Z M271 429 A179 179 0 0 0 446.6 284.8 L95.4 284.8 A179 179 0 0 0 271 429 Z"/><path fill-rule="evenodd" transform="translate(552 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 L443.7 0.2 A180 180 0 0 0 271 -129 L138 -129 L138 -199 L271 -199 A250 250 0 0 1 521 51 L521 250 A250 250 0 0 1 271 500 Z M450 250 L450 70 L271 70 A180 180 0 0 0 91 250 A180 180 0 0 0 271 429 A180 180 0 0 0 450 250 Z"/></g><g fill="{{ $hubColor }}"><path fill-rule="evenodd" transform="translate(1103 0)" d="M20 0 L20 700 L91 700 L91 426.1 A249 249 0 0 0 518 252 L518 0 L447 0 L447 252 A178 178 0 0 1 91 252 L91 0 Z"/><path fill-rule="evenodd" transform="translate(1651 0)" d="M20 500 L20 191 A202.5 191 0 0 1 222.5 0 A202.5 191 0 0 1 425 191 L425 500 L354 500 L354 194 A131 123 0 0 0 223 71 A131 123 0 0 0 92 194 L92 500 Z"/><path fill-rule="evenodd" transform="translate(2106 0)" d="M20 700 L90 700 L90 500 L270 500 A250 250 0 0 0 520 250 A250 250 0 0 0 270 0 A250 250 0 0 0 20 250 Z M90 250 L90 429.5 L270 429.5 A179.5 179.5 0 0 0 449.5 250 A179.5 179.5 0 0 0 270 70.5 A179.5 179.5 0 0 0 90 250 Z"/></g></g>
        </svg>
    @else
        <svg height="{{ $height }}" viewBox="0 -720 1060 940" class="block w-auto shrink-0" role="img" aria-label="EgHub">
            <g transform="scale(1 -1)" fill="{{ $egColor }}"><path fill-rule="evenodd" transform="translate(0 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 A250 250 0 0 1 459.7 86.0 L402.1 125.2 A181 181 0 0 0 93.6 213.9 L520.5 214 A250 250 0 0 1 271 500 Z M271 429 A179 179 0 0 0 446.6 284.8 L95.4 284.8 A179 179 0 0 0 271 429 Z"/><path fill-rule="evenodd" transform="translate(552 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 L443.7 0.2 A180 180 0 0 0 271 -129 L138 -129 L138 -199 L271 -199 A250 250 0 0 1 521 51 L521 250 A250 250 0 0 1 271 500 Z M450 250 L450 70 L271 70 A180 180 0 0 0 91 250 A180 180 0 0 0 271 429 A180 180 0 0 0 450 250 Z"/></g>
        </svg>
    @endif
</span>
