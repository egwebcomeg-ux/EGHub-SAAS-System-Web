{{-- Minimal: like a printed menu, name … price, with a small thumbnail (photo or placeholder) on the side. --}}
<button type="button" data-item="{{ $item->id }}" data-search-text="{{ mb_strtolower($item->name . ' ' . $item->name_en . ' ' . $item->description . ' ' . $item->displayName()) }}"
    @class(['flex w-full items-start gap-3 py-3 text-start', 'opacity-60' => $item->is_sold_out])>
    @include('menu.items.photo', ['class' => 'size-12 shrink-0 rounded-lg'])
    <div class="min-w-0 flex-1">
    <div class="flex items-baseline gap-2">
        <span class="font-bold">{{ $item->displayName() }}</span>
        <span class="mb-1 flex-1 border-b border-dotted border-current opacity-30"></span>
        <span class="flex shrink-0 items-center gap-1.5">@include('menu.items.price')</span>
    </div>
    @if ($d = tx($item, 'description'))<div class="m-muted mt-0.5 text-sm">{{ $d }}</div>@endif
    @include('menu.items.tags')
    </div>
</button>
