@props(['size' => 32, 'text' => true, 'light' => false])
{{--
    EgMenu logo. Mark: the four corners of a phone camera's scan frame around a rounded "e" (scan the QR, get the
    menu). Wordmark: "egmenu" in Diamonds (falls back to Cairo until public/fonts/Diamonds-Regular.* is added),
    "eg" orange like EgWeb's logo, "menu" navy. Sizes scale from the mark so callers only pass :size.
--}}
@php
    $ink = $light ? '#ffffff' : '#1E2D4F';
    $accent = $light ? '#F07A2A' : '#E8642C';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-[0.3em] leading-none']) }} dir="ltr" style="font-size: {{ $size }}px">
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 48 48" class="shrink-0" aria-hidden="true">
        <path d="M3 15v-5a7 7 0 0 1 7-7h5M33 3h5a7 7 0 0 1 7 7v5M45 33v5a7 7 0 0 1-7 7h-5M15 45h-5a7 7 0 0 1-7-7v-5" fill="none" stroke="{{ $ink }}" stroke-width="4" stroke-linecap="round"/>
        <path d="M14.5 24h19a9.5 9.5 0 1 0-2.8 6.7" fill="none" stroke="{{ $accent }}" stroke-width="5" stroke-linecap="round"/>
    </svg>
    @if ($text)
        <span class="logo-wordmark text-[0.78em] {{ $light ? 'text-white' : 'text-navy-800' }}"><span style="color: {{ $accent }}">eg</span>menu</span>
    @endif
</span>
