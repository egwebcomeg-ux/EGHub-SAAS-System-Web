@extends('layouts.base')
@section('title', __('QR cards') . ' · ' . tenant()->name)
@section('body_class', 'bg-white text-navy-900')

@push('head')
<style>
    @page { size: A4; margin: 10mm; }
    .qr-card { break-inside: avoid; border-color: {{ $settings['color_secondary'] }}; }
    .qr-card svg { width: 100%; height: auto; }
    @media print { .no-print { display: none !important; } }
</style>
@endpush

@section('body')
@php($brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name)
<div class="no-print sticky top-0 flex items-center justify-between gap-3 border-b border-navy-100 bg-white p-4">
    <div class="text-sm text-navy-500">{{ __('Cards fit 6 per A4 page. Cut along the borders.') }}</div>
    <button onclick="window.print()" class="btn-primary">{{ __('Print') }}</button>
</div>

<div class="mx-auto grid max-w-[190mm] grid-cols-2 gap-[6mm] p-[6mm] sm:grid-cols-3">
    @if ($onlyMenu)
        <div class="qr-card rounded-2xl border-2 p-4 text-center">
            @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="mx-auto mb-2 h-12 object-contain">@endif
            <div class="font-extrabold">{{ $brand }}</div>
            <div class="my-3">{!! $menuQr !!}</div>
            <div class="text-sm font-bold" style="color: {{ $settings['color_primary'] }}">{{ $toLinks ? __('Scan for the menu, ordering and our links') : __('Scan to see the menu') }}</div>
        </div>
    @else
        @forelse ($cards as $c)
            <div class="qr-card rounded-2xl border-2 p-4 text-center">
                @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="mx-auto mb-2 h-12 object-contain">@endif
                <div class="font-extrabold">{{ $brand }}</div>
                <div class="my-3">{!! $c['qr'] !!}</div>
                <div class="text-2xl font-extrabold" style="color: {{ $settings['color_primary'] }}">{{ __('Table :name', ['name' => $c['table']->name]) }}</div>
                <div class="text-xs text-navy-500">{{ $toLinks ? __('Scan for the menu, ordering and our links') : __('Scan to see the menu and order') }}</div>
            </div>
        @empty
            <p class="col-span-full text-center text-navy-500">{{ __('No tables yet.') }}</p>
        @endforelse
    @endif
</div>
@endsection
