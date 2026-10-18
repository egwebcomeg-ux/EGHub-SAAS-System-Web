@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
@endphp
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $color('color_secondary', '#0A2540') }}">
@include('menu.partials.theme', ['settings' => $settings])
<style>
    .m-slot:has(input:checked) { background: var(--menu-accent); color: var(--menu-accent-text); border-color: var(--menu-accent); }
</style>
