{{-- Bistro: name … gold price on one line, the description below in a calmer tone, a small photo when there is one. --}}
<button type="button" data-item="{{ $item->id }}"
    @class(['m-card flex w-full items-start gap-4 border-b py-4 text-start', $qaPad ?? '', 'opacity-50' => $item->is_sold_out])>
    @include('menu.items.photo', ['class' => 'size-16 shrink-0 rounded-xl'])
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2">
            <span class="text-lg font-semibold tracking-wide">{{ $item->displayName() }}</span>
            <span class="mb-1 flex-1 border-b border-dotted border-[#c9a45c]/50"></span>
            <span class="flex shrink-0 items-center gap-1.5">@include('menu.items.price')</span>
        </div>
        @include('menu.items.alt-name')
        @if ($d = tx($item, 'description'))<div class="m-muted mt-1 text-sm leading-relaxed">{{ $d }}</div>@endif
        @include('menu.items.tags')
    </div>
</button>
