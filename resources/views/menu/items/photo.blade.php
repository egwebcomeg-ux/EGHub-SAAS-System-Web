{{-- The item photo, or (setting item_placeholder) a themed tile of the same size and radius with the category icon. --}}
@if ($item->image)
    <img src="/storage/{{ $item->image }}" alt="" loading="lazy" class="{{ $class }} object-cover">
@elseif ($placeholders ?? true)
    <span class="m-ph {{ $class }} grid place-items-center" data-placeholder="{{ $phIcon ?? 'plate' }}">{!! \App\Support\ItemPlaceholder::svg($phIcon ?? 'plate', $iconClass ?? 'size-[42%]') !!}</span>
@endif
