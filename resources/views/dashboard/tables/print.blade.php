@extends('layouts.base')
@section('title', __('QR cards') . ' · ' . tenant()->name)
@section('body_class', 'bg-navy-50 text-navy-900')

@use('App\Support\StandStyles')
@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $logo = $settings['logo'] ? '/storage/' . $settings['logo'] : null;
    $options = $design['options'];
    $pageRules = [
        'classic' => '@page { size: A4; margin: 10mm; }',
        'a6' => '@page { size: 105mm 148mm; margin: 0; }',
        'a4' => '@page { size: A4; margin: 0; }',
    ];
    $card = fn (array $c) => [
        'style' => $design['style'], 'options' => $options, 'brand' => $brand, 'logo' => $logo, 'waiter' => $canCallWaiter,
        'headline' => $tableHeadline, 'table' => __('Table :name', ['name' => $c['table']->name]), 'qr' => $c['qr'], 'url' => $shortUrl,
    ];
    $menuCard = [
        'style' => $design['style'], 'options' => $options, 'brand' => $brand, 'logo' => $logo, 'waiter' => false, 'table' => null,
        'headline' => $toLinks ? __('Scan for the menu, ordering and our links') : __('Scan to see the menu'), 'qr' => $menuQr, 'url' => $shortUrl,
    ];
    $singleTable = ! $onlyMenu && $cards->count() === 1 ? $cards->first()['table'] : null;
    $previewCard = $onlyMenu ? $menuCard : ($cards->isNotEmpty() ? $card($cards->first()) : null);
@endphp

