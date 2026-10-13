{{-- Minimal: like a printed menu, name … price, no photos. --}}
<button type="button" data-item="{{ $item->id }}" data-search-text="{{ mb_strtolower($item->name . ' ' . $item->name_en . ' ' . $item->description) }}"
    @class(['block w-full py-3 text-start', 'opacity-60' => $item->is_sold_out])>
    <div class="flex items-baseline gap-2">
        <span class="font-bold">{{ $item->displayName() }}</span>
        <span class="mb-1 flex-1 border-b border-dotted border-current opacity-30"></span>
        <span class="flex shrink-0 items-center gap-1.5">@include('menu.items.price')</span>
    </div>
    @if ($d = tr($item->description, $item->description_en))<div class="m-muted mt-0.5 text-sm text-navy-500">{{ $d }}</div>@endif
    @include('menu.items.tags')
</button>
