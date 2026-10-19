{{--
    Bold cards ("كروت جريئة", App\Support\MenuLayouts): name, a short description and the price in an accent pill on the
    start side, a big photo (or the themed placeholder) on the end side. The round quick "+" sits next to the price
    pill (a sibling of this button, App\Support\MenuLayouts::quickSpot = bold); m-bold-room keeps its place.
--}}
<button type="button" data-item="{{ $item->id }}"
    @class(['m-card m-bold flex w-full items-stretch gap-3 rounded-3xl border m-border m-surface p-3 text-start transition active:scale-[.99]', 'opacity-60' => $item->is_sold_out])>
    <div class="flex min-w-0 flex-1 flex-col">
        <div class="text-lg leading-snug font-extrabold">{{ $item->displayName() }}</div>
        @if ($d = tx($item, 'description'))<div class="m-muted mt-0.5 line-clamp-2 text-sm">{{ $d }}</div>@endif
        @include('menu.items.tags')
        <div @class(['mt-auto flex flex-wrap items-center gap-2 pt-3', 'm-bold-room' => ($qaPad ?? '') === 'bold'])>
            <span class="m-bold-price" dir="auto">{{ money($item->price) }} {{ $currency }}</span>
            @if ($item->offer_from)<span class="m-muted text-sm line-through">{{ money($item->offer_from) }}</span><span class="badge bg-emerald-100 text-emerald-800" dir="ltr">-{{ $item->offer_percent }}%</span>@endif
            @if ($item->relationLoaded('comboParts') && $item->comboParts->isNotEmpty())<span class="badge bg-brand-100 text-brand-700">{{ __('Combo') }}</span>@endif
            @if ($item->is_sold_out)<span class="badge bg-red-100 text-red-700">{{ __('Sold out') }}</span>@endif
        </div>
    </div>
    @include('menu.items.photo', ['class' => 'size-28 shrink-0 rounded-2xl', 'iconClass' => 'size-[40%]'])
</button>
