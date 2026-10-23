@props(['tone' => 'dark'])
{{--
    The "by EgWeb" capsule: a rounded pill with the EgWeb logo, linking to egweb.com.eg. One look everywhere it shows
    (the site footer, the dashboard and admin sidebars). tone="dark" sits on a dark background (the white logo),
    tone="light" on a light one (the coloured logo).
--}}
@php($onDark = $tone !== 'light')
<a href="https://egweb.com.eg" target="_blank" rel="noopener" data-egweb-badge
   {{ $attributes->class([
       'inline-flex h-9 shrink-0 items-center gap-2 rounded-full border ps-3.5 pe-3 text-xs transition',
       'border-white/15 bg-white/5 text-white/70 hover:border-white/30 hover:bg-white/10 hover:text-white' => $onDark,
       'border-navy-200 bg-white text-navy-600 hover:border-navy-300 hover:bg-navy-50 hover:text-navy-900' => ! $onDark,
   ]) }}>
    <span>{{ __('by') }}</span>
    <img src="{{ versioned_asset($onDark ? 'img/brand/egweb-on-dark.webp' : 'img/brand/egweb-on-light.webp') }}" alt="EgWeb" width="68" height="22" class="h-[22px] w-auto" loading="lazy">
</a>
