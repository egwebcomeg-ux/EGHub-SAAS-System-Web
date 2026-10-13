{{-- Food labels of an item (vegetarian, spicy…). --}}
@if ($tags = $item->tags())<div class="mt-1 flex flex-wrap gap-1">@foreach ($tags as $t)<span class="rounded-full bg-black/5 px-2 py-0.5 text-[11px] font-semibold" title="{{ $t['label'] }}">{{ $t['icon'] }} {{ $t['label'] }}</span>@endforeach</div>@endif
