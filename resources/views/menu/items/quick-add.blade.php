{{--
    The quick "+" of an orderable item (App\Support\MenuLayouts::quickAdd / quickSpot): $qa = add (one tap adds one
    to the cart) or sheet (a size or a required choice first: the tap opens the item sheet). Sits next to the item
    button, never inside it; resources/js/menu.js hides every one while the cart mode cannot order.
--}}
@php
    $spotClass = match ($spot) {
        'top' => 'top-2 end-2 size-9',
        'photo' => 'top-[4.25rem] end-1.5 size-9',
        'photo-sm' => 'top-[2.85rem] end-1.5 size-7',
        'bold' => 'bottom-3 start-3 size-11',
        default => 'top-1/2 end-1 size-9 -translate-y-1/2',
    };
@endphp
<button type="button" class="m-qa m-primary absolute grid place-items-center rounded-full {{ $spotClass }}" data-quick-add="{{ $qa }}" data-quick-item="{{ $item->id }}"
    aria-label="{{ __('Add :name to the order', ['name' => $item->displayName()]) }}">
    <svg viewBox="0 0 24 24" class="size-[55%]" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</button>
