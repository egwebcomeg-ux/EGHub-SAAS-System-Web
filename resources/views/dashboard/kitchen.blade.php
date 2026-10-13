@extends('layouts.base', ['viteEntries' => ['resources/js/kitchen.js']])
@section('title', __('Kitchen display'))
@section('body_class', 'bg-navy-900 text-white')

@section('body')
@php
    $i18n = [
        'start' => __('Start'), 'ready' => __('Ready'), 'new' => __('New'), 'preparing' => __('Preparing'),
        'table' => __('Table :name', ['name' => '__N__']), 'empty' => __('No orders right now'), 'scheduled' => __('Scheduled :time', ['time' => '__T__']),
    ];
@endphp
<div class="flex min-h-dvh flex-col">
    <header class="flex flex-wrap items-center gap-3 border-b border-white/10 px-4 py-3">
        <a href="{{ route('dash.home') }}" class="text-white/60 hover:text-white">←</a>
        <h1 class="text-xl font-extrabold">{{ __('Kitchen display') }}{{ $station ? ' · ' . tr($station->name, $station->name_en) : '' }}</h1>
        @if ($stations->isNotEmpty())
            <nav class="flex flex-wrap gap-1 text-sm">
                <a href="?" @class(['rounded-lg px-3 py-1.5', 'bg-white text-navy-900' => !$station, 'bg-white/10' => $station])>{{ __('All') }}</a>
                @foreach ($stations as $s)
                    <a href="?station={{ $s->id }}" @class(['rounded-lg px-3 py-1.5', 'bg-white text-navy-900' => $station?->id === $s->id, 'bg-white/10' => $station?->id !== $s->id])>{{ tr($s->name, $s->name_en) }}</a>
                @endforeach
            </nav>
        @endif
        <div class="ms-auto flex items-center gap-2 text-sm">
            <span class="badge bg-white/10 text-white" data-count>0</span>
            <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5" data-sound>🔔 {{ __('Enable sound') }}</button>
            <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5" data-fullscreen>⛶ {{ __('Full screen') }}</button>
        </div>
    </header>
    <main class="grid flex-1 auto-rows-min gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" id="kds"
          data-feed="{{ route('dash.kitchen.feed', array_filter(['station' => $station?->id])) }}"
          data-bump="{{ url('/kitchen/orders/__ID__') . ($station ? '?station=' . $station->id : '') }}"
          data-i18n='{{ json_encode($i18n, JSON_UNESCAPED_UNICODE) }}'></main>
</div>
@endsection
