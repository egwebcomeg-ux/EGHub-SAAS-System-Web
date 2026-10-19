{{--
    The heart of an item card (App\Support\MenuLayouts::favSpot): favourites live on this phone only (localStorage,
    resources/js/menu.js), so the page always starts with it off and the script presses the saved ones.
--}}
<button type="button" class="m-fav absolute grid size-8 place-items-center rounded-full {{ $spot }}" data-fav="{{ $item->id }}" aria-pressed="false"
    aria-label="{{ __('Keep :name in my favourites', ['name' => $item->displayName()]) }}">
    <svg viewBox="0 0 24 24" class="size-[60%]" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" aria-hidden="true"><path d="M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7a4.3 4.3 0 0 1 7.5 2.8C19.5 15.4 12 20 12 20Z"/></svg>
</button>
