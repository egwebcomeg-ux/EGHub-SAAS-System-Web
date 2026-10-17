@props(['size' => 32, 'text' => true, 'light' => false])
{{--
    EgMenu logo. Mark: a QR code reduced to its idea — three finder squares and, in the fourth corner, an orange tile
    with a fork and knife (scan the code, get the menu). Wordmark: "egmenu" in Diamonds (falls back to Cairo until
    public/fonts/Diamonds-Regular.* is added), "eg" orange like EgWeb's logo, "menu" navy. Vector, so it stays sharp
    from the 16px favicon to print. Sizes scale from the mark so callers only pass :size.
--}}
@php
    $ink = $light ? '#ffffff' : '#1E2D4F';
    $accent = $light ? '#F07A2A' : '#E8642C';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-[0.3em] leading-none']) }} dir="ltr" style="font-size: {{ $size }}px">
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 48 48" class="shrink-0" aria-hidden="true">
        <g fill="none" stroke="{{ $ink }}" stroke-width="3.5">
            <rect x="3" y="3" width="18" height="18" rx="5"/>
            <rect x="27" y="3" width="18" height="18" rx="5"/>
            <rect x="3" y="27" width="18" height="18" rx="5"/>
        </g>
        <g fill="{{ $ink }}">
            <rect x="9" y="9" width="6" height="6" rx="1.5"/>
            <rect x="33" y="9" width="6" height="6" rx="1.5"/>
            <rect x="9" y="33" width="6" height="6" rx="1.5"/>
        </g>
        <rect x="25.25" y="25.25" width="21.5" height="21.5" rx="6" fill="{{ $accent }}"/>
        <path d="M31.5 30v5M33.5 30v5M35.5 30v5M31.5 35c0 1.6.9 2.6 2 2.6s2-1 2-2.6M33.5 37.6V43M40.3 38V43" fill="none" stroke="#ffffff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M41.2 29.4c-2.5.9-3.5 3.7-3.5 7.5 0 .9.6 1.4 1.4 1.4h2.1z" fill="#ffffff"/>
    </svg>
    @if ($text)
        <span class="logo-wordmark text-[0.78em] {{ $light ? 'text-white' : 'text-navy-800' }}"><span style="color: {{ $accent }}">eg</span>menu</span>
    @endif
</span>
