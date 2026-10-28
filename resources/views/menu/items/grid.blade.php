{{-- Grid: photo-first cards, two per row. With $big ("cards" layout): one wide photo card per row, with the description. --}}
@php
    $big = $big ?? false;
@endphp
<button type="button" data-item="{{ $item->id }}"
    @class(['m-card flex h-full w-full flex-col overflow-hidden rounded-2xl border m-border m-surface text-start shadow-sm transition active:scale-[.98]', 'opacity-60' => $item->is_sold_out])>
    @if ($item->image || ($placeholders ?? true))
        @include('menu.items.photo', $big ? ['class' => 'aspect-[16/9] w-full', 'iconClass' => 'size-[22%]'] : ['class' => 'aspect-square w-full', 'iconClass' => 'size-[34%]'])
    @elseif (! $big)
        <span class="flex aspect-[4/3] w-full items-center justify-center text-3xl font-extrabold" style="background: color-mix(in srgb, var(--menu-accent) 12%, transparent); color: var(--menu-accent)">{{ mb_substr($item->displayName(), 0, 1) }}</span>
    @endif
    <div @class(['flex flex-1 flex-col', $big ? 'p-4' : 'p-3', $big ? ($qaPad ?? '') : ''])>
        <div @class(['font-bold leading-snug', $big ? 'text-lg' : 'line-clamp-2'])>{{ $item->displayName() }}</div>
        @include('menu.items.alt-name', ['altClass' => $big ? '' : 'truncate'])
        @include('menu.items.tags')
        @if ($big && ($d = tx($item, 'description')))<div class="m-muted mt-1 line-clamp-3 text-sm">{{ $d }}</div>@endif
        <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-2 text-sm">@include('menu.items.price')</div>
    </div>
</button>
