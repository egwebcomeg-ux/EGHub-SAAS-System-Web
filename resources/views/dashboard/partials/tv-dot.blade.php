{{-- A small coloured dot: the screen is on (green), was seen before (amber) or not paired / not seen yet (grey). --}}
@php
    $st = $screen->status();
@endphp
<span @class(['size-2.5 shrink-0 rounded-full', 'bg-emerald-500' => $st === 'online', 'bg-amber-500' => $st === 'offline', 'bg-navy-300' => ! in_array($st, ['online', 'offline'], true)])
    title="{{ $st === 'online' ? __('Playing now') : ($st === 'offline' ? __('Offline') : __('Not paired')) }}"></span>
