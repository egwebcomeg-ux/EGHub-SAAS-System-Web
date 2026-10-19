@extends('layouts.base')
@section('title', __('Print vouchers') . ' · ' . tenant()->name)
@section('body_class', 'bg-navy-50 text-navy-900')

@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $logo = $settings['logo'] ? '/storage/' . $settings['logo'] : null;
    $accent = is_hex_color($settings['color_primary'] ?? '') ? $settings['color_primary'] : '#F26522';
    $perSheet = $layout === 'a4' ? 8 : 1;
@endphp

@push('head')
<style>
    @page { size: {{ $layout === 'a4' ? 'A4' : '105mm 148mm' }}; margin: 0; }
    .sheet { position: relative; margin: 6mm auto; background: #fff; box-shadow: 0 6px 20px rgba(15, 30, 60, .15); break-after: page; overflow: hidden;
        -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sheet:last-child { break-after: auto; }
    [data-layout="a6"] .sheet { width: 105mm; height: 148mm; }
    [data-layout="a4"] .sheet { width: 210mm; height: 297mm; display: grid; grid-template-columns: 105mm 105mm; grid-template-rows: repeat(4, 74.25mm); }
    [data-layout="a4"] .v { border: .2mm dashed #b8c3d1; }
    .v { --accent: {{ $accent }}; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center;
        padding: 9mm 8mm; height: 100%; box-sizing: border-box; border-top: 3mm solid var(--accent); }
    .v__brand { display: flex; align-items: center; justify-content: center; gap: 2mm; font-weight: 800; font-size: 4.2mm; }
    .v__brand img { max-height: 13mm; max-width: 40mm; object-fit: contain; }
    .v__value { font-size: 12mm; line-height: 1.05; font-weight: 900; color: var(--accent); }
    .v__cond { font-size: 3.1mm; color: #3c4a5e; }
    .v__qr svg { width: 34mm; height: 34mm; }
    .v__code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 7mm; font-weight: 800; letter-spacing: .6mm; border: .4mm solid #1d2a3d; border-radius: 2mm; padding: 1mm 3mm; }
    .v__exp { font-size: 3mm; color: #3c4a5e; }
    /* 8 per A4: the QR beside the text */
    [data-layout="a4"] .v { display: grid; grid-template-columns: 1fr 27mm; gap: 2mm 4mm; align-items: center; padding: 5mm 6mm; border-top-width: 2mm; text-align: start; }
    [data-layout="a4"] .v__brand { justify-content: flex-start; grid-column: 1 / -1; font-size: 3.6mm; }
    [data-layout="a4"] .v__brand img { max-height: 8mm; }
    [data-layout="a4"] .v__value { font-size: 8mm; }
    [data-layout="a4"] .v__body { display: flex; flex-direction: column; gap: 1.5mm; align-items: flex-start; }
    [data-layout="a4"] .v__code { font-size: 5mm; }
    [data-layout="a4"] .v__qr svg { width: 27mm; height: 27mm; }
    [data-layout="a6"] .v__body { display: flex; flex-direction: column; align-items: center; gap: 3mm; }
    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .sheet { margin: 0; box-shadow: none; }
    }
</style>
@endpush

@section('body')
<div class="no-print sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-navy-100 bg-white p-4">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('dash.coupons') }}" class="text-sm font-semibold text-brand-700">{{ __('Back to coupons') }}</a>
        <span class="text-sm text-navy-500">{{ __(':n vouchers', ['n' => $cards->count()]) }} · {{ __('Turn on “Background graphics” in the print dialog so the colors come out.') }}</span>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('dash.vouchers.print', $query + ['layout' => 'a6']) }}" @class(['btn-ghost', 'ring-2 ring-brand-500' => $layout === 'a6'])>{{ __('A6, one per page') }}</a>
        <a href="{{ route('dash.vouchers.print', $query + ['layout' => 'a4']) }}" @class(['btn-ghost', 'ring-2 ring-brand-500' => $layout === 'a4'])>{{ __('8 per A4') }}</a>
        <button type="button" onclick="window.print()" class="btn-primary">{{ __('Print') }}</button>
    </div>
</div>

<main data-layout="{{ $layout }}">
    @foreach ($cards->chunk($perSheet) as $chunk)
        <div class="sheet">
            @foreach ($chunk as $c)
                @php($v = $c['voucher'])
                <article class="v" data-print-voucher="{{ $v->code }}">
                    <div class="v__brand">
                        @if ($logo)<img src="{{ $logo }}" alt="">@endif
                        <span>{{ $brand }}</span>
                    </div>
                    <div class="v__body">
                        <div class="v__value">{{ $v->headline() }}</div>
                        @if ($c['conditions'] !== '')<div class="v__cond">{{ $c['conditions'] }}</div>@endif
                        <div class="v__code" dir="ltr">{{ $v->code }}</div>
                        <div class="v__exp">
                            @if ($v->starts_at){{ __('from :date', ['date' => local_time($v->starts_at, 'Y/m/d')]) }} · @endif
                            {{ $v->expires_at ? __('Valid until :date', ['date' => local_time($v->expires_at, 'Y/m/d')]) : __('Scan to order with the discount') }}
                        </div>
                    </div>
                    <div class="v__qr" aria-hidden="true">{!! $c['qr'] !!}</div>
                </article>
            @endforeach
        </div>
    @endforeach
</main>
@endsection
