@extends('layouts.base', ['viteEntries' => ['resources/js/kitchen.js']])
@section('title', \App\Support\BusinessType::prepScreen())
@section('body_class', 'bg-navy-900 text-white')

@section('body')
@php
    $i18n = [
        'start' => __('Start'), 'ready' => __('Ready'), 'new' => __('New'), 'preparing' => __('Preparing'),
        'table' => __('Table :name', ['name' => '__N__']), 'empty' => __('No orders right now'), 'scheduled' => __('Scheduled :time', ['time' => '__T__']),
        'foodReady' => __('Food ready'), 'drinksReady' => __('Drinks ready'),
        'pager' => \App\Services\Orders\Pager::staffTexts(),
    ];
    $chips = [['all', __('All')], ['dine_in', __('Dine-in')], ['takeaway', __('Takeaway')], ['delivery', __('Delivery')], ['food', '🍽 '.__('Food')], ['drinks', '☕ '.__('Drinks')]];
@endphp
<div class="dash-app flex min-h-dvh flex-col">
    <header class="flex flex-wrap items-center gap-3 border-b border-white/10 px-4 py-3">
        <a href="{{ route('dash.home') }}" class="grid size-11 place-items-center rounded-xl text-lg text-white/60 hover:bg-white/10 hover:text-white" aria-label="{{ __('Dashboard') }}">←</a>
        <h1 class="text-xl font-extrabold">{{ \App\Support\BusinessType::prepScreen() }}{{ $station ? ' · ' . tr($station->name, $station->name_en) : '' }}</h1>
        @if ($stations->isNotEmpty())
            <nav class="flex flex-wrap gap-1 text-sm">
                <a href="?" @class(['flex min-h-10 items-center rounded-lg px-3', 'bg-white text-navy-900' => !$station, 'bg-white/10' => $station])>{{ __('All') }}</a>
                @foreach ($stations as $s)
                    <a href="?station={{ $s->id }}" @class(['flex min-h-10 items-center rounded-lg px-3', 'bg-white text-navy-900' => $station?->id === $s->id, 'bg-white/10' => $station?->id !== $s->id])>{{ tr($s->name, $s->name_en) }}</a>
                @endforeach
            </nav>
        @endif
        <div class="ms-auto flex items-center gap-2 text-sm">
            <span class="badge bg-white/10 text-white" data-count>0</span>
            @if (auth()->user()->can('menu.manage') && \App\Services\Entitlements::current()->allows('kitchen_stations'))
                {{-- the stations (which station prepares what) are this screen's settings --}}
                <a href="{{ route('dash.stations') }}" class="flex min-h-10 items-center rounded-lg bg-white/10 px-3 font-semibold hover:bg-white/15" data-kitchen-settings>⚙ {{ __('Kitchen stations') }}</a>
            @endif
            <button type="button" class="min-h-10 rounded-lg bg-white/10 px-3 font-semibold hover:bg-white/15" data-sound>🔔 {{ __('Enable sound') }}</button>
            <button type="button" class="min-h-10 rounded-lg bg-white/10 px-3 font-semibold hover:bg-white/15" data-fullscreen>⛶ {{ __('Full screen') }}</button>
        </div>
    </header>
    {{-- quick filters: order type, or only the food / only the drinks of every ticket (remembered on this screen) --}}
    <div class="flex gap-2 overflow-x-auto border-b border-white/10 px-4 py-2" data-chips role="toolbar" aria-label="{{ __('Filter orders') }}">
        @foreach ($chips as [$key, $label])
            <button type="button" data-chip="{{ $key }}" aria-pressed="false"
                class="group flex min-h-10 shrink-0 items-center gap-2 rounded-full bg-white/10 px-4 text-sm font-bold text-white transition aria-pressed:bg-white aria-pressed:text-navy-900">
                <span>{{ $label }}</span>
                <span class="min-w-6 rounded-full bg-white/15 px-1.5 text-center text-xs tabular-nums group-aria-pressed:bg-brand-600 group-aria-pressed:text-white" data-chip-count="{{ $key }}">0</span>
            </button>
        @endforeach
    </div>
    <main class="grid flex-1 auto-rows-min gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" id="kds"
          data-feed="{{ route('dash.kitchen.feed', array_filter(['station' => $station?->id])) }}"
          data-bump="{{ url('/kitchen/orders/__ID__') . ($station ? '?station=' . $station->id : '') }}"
          data-i18n='{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}'></main>
</div>
@endsection
