{{-- Classic: text on the start side, photo on the end side. Also used by the dark theme and (compact) by the "list" layout.
     Without a photo (and no placeholder tile) the price sits on the name's line, at the end, like a printed menu. --}}
@php($withPhoto = $item->image || ($placeholders ?? true))
<button type="button" data-item="{{ $item->id }}"
    @class(['m-card flex w-full items-stretch rounded-2xl border m-border m-surface text-start shadow-sm transition active:scale-[.99]', ($compact ?? false) ? 'gap-3 p-2.5' : 'gap-3 p-3', ! $withPhoto ? 'min-h-14 items-center' : '', $qaPad ?? '', 'opacity-60' => $item->is_sold_out])>
    @if ($withPhoto)
        <div class="flex min-w-0 flex-1 flex-col">
            <div class="font-bold">{{ $item->displayName() }}</div>
            @include('menu.items.tags')
            @if ($d = tx($item, 'description'))<div class="m-muted mt-0.5 line-clamp-2 text-sm">{{ $d }}</div>@endif
            <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">@include('menu.items.price')</div>
        </div>
        @include('menu.items.photo', ['class' => ($compact ?? false) ? 'size-16 shrink-0 rounded-xl' : 'size-24 shrink-0 rounded-xl'])
    @else
        <div class="min-w-0 flex-1" data-item-row>
            <div class="flex items-baseline justify-between gap-3">
                <div class="min-w-0 font-bold">{{ $item->displayName() }}</div>
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5 text-end">@include('menu.items.price')</div>
            </div>
            @include('menu.items.tags')
            @if ($d = tx($item, 'description'))<div class="m-muted mt-0.5 line-clamp-2 text-sm">{{ $d }}</div>@endif
        </div>
    @endif
</button>
