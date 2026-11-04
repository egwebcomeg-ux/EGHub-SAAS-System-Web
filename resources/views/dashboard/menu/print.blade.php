<!doctype html>
<html lang="{{ $mainLang }}" dir="{{ \App\Support\MenuLocales::dir($mainLang) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Printed menu') }} · {{ tenant()->name }}</title>
    @fonts
    @vite(['resources/css/app.css'])
    {{-- the restaurant's own font, as on its guest menu (the script waits for it before fitting the columns) --}}
    @if ($fontCss = \App\Support\MenuFonts::cssUrl($settings['font']))
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="stylesheet" href="{{ $fontCss }}">
    @endif
    @php
        $primary = is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#F26522';
        $secondary = is_hex_color($settings['color_secondary']) ? $settings['color_secondary'] : '#0A2540';
        $currency = $mainLang === 'ar' ? $settings['currency'] : ($settings['currency_en'] ?: $settings['currency']);
        // a menu row in one language: Arabic as typed; English (name falls back to Arabic); an extra language (then English, then Arabic)
        $text = function ($model, string $field, ?string $locale) {
            $ar = (string) $model->getAttribute($field);
            $en = (string) $model->getAttribute($field.'_en');
            $fallback = $field === 'name' ? ($en ?: $ar) : $en;
            return match ($locale) {
                null => '',
                'ar' => $ar,
                'en' => $fallback,
                default => (string) \App\Services\Menu\MenuTranslations::text($model, $field, $locale) ?: $fallback,
            };
        };
        $sizePrices = function ($item) use ($text, $mainLang) {
            $sizes = $item->optionGroups->first(fn ($g) => $g->type === 'single' && $g->is_required);
            return $sizes ? $sizes->options->map(fn ($o) => [$text($o, 'name', $mainLang), $item->price + $o->price])->all() : [['', $item->price]];
        };
        $cards = $template === 'cards';
        // the panels print Arabic prices in Arabic digits, like the weights next to them
        $cardPrice = fn ($price) => $mainLang === 'ar' ? strtr(money($price), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩', ',' => '٬', '.' => '٫']) : money($price);
        $phone = $branch->phone ?: $settings['phone'];
    @endphp
    <style>
        :root { --p: {{ $primary }}; --s2: {{ $secondary }}; --s: 1; }
        @page { size: A4; margin: 0; }
        html, body { background: #e9edf3; }
        body { font-family: '{{ $settings['font'] }}', 'Cairo', sans-serif; color: #1b2233; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { width: 210mm; height: 297mm; margin: 8mm auto; background: #fff; padding: 12mm 11mm; display: flex; flex-direction: column; box-shadow: 0 6px 30px rgba(0,0,0,.12); overflow: hidden; }
        .cols { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 9mm; min-height: 0; }
        .slot { overflow: hidden; min-height: 0; }
        .cat { margin-bottom: calc(5mm * var(--s)); break-inside: avoid; }
        .cat-head { display: flex; align-items: center; gap: 2mm; color: var(--s2); font-weight: 800; font-size: calc(17px * var(--s)); border-bottom: 2px solid var(--p); padding-bottom: 1mm; margin-bottom: calc(2mm * var(--s)); }
        .cat-head .en { color: var(--p); font-weight: 600; font-size: .7em; margin-inline-start: auto; }
        .cat-head.is-cont::after { content: '…'; opacity: .5; }
        .item { display: flex; gap: 2.5mm; align-items: flex-start; padding: calc(1.3mm * var(--s)) 0; }
        .item img { width: calc(15mm * var(--s)); height: calc(15mm * var(--s)); object-fit: cover; border-radius: 2mm; flex-shrink: 0; }
        .item .body { flex: 1; min-width: 0; }
        .item .line { display: flex; align-items: baseline; gap: 1.5mm; font-size: calc(13px * var(--s)); font-weight: 700; }
        .item .dots { flex: 1; border-bottom: 1px dotted #b8c0cf; transform: translateY(-1mm); }
        .item .price { color: var(--p); font-weight: 800; white-space: nowrap; }
        .item .en { font-size: calc(10.5px * var(--s)); color: #66708a; font-weight: 600; }
        .item .desc { font-size: calc(10px * var(--s)); color: #66708a; margin-top: .3mm; }
        .item .sizes { font-size: calc(10.5px * var(--s)); color: #3a4560; }
        header.top { display: flex; align-items: center; justify-content: space-between; gap: 6mm; border-bottom: 3px solid var(--s2); padding-bottom: 4mm; margin-bottom: 5mm; }
        header.top h1 { font-size: 26px; font-weight: 800; color: var(--s2); margin: 0; }
        header.top p { margin: 0; color: #66708a; font-size: 12px; }
        footer.bottom { display: flex; align-items: center; gap: 5mm; border-top: 2px solid #e3e7ee; padding-top: 3mm; margin-top: 3mm; font-size: 11px; color: #4b5570; }
        footer.bottom .qr { width: 24mm; } footer.bottom .qr svg { width: 100%; height: auto; }
        #pool { position: absolute; visibility: hidden; width: 90mm; inset-inline-start: -9999px; }
        @media print { html, body { background: #fff; } .sheet { margin: 0; box-shadow: none; } .no-print { display: none !important; } }
        @if ($cards)
            @page { size: A4 landscape; margin: 0; }
            .sheet { width: 297mm; height: 210mm; padding: 9mm; position: relative; }
            .sheet .cols { grid-template-columns: repeat(4, 1fr); gap: 6mm; position: relative; z-index: 1; }
            .dot { position: absolute; border-radius: 50%; z-index: 0; }
            .slot { border-radius: 8mm; padding: 6mm 5mm; }
            .slot.dark { background: var(--s2); color: #fff; }
            .slot.light { background: #fff; border: 2.5px solid var(--s2); }
            .cat-head { border: 0; padding: 0; justify-content: flex-start; font-size: calc(20px * var(--s)); }
            .cat-head .photo { width: calc(17mm * var(--s)); height: calc(17mm * var(--s)); border-radius: 50%; object-fit: cover; margin-inline-start: auto; flex-shrink: 0; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.18); }
            /* the category's photo by its title, as the shop's own price list does; once, not on a continued panel */
            .cat-head.is-cont .photo { display: none; }
            .dark .cat-head { color: var(--p); } .dark .cat-head .en { color: #fff; opacity: .7; }
            .row { display: grid; grid-template-columns: 1fr auto 11mm; align-items: baseline; gap: 2mm; font-size: calc(14px * var(--s)); font-weight: 700; line-height: 1.35; }
            .item-block { padding: calc(.5mm * var(--s)) 0; }
            .row .size { color: var(--p); font-size: .9em; white-space: nowrap; }
            .row .price { text-align: end; font-weight: 800; }
            .row .en { grid-column: 1 / -1; font-size: .72em; opacity: .65; }
            .light .row .price { color: var(--s2); }
            .item.item-block { display: block; }
            footer.bottom { position: relative; z-index: 1; border: 0; margin-top: 4mm; padding: 0; justify-content: space-between; }
            footer.bottom .qr { width: 20mm; }
            .call { display: inline-flex; flex-direction: column; align-items: center; border: 2.5px solid var(--s2); border-radius: 4mm; overflow: hidden; }
            .call b { background: var(--s2); color: #fff; padding: 1mm 6mm; font-size: 15px; }
            .call span { padding: .5mm 5mm; font-size: 19px; font-weight: 800; color: var(--s2); }
        @endif
    </style>
</head>
<body>
<div class="no-print sticky top-0 z-10 border-b border-navy-100 bg-white px-4 py-3 shadow-sm">
    <form class="mx-auto flex max-w-5xl flex-wrap items-end gap-3 text-sm">
        <div><label class="field-label">{{ __('Template') }}</label>
            <select class="field min-h-9 py-0" name="template" onchange="this.form.submit()">
                <option value="text" @selected($template === 'text')>{{ __('Text only') }}</option>
                <option value="photos" @selected($template === 'photos')>{{ __('With photos') }}</option>
                <option value="cards" @selected($cards)>{{ __('Colour panels (landscape)') }}</option>
            </select></div>
        <div><label class="field-label">{{ __('Pages') }}</label>
            <select class="field min-h-9 py-0" name="pages" onchange="this.form.submit()">
                @for ($p = 1; $p <= 6; $p++)<option value="{{ $p }}" @selected($pages === $p)>{{ $p }}</option>@endfor
            </select></div>
        <div><label class="field-label">{{ __('Language') }}</label>
            <select class="field min-h-9 py-0" name="lang" onchange="this.form.submit()">
                <option value="both" @selected($lang === 'both')>{{ __('Arabic + English') }}</option>
                <option value="ar" @selected($lang === 'ar')>العربية</option>
                <option value="en" @selected($lang === 'en')>English</option>
                @foreach ($extraLocales as $code)
                    <option value="ar-{{ $code }}" @selected($lang === 'ar-'.$code)>العربية + {{ \App\Support\MenuLocales::native($code) }}</option>
                    <option value="{{ $code }}" @selected($lang === $code)>{{ \App\Support\MenuLocales::native($code) }}</option>
                @endforeach
            </select></div>
        @if ($branches->count() > 1)
            <div><label class="field-label">{{ __('Branch prices') }}</label>
                <select class="field min-h-9 py-0" name="branch" onchange="this.form.submit()">
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach
                </select></div>
        @endif
        <label class="flex min-h-9 items-center gap-2"><input type="hidden" name="desc" value="0"><input type="checkbox" name="desc" value="1" @checked($desc) onchange="this.form.submit()" class="size-4 accent-brand-600"> {{ __('Descriptions') }}</label>
        <button type="button" onclick="window.print()" class="btn-primary ms-auto">🖨 {{ __('Print / save as PDF') }}</button>
    </form>
    <p class="mx-auto mt-2 max-w-5xl text-xs text-navy-500" id="fit-note"></p>
</div>

@for ($p = 1; $p <= $pages; $p++)
    @if ($cards)
        {{-- the panels template: brand-coloured dots behind four panels, the logo, «order now» and the QR under them --}}
        <div class="sheet">
            @foreach ([['-9mm', '-6mm', '30mm', $primary], ['62%', '-12mm', '26mm', '#DCE34A'], ['30%', '96%', '24mm', '#6CC04A'], ['-8mm', '45%', '18mm', '#8E7CC3'], ['97%', '60%', '20mm', $primary], ['55%', '93%', '22mm', '#E86A7A']] as [$top, $left, $size, $color])
                <span class="dot" style="top: {{ $top }}; left: {{ $left }}; width: {{ $size }}; height: {{ $size }}; background: {{ $color }}"></span>
            @endforeach
            <div class="cols">
                @for ($s = 0; $s < 4; $s++)<div class="slot {{ $s % 2 ? 'light' : 'dark' }}"></div>@endfor
            </div>
            @if ($p === $pages)
                <footer class="bottom">
                    @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" style="height: 22mm">@else<b style="color: var(--s2); font-size: 22px">{{ $settings['brand_name'] }}</b>@endif
                    @if ($phone)<div class="call"><b>📞 {{ __('Order now') }}</b><span dir="ltr">{{ $phone }}</span></div>@endif
                    <div class="qr">{!! $qr !!}</div>
                </footer>
            @endif
        </div>
        @continue
    @endif
    <div class="sheet">
        @if ($p === 1)
            <header class="top">
                <div>
                    <h1>{{ $mainLang !== 'ar' ? ($settings['brand_name_en'] ?: $settings['brand_name']) : $settings['brand_name'] }}</h1>
                    @if ($t = $mainLang !== 'ar' ? $settings['tagline_en'] : $settings['tagline'])<p>{{ $t }}</p>@endif
                </div>
                @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" style="height: 20mm">@endif
            </header>
        @endif
        <div class="cols"><div class="slot"></div><div class="slot"></div></div>
        @if ($p === $pages)
            <footer class="bottom">
                <div class="qr">{!! $qr !!}</div>
                <div>
                    <b style="color: var(--s2)">{{ __('Scan to see the menu and order') }}</b><br>
                    @if ($branch->address){{ $branch->address }}<br>@endif
                    {{ $branch->phone ?: $settings['phone'] }}
                    @if ((float) $settings['service_percent'] > 0 || (float) $settings['tax_percent'] > 0)
                        <br>{{ __('Prices in :currency, service :service% and tax :tax% not included.', ['currency' => $currency, 'service' => money($settings['service_percent']), 'tax' => money($settings['tax_percent'])]) }}
                    @endif
                </div>
            </footer>
        @endif
    </div>
@endfor

{{-- every category rendered once, hidden; the script flows them into the columns --}}
<div id="pool">
    @foreach ($categories as $c)
        <section class="cat">
            <div class="cat-head"><span>{{ $text($c, 'name', $mainLang) }}</span>@if (($second = $text($c, 'name', $secondLang)) && $second !== $text($c, 'name', $mainLang))<span class="en" dir="{{ \App\Support\MenuLocales::dir($secondLang) }}">{{ $second }}</span>@endif
                @if ($cards && $c->image)<img class="photo" src="/storage/{{ $c->image }}" alt="">@endif</div>
            @foreach ($c->items as $item)
                @php
                    $prices = $sizePrices($item);
                    $second = $text($item, 'name', $secondLang);
                    $second = $second !== $text($item, 'name', $mainLang) ? $second : '';
                @endphp
                @if ($cards)
                    {{-- one line per size (name on the first, then weight and price), as the shop prints its price list --}}
                    <div class="item item-block">
                        @foreach ($prices as [$size, $price])
                            <div class="row"><span>{{ $loop->first ? $text($item, 'name', $mainLang) : '' }}</span><span class="size">{{ $size }}</span><span class="price">{{ $cardPrice($price) }}</span>@if ($loop->first && $second)<span class="en" dir="{{ \App\Support\MenuLocales::dir($secondLang) }}">{{ $second }}</span>@endif @if ($loop->first && $desc && ($d = $text($item, 'description', $mainLang)))<span class="en">{{ $d }}</span>@endif</div>
                        @endforeach
                    </div>
                    @continue
                @endif
                <div class="item">
                    @if ($template === 'photos' && $item->image)<img src="/storage/{{ $item->image }}" alt="">@endif
                    <div class="body">
                        <div class="line">
                            <span>{{ $text($item, 'name', $mainLang) }}</span>
                            <span class="dots"></span>
                            @if (count($prices) === 1)<span class="price">{{ money($prices[0][1]) }}</span>@endif
                        </div>
                        @if (($second = $text($item, 'name', $secondLang)) && $second !== $text($item, 'name', $mainLang))<div class="en" dir="{{ \App\Support\MenuLocales::dir($secondLang) }}">{{ $second }}</div>@endif
                        @if (count($prices) > 1)
                            <div class="sizes">@foreach ($prices as [$size, $price]){{ $size }} <b class="price">{{ money($price) }}</b>@if (!$loop->last) · @endif @endforeach</div>
                        @endif
                        @if ($desc && ($d = $text($item, 'description', $mainLang)))<div class="desc">{{ $d }}</div>@endif
                        @if ($desc && ($item->allergenList() || $item->spicyIcons()))<div class="desc">{{ $item->spicyIcons() }} {{ implode(' · ', array_map(fn ($a) => $a['icon'].' '.$a['label'], $item->allergenList())) }}</div>@endif
                    </div>
                </div>
            @endforeach
        </section>
    @endforeach
</div>

@php
    $i18n = [
        'fit' => __('Fits on :pages A4 page(s) at :size% text size. Print with no margins and “Background graphics” on.', ['pages' => $pages, 'size' => '__S__']),
        'warn' => __('The menu does not fit on :pages page(s) even at the smallest text. Add a page or hide descriptions.', ['pages' => $pages]),
    ];
@endphp
<script>
    // Flow categories into the columns in menu order; split a long category only if both parts keep 3+ items.
    // Try the largest type scale first and step down until everything fits. (Ported from Scratch Cafe.)
    (() => {
        const T = @json($i18n);
        const slots = [...document.querySelectorAll('.slot')];
        const cats = [...document.querySelectorAll('#pool .cat')];
        const root = document.documentElement;
        const overflows = (slot) => slot.scrollHeight > slot.clientHeight + 0.5;

        function layout(scale) {
            root.style.setProperty('--s', scale);
            slots.forEach((s) => (s.innerHTML = ''));
            let si = 0;
            for (const cat of cats) {
                const head = cat.querySelector('.cat-head');
                const rows = [...cat.querySelectorAll('.item')];
                let i = 0, split = false, block;
                const open = () => {
                    block = document.createElement('section');
                    block.className = 'cat';
                    const h = head.cloneNode(true);
                    h.classList.toggle('is-cont', i > 0);
                    block.append(h);
                    slots[si].append(block);
                };
                open();
                while (i < rows.length) {
                    const r = rows[i].cloneNode(true);
                    block.append(r);
                    if (!overflows(slots[si])) { i++; continue; }
                    r.remove();
                    const back = split ? 0 : Math.max(0, 3 - (rows.length - i));
                    if (i === 0 || (!split && i - back < 3)) { block.remove(); i = 0; }
                    else if (back) { [...block.querySelectorAll('.item')].slice(-back).forEach((el) => el.remove()); i -= back; }
                    split = true;
                    if (++si >= slots.length) return false;
                    open();
                }
            }
            return true;
        }

        function fit() {
            // the panels stop at 110%: larger, long names break onto two lines
            let scale = @json($cards ? 1.1 : 1.35);
            while (scale > 0.6 && !layout(scale)) scale = Math.round((scale - 0.025) * 1000) / 1000;
            const ok = scale > 0.6 || layout(scale);
            const note = document.getElementById('fit-note');
            note.textContent = ok ? T.fit.replace('__S__', Math.round(scale * 100)) : T.warn;
            note.className = 'mx-auto mt-2 max-w-5xl text-xs ' + (ok ? 'text-navy-500' : 'font-bold text-red-600');
        }

        (document.fonts?.ready ?? Promise.resolve())
            .then(() => Promise.all([...document.images].filter((i) => !i.complete).map((i) => new Promise((r) => (i.onload = i.onerror = r)))))
            .then(fit);
    })();
</script>
</body>
</html>
