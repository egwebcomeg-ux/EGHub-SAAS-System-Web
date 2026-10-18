{{-- Badges of an item (App\Support\ItemBadges: icon + word on a token pill, never a color alone) and its hotness. --}}
@php
    $spicy = $item->spicyIcons();
    // the hotness pill already says "spicy"
    $tags = array_values(array_filter($item->tags(), fn ($t) => ! ($spicy && $t['key'] === 'spicy')));
@endphp
@if ($tags || $spicy)
    <div class="mt-1 flex flex-wrap gap-1" data-item-badges>
        @if ($spicy)<span class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700" title="{{ __('Spicy') }}">{{ $spicy }}<span class="sr-only"> {{ __('Spicy') }}</span></span>@endif
        @foreach ($tags as $t)<span class="m-tag rounded-full px-2 py-0.5 text-[11px] font-semibold" title="{{ $t['label'] }}">{{ $t['icon'] }} {{ $t['label'] }}</span>@endforeach
    </div>
@endif
