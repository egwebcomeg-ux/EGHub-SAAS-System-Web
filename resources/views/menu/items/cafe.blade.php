{{-- Cozy cafe: round photo on the start side, name and a soft price pill. Without a photo (and no placeholder
     tile) no empty circle: the price sits on the name's line, at the end. --}}
@php($withPhoto = $item->image || ($placeholders ?? true))
<button type="button" data-item="{{ $item->id }}"
    @class(['m-card flex w-full items-center gap-4 rounded-3xl border p-3 text-start transition active:scale-[.99]', $qaPad ?? '', 'opacity-60' => $item->is_sold_out])>
    @if ($withPhoto)
        @include('menu.items.photo', ['class' => 'size-20 shrink-0 rounded-full ring-4 ring-[var(--menu-surface)]'])
    @endif
    <div class="min-w-0 flex-1" @unless ($withPhoto) data-item-row @endunless>
        @if ($withPhoto)
            <div class="text-lg font-bold">{{ $item->displayName() }}</div>
        @else
            <div class="flex items-baseline justify-between gap-3">
                <div class="min-w-0 text-lg font-bold">{{ $item->displayName() }}</div>
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5 text-end">@include('menu.items.price')</div>
            </div>
        @endif
        @include('menu.items.tags')
        @if ($d = tx($item, 'description'))<div class="m-muted line-clamp-2 text-sm">{{ $d }}</div>@endif
        @if ($withPhoto)<div class="mt-2 flex flex-wrap items-center gap-2">@include('menu.items.price')</div>@endif
    </div>
</button>
