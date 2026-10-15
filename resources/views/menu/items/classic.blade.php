{{-- Classic: text on the start side, photo on the end side. Also used by the dark theme. --}}
<button type="button" data-item="{{ $item->id }}" data-search-text="{{ mb_strtolower($item->name . ' ' . $item->name_en . ' ' . $item->description . ' ' . $item->displayName()) }}"
    @class(['m-card flex w-full items-stretch gap-3 rounded-2xl border border-black/5 bg-white p-3 text-start shadow-sm transition active:scale-[.99]', 'opacity-60' => $item->is_sold_out])>
    <div class="flex min-w-0 flex-1 flex-col">
        <div class="font-bold">{{ $item->displayName() }}</div>
        @include('menu.items.tags')
        @if ($d = tx($item, 'description'))<div class="m-muted mt-0.5 line-clamp-2 text-sm text-navy-500">{{ $d }}</div>@endif
        <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">@include('menu.items.price')</div>
    </div>
    @if ($item->image)
        <img src="/storage/{{ $item->image }}" alt="" loading="lazy" class="size-24 shrink-0 rounded-xl object-cover">
    @endif
</button>