@push('head')
@include('partials.stand-card-css')
<style id="page-size">{{ $pageRules[$design['layout']] }}</style>
<style>
    .sheets { padding: 6mm 0 12mm; }
    .sheet, .cell { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sheets[data-layout="classic"] { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6mm; max-width: 190mm; margin: 0 auto; padding-inline: 4mm; }
    .sheets[data-layout="classic"] .sheet { display: contents; }
    .sheets[data-layout="classic"] .cell { break-inside: avoid; }
    .sheets[data-layout="classic"] .sc { border-radius: 3mm; outline: .25mm dashed #b8c3d1; outline-offset: 1mm; }
    .sheets[data-layout="a6"] .sheet { display: contents; }
    .sheets[data-layout="a6"] .cell { width: 105mm; height: 148mm; margin: 0 auto 8mm; break-after: page; box-shadow: 0 6px 20px rgba(15, 30, 60, .15); }
    .sheets[data-layout="a6"] .sheet:last-child .cell:last-child { break-after: auto; }
    .sheets[data-layout="a4"] .sheet { position: relative; width: 210mm; height: 297mm; margin: 0 auto 8mm; background: #fff; display: grid;
        grid-template-columns: 105mm 105mm; grid-template-rows: 148.5mm 148.5mm; align-content: start; break-after: page; overflow: hidden; box-shadow: 0 6px 20px rgba(15, 30, 60, .15); }
    .sheets[data-layout="a4"] .sheet:last-child { break-after: auto; }
    /* light cut lines between the four cards */
    .sheets[data-layout="a4"] .sheet::before, .sheets[data-layout="a4"] .sheet::after { content: ""; position: absolute; z-index: 1; pointer-events: none; }
    .sheets[data-layout="a4"] .sheet::before { inset-block: 0; left: 105mm; border-left: .2mm dashed #9aa7b8; }
    .sheets[data-layout="a4"] .sheet::after { inset-inline: 0; top: 148.5mm; border-top: .2mm dashed #9aa7b8; }
    .sheets[data-layout="a4"] .cell { width: 105mm; height: 148mm; }
    .sheets:not([data-layout="classic"]) .sc { height: 100%; aspect-ratio: auto; }
    @media print {
        body { background: #fff !important; }
        .no-print { display: none !important; }
        .sheets { padding: 0; }
        .sheets[data-layout="a6"] .cell, .sheets[data-layout="a4"] .sheet { margin: 0; box-shadow: none; }
    }
</style>
@endpush

@section('body')
<div class="no-print sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-navy-100 bg-white p-4">
    <div class="flex items-center gap-3">
        <a href="{{ route('dash.tables') }}" class="text-sm font-semibold text-brand-700">{{ __('Back to tables') }}</a>
        <span class="text-sm text-navy-500">{{ __('Pick the design, then print. Turn on “Background graphics” in the print dialog so the colors come out.') }}</span>
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($singleTable)
            <a href="{{ route('dash.tables.svg', $singleTable) }}" class="btn-ghost" data-svg-download>{{ __('Download SVG') }}</a>
        @endif
        <button type="button" onclick="window.print()" class="btn-primary">{{ __('Print') }}</button>
    </div>
</div>

<section class="no-print mx-auto max-w-6xl p-4" data-design>
    <div class="card grid gap-6 lg:grid-cols-[1fr_300px]">
        <form method="post" action="{{ route('dash.tables.design') }}" class="space-y-5">
            @csrf
            <div>
                <h1 class="text-xl font-extrabold">{{ __('Stand design') }}</h1>
                <p class="text-sm text-navy-500">{{ __('Changes show on the cards right away. Save to keep them for next time and for the SVG download.') }}</p>
            </div>
            @if (session('ok'))<div class="rounded-xl bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800" role="status">{{ session('ok') }}</div>@endif
            @if ($errors->any())
                <div class="rounded-xl bg-red-50 px-4 py-2 text-sm text-red-800" role="alert">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            @endif

            <fieldset>
                <legend class="field-label">{{ __('Stand material') }}</legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach (StandStyles::STYLES as $key => $p)
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-navy-200 p-2 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                            <input type="radio" name="stand_style" value="{{ $key }}" @checked($design['style'] === $key) class="sr-only">
                            <span class="size-7 shrink-0 rounded-lg border border-navy-200" style="background: linear-gradient(165deg, {{ $p['bg1'] }}, {{ $p['bg2'] }})"></span>
                            {{ StandStyles::label($key) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="field-label">{{ __('Print layout') }}</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach (StandStyles::LAYOUTS as $layout)
                        @php([$layoutName, $layoutHint] = StandStyles::layoutLabel($layout))
                        <label class="cursor-pointer rounded-xl border border-navy-200 p-3 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                            <input type="radio" name="stand_layout" value="{{ $layout }}" @checked($design['layout'] === $layout) class="sr-only">
                            <span class="block font-bold">{{ $layoutName }}</span>
                            <span class="block text-xs text-navy-500">{{ $layoutHint }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="field-label">{{ __('Accent color (frames and text)') }}</span>
                    <span class="flex items-center gap-2">
                        <input type="color" name="accent" value="{{ $options['accent'] }}" class="h-11 w-16 cursor-pointer rounded-lg border border-navy-200 bg-white p-1">
                        @foreach (array_unique([$settings['color_primary'], $settings['color_secondary'], '#F26522', '#0F766E', '#B45309']) as $swatch)
                            @if (is_hex_color($swatch))
                                <button type="button" class="size-8 rounded-full border-2 border-white shadow ring-1 ring-navy-200" style="background: {{ $swatch }}" data-accent="{{ $swatch }}" aria-label="{{ $swatch }}"></button>
                            @endif
                        @endforeach
                    </span>
                    <span class="mt-1 block text-xs text-navy-500">{{ __('The QR code itself stays dark on white so every phone can scan it.') }}</span>
                </label>
                <div class="space-y-2 text-sm">
                    <span class="field-label">{{ __('Lines on the card') }}</span>
                    @if ($canCallWaiter)
                        <label class="flex items-center gap-2"><input type="checkbox" name="waiter" value="1" @checked($options['waiter']) class="size-4 accent-brand-600"> {{ __('Call the waiter from your phone') }}</label>
                    @else
                        <input type="hidden" name="waiter" value="{{ $options['waiter'] ? 1 : 0 }}">
                        <p class="text-xs text-navy-500">{{ __('“Call the waiter” shows up here when ordering from the table is on in your plan and settings.') }}</p>
                    @endif
                    <label class="flex items-center gap-2"><input type="checkbox" name="payment" value="1" @checked($options['payment']) class="size-4 accent-brand-600"> {{ __('Pay cash or InstaPay') }}</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="url" value="1" @checked($options['url']) class="size-4 accent-brand-600"> {{ __('The short menu link') }} <code dir="ltr" class="text-xs text-navy-500">{{ $shortUrl }}</code></label>
                </div>
                <label class="block">
                    <span class="field-label">{{ __('Wi-Fi name (optional)') }}</span>
                    <input name="wifi_name" value="{{ $options['wifi_name'] }}" maxlength="40" class="field" dir="auto">
                </label>
                <label class="block">
                    <span class="field-label">{{ __('Wi-Fi password (optional)') }}</span>
                    <input name="wifi_password" value="{{ $options['wifi_password'] }}" maxlength="40" class="field" dir="ltr">
                </label>
            </div>
            <button class="btn-primary">{{ __('Save design') }}</button>
        </form>

        @if ($previewCard)
            <div class="stand-scene self-start" aria-hidden="true">
                <div class="stand mx-auto max-w-[260px]" data-stand data-style="{{ $design['style'] }}">
                    <div class="stand__card">@include('partials.stand-card', $previewCard)</div>
                    <div class="stand__base"></div>
                </div>
                <p class="mt-4 text-center text-xs text-navy-500">{{ __('How it looks in the stand') }}</p>
            </div>
        @endif
    </div>
</section>

<main class="sheets" data-sheets data-layout="{{ $design['layout'] }}">
    @if ($onlyMenu)
        <div class="sheet"><div class="cell">@include('partials.stand-card', $menuCard)</div></div>
    @else
        @forelse ($cards->chunk(4) as $chunk)
            <div class="sheet">
                @foreach ($chunk as $c)
                    <div class="cell">@include('partials.stand-card', $card($c))</div>
                @endforeach
            </div>
        @empty
            <p class="col-span-full text-center text-navy-500">{{ __('No tables yet.') }}</p>
        @endforelse
    @endif
</main>

<script>
    // live preview: the form drives every card on the page; saving keeps the choice for next time
    (() => {
        const form = document.querySelector('[data-design] form');
        const pageRules = @json($pageRules);
        const apply = () => {
            const data = new FormData(form);
            const style = data.get('stand_style');
            const accent = data.get('accent');
            document.querySelectorAll('[data-stand-card], [data-stand]').forEach((el) => { el.dataset.style = style; });
            document.querySelectorAll('[data-stand-card]').forEach((el) => el.style.setProperty('--accent', accent));
            ['waiter', 'payment', 'url'].forEach((key) => {
                const box = form.querySelector(`input[type="checkbox"][name="${key}"]`);
                if (box) document.querySelectorAll(`[data-line="${key}"]`).forEach((el) => { el.hidden = !box.checked; });
            });
            const wifiName = String(data.get('wifi_name') || '').trim();
            const wifiPass = String(data.get('wifi_password') || '').trim();
            document.querySelectorAll('[data-line="wifi"]').forEach((el) => {
                el.hidden = wifiName === '';
                el.querySelector('[data-wifi-name]').textContent = wifiName;
                el.querySelector('[data-wifi-pass]').textContent = wifiPass;
                el.querySelector('[data-wifi-pass-wrap]').hidden = wifiPass === '';
            });
            document.querySelector('[data-sheets]').dataset.layout = data.get('stand_layout');
            document.getElementById('page-size').textContent = pageRules[data.get('stand_layout')];
        };
        form.addEventListener('input', apply);
        form.addEventListener('change', apply);
        form.querySelectorAll('[data-accent]').forEach((b) => b.addEventListener('click', () => {
            form.querySelector('[name="accent"]').value = b.dataset.accent;
            apply();
        }));
    })();
</script>
@endsection
