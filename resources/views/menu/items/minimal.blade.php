{{-- Minimal: like a printed menu, name … price, with a small thumbnail (photo or placeholder) on the side. With $noPhoto ("text" layout): a price list without any image.
     An item sold in several sizes (weights) lists each size … price under its name, as a shop's price list does. --}}
@php
    $sizeGroup = $item->relationLoaded('optionGroups') ? $item->optionGroups->first(fn ($g) => $g->type === 'single' && $g->is_required && $g->options->isNotEmpty()) : null;
@endphp
<button type="button" data-item="{{ $item->id }}"
    @class(['flex w-full items-start gap-3 py-3 text-start', $qaPad ?? '', 'opacity-60' => $item->is_sold_out])>
    @unless ($noPhoto ?? false)
        @include('menu.items.photo', ['class' => 'size-12 shrink-0 rounded-lg'])
    @endunless
    <div class="min-w-0 flex-1">
    @if ($sizeGroup && ! $item->offer_from)
        <div class="font-bold">{{ $item->displayName() }}@if ($item->is_sold_out) <span class="badge bg-red-100 text-red-700">{{ __('Sold out') }}</span>@endif</div>
        <div class="mt-1 space-y-0.5 text-sm" data-item-sizes>
            @foreach ($sizeGroup->options as $size)
                <div class="flex items-baseline gap-2">
                    <span class="m-muted">{{ tx($size, 'name') }}</span>
                    <span class="mb-1 flex-1 border-b border-dotted border-current opacity-20"></span>
                    <span class="m-primary-text shrink-0 font-extrabold">{{ money($item->price + $size->price) }} {{ $currency }}</span>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex items-baseline gap-2">
            <span class="font-bold">{{ $item->displayName() }}</span>
            <span class="mb-1 flex-1 border-b border-dotted border-current opacity-30"></span>
            <span class="flex shrink-0 items-center gap-1.5">@include('menu.items.price')</span>
        </div>
    @endif
    @include('menu.items.alt-name')
    @if ($d = tx($item, 'description'))<div class="m-muted mt-0.5 text-sm">{{ $d }}</div>@endif
    @include('menu.items.tags')
    </div>
</button>
