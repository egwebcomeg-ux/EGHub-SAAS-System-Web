{{-- Cozy cafe: round photo on the start side, name and a soft price pill. --}}
<button type="button" data-item="{{ $item->id }}" data-search-text="{{ mb_strtolower($item->name . ' ' . $item->name_en . ' ' . $item->description . ' ' . $item->displayName()) }}"
    @class(['m-card flex w-full items-center gap-4 rounded-3xl border p-3 text-start transition active:scale-[.99]', 'opacity-60' => $item->is_sold_out])>
    @if ($item->image)
        <img src="/storage/{{ $item->image }}" alt="" loading="lazy" class="size-20 shrink-0 rounded-full object-cover ring-4 ring-white">
    @else
        <span class="grid size-20 shrink-0 place-items-center rounded-full bg-[#ead9c6] text-2xl" aria-hidden="true">☕</span>
    @endif
    <div class="min-w-0 flex-1">
        <div class="text-lg font-bold">{{ $item->displayName() }}</div>
        @include('menu.items.tags')
        @if ($d = tx($item, 'description'))<div class="m-muted line-clamp-2 text-sm">{{ $d }}</div>@endif
        <div class="mt-2 flex flex-wrap items-center gap-2">@include('menu.items.price')</div>
    </div>
</button>
