{{-- Price line of a menu item: current price, offer, combo and sold-out badges. --}}
<span class="m-primary-text font-extrabold">{{ money($item->price) }} {{ $currency }}</span>
@if ($item->offer_from)<span class="m-muted text-sm line-through">{{ money($item->offer_from) }}</span><span class="badge bg-emerald-100 text-emerald-800" dir="ltr">-{{ $item->offer_percent }}%</span>@endif
@if ($item->relationLoaded('comboParts') && $item->comboParts->isNotEmpty())<span class="badge bg-brand-100 text-brand-700">{{ __('Combo') }}</span>@endif
@if ($item->is_sold_out)<span class="badge bg-red-100 text-red-700">{{ __('Sold out') }}</span>@endif
