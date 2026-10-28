{{--
    The second name of a menu row on the bilingual menu (setting menu_bilingual, App\Support\MenuBilingual): the other
    base language, smaller and muted, under the shown name. The outer line keeps the page's direction (so it lines up
    with the name above it), the inner one carries the text's own language and direction.
--}}
@if ($alt = \App\Support\MenuBilingual::text($altOf ?? $item))
    {{-- a one-line name cuts at its own end: the ellipsis sits on the inner line, in the name's own direction --}}
    @php($altTruncate = str_contains($altClass ?? '', 'truncate'))
    <span class="m-alt {{ trim(str_replace('truncate', '', $altClass ?? '')) }}" data-alt-name><span{!! $altTruncate ? ' class="block truncate"' : '' !!} lang="{{ \App\Support\MenuBilingual::lang() }}" dir="{{ \App\Support\MenuBilingual::dir() }}">{{ $alt }}</span></span>
@endif
