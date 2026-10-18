{{--
    Theme tokens of every guest page of a restaurant (menu, order tracking, booking, card, wallet, gift, links, about).
    Components read the variables through the m-* classes below, so a dark template turns every sheet, input and
    button dark too. Include it in the head of each of those pages (never on the kiosk or TV screens).
--}}
@php
    $themeKey = $theme ?? \App\Support\MenuThemes::current($settings);
    $menuTheme = \App\Support\MenuThemes::tokens($themeKey, $settings);
    $themeVars = collect($menuTheme['vars'])->map(fn ($v, $k) => "$k: $v;")->implode(' ');
@endphp
<style>
    :root { {{ $themeVars }} @if ($menuTheme['dark']) color-scheme: dark; @endif }
    body { background: var(--menu-bg); color: var(--menu-text); }
    .m-surface { background-color: var(--menu-surface); color: var(--menu-text); }
    .m-surface-2 { background-color: var(--menu-surface-2); }
    .m-text { color: var(--menu-text); }
    .m-muted { color: var(--menu-muted); }
    .m-border { border-color: var(--menu-border); }
    .m-divide > :not(:last-child) { border-color: var(--menu-border); }
    .m-ring { --tw-ring-color: var(--menu-border); }
    .m-primary { background: var(--menu-accent); color: var(--menu-accent-text); }
    .m-primary-text { color: var(--menu-accent); }
    .m-primary-border { border-color: var(--menu-accent); }
    .m-secondary { background: var(--menu-secondary); color: #fff; }
    body .field { background: var(--menu-surface-2); color: var(--menu-text); border-color: var(--menu-border); }
    body .field::placeholder { color: var(--menu-muted); opacity: 1; }
    body .field:focus { border-color: var(--menu-accent); --tw-ring-color: color-mix(in srgb, var(--menu-accent) 30%, transparent); }
    body .field-label, body .section-title, body .page-title { color: var(--menu-text); }
    body .muted { color: var(--menu-muted); }
    body dialog { color: var(--menu-text); }
    body .card { background: var(--menu-surface); border-color: var(--menu-border); color: var(--menu-text); }
    body .btn-ghost { background: var(--menu-surface); color: var(--menu-text); border-color: var(--menu-border); }
    body .btn-primary { background: var(--menu-accent); background-image: none; color: var(--menu-accent-text); box-shadow: none; }
    body .badge-soft { background: var(--menu-surface-2); color: var(--menu-muted); }
    body input[type=checkbox], body input[type=radio] { accent-color: var(--menu-accent); }
    @if ($menuTheme['dark'])
        /* tinted notices (offers, warnings, errors, the accent badge) on dark templates */
        .bg-emerald-50, .bg-emerald-100 { background-color: rgba(16, 185, 129, .14) !important; }
        .text-emerald-900, .text-emerald-800, .text-emerald-700 { color: #6EE7B7 !important; }
        .ring-emerald-200 { --tw-ring-color: rgba(16, 185, 129, .35) !important; }
        .bg-amber-50, .bg-amber-100 { background-color: rgba(245, 158, 11, .14) !important; }
        .text-amber-900, .text-amber-800, .text-amber-700 { color: #FCD34D !important; }
        .ring-amber-200 { --tw-ring-color: rgba(245, 158, 11, .35) !important; }
        .bg-red-50, .bg-red-100 { background-color: rgba(239, 68, 68, .14) !important; }
        .text-red-700, .text-red-600 { color: #FCA5A5 !important; }
        .ring-red-200 { --tw-ring-color: rgba(239, 68, 68, .35) !important; }
        .bg-brand-50, .bg-brand-100 { background-color: color-mix(in srgb, var(--menu-accent) 16%, transparent) !important; }
        .text-brand-700 { color: var(--menu-accent) !important; }
    @endif
    @include('menu.themes.styles', ['theme' => $themeKey])
</style>
