{{-- A TV screen's device: «شغالة» / «آخر ظهور من …» / «مستنية الشاشة تفتح» / «مش متربطة». --}}
@php
    $st = $screen->status();
@endphp
<div class="flex flex-wrap items-center gap-2 text-sm" data-tv-status="{{ $st }}">
    @if ($st === 'online')
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 font-bold text-emerald-800"><span class="size-2 rounded-full bg-emerald-500"></span>{{ __('Playing now') }}</span>
        <span class="text-xs text-navy-500">{{ __('Checked in :time', ['time' => $screen->last_seen_at->diffForHumans()]) }}</span>
    @elseif ($st === 'offline')
        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 font-bold text-amber-800"><span class="size-2 rounded-full bg-amber-500"></span>{{ __('Last seen :time', ['time' => $screen->last_seen_at->diffForHumans()]) }}</span>
    @elseif ($st === 'waiting')
        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 font-bold text-sky-800"><span class="size-2 rounded-full bg-sky-500"></span>{{ __('Paired, waiting for the TV') }}</span>
    @else
        <span class="inline-flex items-center gap-1.5 rounded-full bg-navy-100 px-2.5 py-1 font-bold text-navy-700"><span class="size-2 rounded-full bg-navy-400"></span>{{ __('Not paired') }}</span>
    @endif
</div>
