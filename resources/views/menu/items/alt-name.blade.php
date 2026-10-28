{{--
    The second name of a menu row on the bilingual menu (setting menu_bilingual, App\Support\MenuBilingual): the other
    base language, smaller and muted, under the shown name. The outer line keeps the page's direction (so it lines up
    with the name above it), the inner one carries the text's own language and direction.
--}}
@if ($alt = \App\Support\MenuBilingual::text($altOf ?? $item))
    <span class="m-alt {{ $altClass ?? '' }}" data-alt-name><span lang="{{ \App\Support\MenuBilingual::lang() }}" dir="{{ \App\Support\MenuBilingual::dir() }}">{{ $alt }}</span></span>
@endif
