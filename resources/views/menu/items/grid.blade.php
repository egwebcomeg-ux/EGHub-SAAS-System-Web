{{-- Grid: photo-first cards, two per row. --}}
<button type="button" data-item="{{ $item->id }}" data-search-text="{{ mb_strtolower($item->name . ' ' . $item->name_en . ' ' . $item->description) }}"
    @class(['m-card flex flex-col overflow-hidden rounded-2xl border border-black/5 bg-white text-start shadow-sm transition active:scale-[.98]', 'opacity-60' => $item->is_sold_out])>
    @if ($item->image)
        <img src="/storage/{{ $item->image }}" alt="" loading="lazy" class="aspect-square w-full object-cover">
    @else
        <span class="flex aspect-[4/3] w-full items-center justify-center text-3xl font-extrabold" style="background: color-mix(in srgb, var(--menu-primary) 12%, transparent); color: var(--menu-primary)">{{ mb_substr($item->displayName(), 0, 1) }}</span>
    @endif
    <div class="flex flex-1 flex-col p-3">
        <div class="line-clamp-2 font-bold leading-snug">{{ $item->displayName() }}</div>
        @include('menu.items.tags')
        <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-2 text-sm">@include('menu.items.price')</div>
    </div>
</button>
