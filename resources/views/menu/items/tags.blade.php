{{-- Food labels of an item (vegetarian, spicy…) and its hotness. --}}
@php
    $tags = $item->tags();
    $spicy = $item->spicyIcons();
@endphp
@if ($tags || $spicy)
    <div class="mt-1 flex flex-wrap gap-1">
        @if ($spicy)<span class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700" title="{{ __('Spicy') }}">{{ $spicy }}</span>@endif
        @foreach ($tags as $t)<span class="rounded-full bg-black/5 px-2 py-0.5 text-[11px] font-semibold" title="{{ $t['label'] }}">{{ $t['icon'] }} {{ $t['label'] }}</span>@endforeach
    </div>
@endif
