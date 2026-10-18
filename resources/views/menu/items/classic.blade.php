{{-- Classic: text on the start side, photo on the end side. Also used by the dark theme and (compact) by the "list" layout. --}}
<button type="button" data-item="{{ $item->id }}"
    @class(['m-card flex w-full items-stretch rounded-2xl border m-border m-surface text-start shadow-sm transition active:scale-[.99]', ($compact ?? false) ? 'gap-3 p-2.5' : 'gap-3 p-3', $qaPad ?? '', 'opacity-60' => $item->is_sold_out])>
    <div class="flex min-w-0 flex-1 flex-col">
        <div class="font-bold">{{ $item->displayName() }}</div>
        @include('menu.items.tags')
        @if ($d = tx($item, 'description'))<div class="m-muted mt-0.5 line-clamp-2 text-sm">{{ $d }}</div>@endif
        <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">@include('menu.items.price')</div>
    </div>
    @include('menu.items.photo', ['class' => ($compact ?? false) ? 'size-16 shrink-0 rounded-xl' : 'size-24 shrink-0 rounded-xl'])
</button>
