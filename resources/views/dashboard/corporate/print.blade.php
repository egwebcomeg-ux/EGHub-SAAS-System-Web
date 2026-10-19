@extends('layouts.base')
@section('title', __('Print vouchers') . ' · ' . $contract->name)
@section('body_class', 'bg-navy-50 text-navy-900')

@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $logo = $settings['logo'] ? '/storage/' . $settings['logo'] : null;
    $accent = is_hex_color($settings['color_primary'] ?? '') ? $settings['color_primary'] : '#F26522';
    $currency = tr($settings['currency'], $settings['currency_en']);
    $terms = array_filter([
        $contract->mode === 'single' ? __('One use: the whole value in one order') : __('Balance: what is left stays for next time'),
        $contract->windowLabel(),
        $contract->min_order ? __('Minimum order :amount', ['amount' => money($contract->min_order)]) : null,
        $branches->isNotEmpty() ? $branches->map(fn ($b) => tr($b->name, $b->name_en))->implode('، ') : null,
    ]);
@endphp

@push('head')
<style>
    /* 10 cut-out cards per A4 (2 × 5), same sheet as the loyalty voucher print */
    @page { size: A4; margin: 0; }
    .sheet { position: relative; margin: 6mm auto; background: #fff; box-shadow: 0 6px 20px rgba(15, 30, 60, .15); break-after: page; overflow: hidden;
        width: 210mm; height: 297mm; display: grid; grid-template-columns: 105mm 105mm; grid-template-rows: repeat(5, 59.4mm);
        -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sheet:last-child { break-after: auto; }
    .cv { --accent: {{ $accent }}; position: relative; display: grid; grid-template-columns: 1fr 25mm; gap: 1mm 3mm; align-items: center; box-sizing: border-box;
        height: 100%; padding: 4mm 5mm; border: .2mm dashed #b8c3d1; border-top: 2mm solid var(--accent); text-align: start; }
    .cv__brand { grid-column: 1 / -1; display: flex; align-items: center; gap: 2mm; font-weight: 800; font-size: 3.3mm; }
    .cv__brand img { max-height: 7mm; max-width: 28mm; object-fit: contain; }
    .cv__brand .cv__company { margin-inline-start: auto; font-size: 3mm; color: #3c4a5e; font-weight: 700; max-width: 45mm; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cv__body { display: flex; flex-direction: column; gap: 1mm; min-width: 0; }
    .cv__label { font-size: 2.7mm; color: #3c4a5e; font-weight: 700; }
    .cv__value { font-size: 8mm; line-height: 1; font-weight: 900; color: var(--accent); }
    .cv__value small { font-size: 3.5mm; }
    .cv__code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 4.2mm; font-weight: 800; letter-spacing: .4mm; border: .35mm solid #1d2a3d; border-radius: 1.5mm; padding: .5mm 2mm; align-self: flex-start; }
    .cv__meta { font-size: 2.6mm; color: #3c4a5e; line-height: 1.35; }
    .cv__qr svg { width: 25mm; height: 25mm; display: block; }
    .cv__serial { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 2.8mm; font-weight: 700; text-align: center; margin-top: .5mm; }
    .cv__copy { position: absolute; top: 2.5mm; left: 50%; transform: translateX(-50%) rotate(-6deg); border: .5mm solid #c0262d; color: #c0262d; font-weight: 900;
        font-size: 3.4mm; padding: .2mm 2mm; border-radius: 1mm; background: #fff; }
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
        <a href="{{ route('dash.corporate.show', $contract) }}" class="text-sm font-semibold text-brand-700">{{ __('Back to the contract') }}</a>
        <span class="text-sm text-navy-500">{{ __(':n vouchers', ['n' => $cards->count()]) }} · {{ __('Turn on “Background graphics” in the print dialog so the colors come out.') }}</span>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('dash.corporate.print', ['contract' => $contract, 'layout' => 'receipt', 'ids' => $cards->map(fn ($c) => $c['voucher']->id)->implode(',')]) }}" class="btn-ghost">{{ __('80mm receipts') }}</a>
        <button type="button" onclick="window.print()" class="btn-primary">{{ __('Print') }}</button>
    </div>
</div>

<main>
    @foreach ($cards->chunk(10) as $chunk)
        <div class="sheet">
            @foreach ($chunk as $c)
                @php($v = $c['voucher'])
                <article class="cv" data-print-voucher="{{ $v->serial }}" @if ($c['copy']) data-copy @endif>
                    @if ($c['copy'])<span class="cv__copy">{{ __('Reprint copy') }}</span>@endif
                    <div class="cv__brand">
                        @if ($logo)<img src="{{ $logo }}" alt="">@endif
                        <span>{{ $brand }}</span>
                        <span class="cv__company">{{ $contract->name }}</span>
                    </div>
                    <div class="cv__body">
                        <div class="cv__label">{{ __('Corporate voucher') }}</div>
                        <div class="cv__value">{{ money($v->value) }} <small>{{ $currency }}</small></div>
                        <div class="cv__code" dir="ltr">{{ $v->formattedCode() }}</div>
                        <div class="cv__meta">
                            <b>{{ __('Valid until :date', ['date' => local_time($v->expires_on, 'Y/m/d')]) }}</b><br>
                            {{ implode(' · ', $terms) }}
                        </div>
                    </div>
                    <div>
                        <div class="cv__qr" aria-hidden="true">{!! $c['qr'] !!}</div>
                        <div class="cv__serial" dir="ltr">{{ $v->serial }}</div>
                    </div>
                </article>
            @endforeach
        </div>
    @endforeach
</main>
@endsection
