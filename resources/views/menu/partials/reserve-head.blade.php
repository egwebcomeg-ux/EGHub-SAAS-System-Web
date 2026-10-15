@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
@endphp
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $color('color_secondary', '#1E2D4F') }}">
<style>
    :root { --menu-primary: {{ $color('color_primary', '#E8642C') }}; --menu-secondary: {{ $color('color_secondary', '#1E2D4F') }}; }
    .m-primary { background: var(--menu-primary); color: #fff; }
    .m-primary-text { color: var(--menu-primary); }
    .m-secondary { background: var(--menu-secondary); color: #fff; }
    .m-slot:has(input:checked) { background: var(--menu-primary); color: #fff; border-color: var(--menu-primary); }
</style>
