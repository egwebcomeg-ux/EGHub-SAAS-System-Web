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
        // a required pick that changes the price is a size (one price each); a pick of flavours at one price is not
        $sizePrices = function ($item) use ($text, $mainLang) {
            $sizes = $item->optionGroups->first(fn ($g) => $g->type === 'single' && $g->is_required);
            return $sizes && $sizes->options->contains(fn ($o) => (float) $o->price !== 0.0)
                ? $sizes->options->map(fn ($o) => [$text($o, 'name', $mainLang), $item->price + $o->price])->all() : [['', $item->price]];
        };
        // the ribbons template names the flavours of a one-price pick after the item (Sundae (Chocolate - Caramel)),
        // unless the item's name already lists them
        $flavourList = function ($item) use ($text, $mainLang) {
            $pick = $item->optionGroups->first(fn ($g) => $g->type === 'single' && $g->is_required);
            $names = $pick ? $pick->options->map(fn ($o) => $text($o, 'name', $mainLang)) : collect();
            $name = $text($item, 'name', $mainLang);
            return $names->isEmpty() || $names->every(fn ($flavour) => str_contains($name, $flavour)) ? null : $names->implode(' - ');
        };
        $cards = $template === 'cards';
        $ribbons = $template === 'ribbons';
        // the ribbons template tags each size with its initial (M, L) when the initials tell the sizes apart, else its name
        $sizeTags = function (array $prices) use ($mainLang) {
            $labels = array_column($prices, 0);
            $initials = array_map(fn ($label) => mb_strtoupper(mb_substr($label, 0, 1)), $labels);
            return $mainLang !== 'ar' && count(array_unique($initials)) === count($initials) ? $initials : $labels;
        };
        // the panels print Arabic prices in Arabic digits, like the weights next to them
        $cardPrice = fn ($price) => $mainLang === 'ar' ? strtr(money($price), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩', ',' => '٬', '.' => '٫']) : money($price);
        $phone = $branch->phone ?: $settings['phone'];
    @endphp
    <style>
        :root { --p: {{ $primary }}; --s2: {{ $secondary }}; --s: 1;
            /* the paper: white, or the brand's dark colour (deepened, so a light brand colour still prints dark) */
            --paper: #fff; --ink: #1b2233; --head: var(--s2); --muted: #66708a; --soft: #3a4560; --line: #e3e7ee; --leader: #b8c0cf;
            --card: color-mix(in srgb, var(--s2) 5%, #fff); --card-line: color-mix(in srgb, var(--s2) 22%, #fff); }
        @if ($dark)
            :root { --paper: color-mix(in srgb, var(--s2) 45%, #070a12); --ink: #f2f4f8; --head: #fff; --muted: #aab3c5; --soft: #ced5e2;
                --line: rgba(255,255,255,.16); --leader: rgba(255,255,255,.3); --card: rgba(255,255,255,.06); --card-line: rgba(255,255,255,.16); }
            /* a dark logo or the QR would vanish on dark paper: each sits on a small white tile */
            .logo-tile, .qr { background: #fff; border-radius: 3mm; padding: 1.5mm; }
        @endif
        @page { size: A4; margin: 0; }
        html, body { background: #e9edf3; }
        body { font-family: '{{ $settings['font'] }}', 'Cairo', sans-serif; color: var(--ink); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { width: 210mm; height: 297mm; margin: 8mm auto; background: var(--paper); padding: 12mm 11mm; display: flex; flex-direction: column; box-shadow: 0 6px 30px rgba(0,0,0,.12); overflow: hidden; }
        .cols { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 9mm; min-height: 0; }
        .slot { overflow: hidden; min-height: 0; }
        .cat { margin-bottom: calc(5mm * var(--s)); break-inside: avoid; }
        .cat-head { display: flex; align-items: center; gap: 2mm; color: var(--head); font-weight: 800; font-size: calc(17px * var(--s)); border-bottom: 2px solid var(--p); padding-bottom: 1mm; margin-bottom: calc(2mm * var(--s)); }
        .cat-head .en { color: var(--p); font-weight: 600; font-size: .7em; margin-inline-start: auto; }
        .cat-head.is-cont::after { content: '…'; opacity: .5; }
        .item { display: flex; gap: 2.5mm; align-items: flex-start; padding: calc(1.3mm * var(--s)) 0; }
        .item img { width: calc(15mm * var(--s)); height: calc(15mm * var(--s)); object-fit: cover; border-radius: 2mm; flex-shrink: 0; }
        .item .body { flex: 1; min-width: 0; }
        .item .line { display: flex; align-items: baseline; gap: 1.5mm; font-size: calc(13px * var(--s)); font-weight: 700; }
        .item .dots { flex: 1; border-bottom: 1px dotted var(--leader); transform: translateY(-1mm); }
        .item .price { color: var(--p); font-weight: 800; white-space: nowrap; }
        .item .en { font-size: calc(10.5px * var(--s)); color: var(--muted); font-weight: 600; }
        .item .desc { font-size: calc(10px * var(--s)); color: var(--muted); margin-top: .3mm; }
        .item .sizes { font-size: calc(10.5px * var(--s)); color: var(--soft); }
        header.top { display: flex; align-items: center; justify-content: space-between; gap: 6mm; border-bottom: 3px solid var(--head); padding-bottom: 4mm; margin-bottom: 5mm; }
        header.top h1 { font-size: 26px; font-weight: 800; color: var(--head); margin: 0; }
        header.top p { margin: 0; color: var(--muted); font-size: 12px; }
        footer.bottom { display: flex; align-items: center; gap: 5mm; border-top: 2px solid var(--line); padding-top: 3mm; margin-top: 3mm; font-size: 11px; color: var(--soft); }
        footer.bottom .qr { width: 24mm; } footer.bottom .qr svg { width: 100%; height: auto; }
        /* the options bar keeps its own dark text on dark paper */
        .no-print { color: #1b2233; }
        #pool { position: absolute; visibility: hidden; width: 90mm; inset-inline-start: -9999px; }
        @media print { html, body { background: var(--paper); } .sheet { margin: 0; box-shadow: none; } .no-print, #pool { display: none !important; } }
        @if ($cards)
            @page { size: A4 landscape; margin: 0; }
            .sheet { width: 297mm; height: 210mm; padding: 9mm; }
            .sheet .cols { grid-template-columns: repeat(4, 1fr); gap: 5mm; }
            /* four plain cards alike, in the paper's own colours */
            .slot { border-radius: 5mm; padding: 5mm 4.5mm; background: var(--card); border: 1.5px solid var(--card-line); }
            .cat-head { border-bottom: 2px solid var(--p); justify-content: flex-start; font-size: calc(18px * var(--s)); }
            .cat-head .photo { width: calc(14mm * var(--s)); height: calc(14mm * var(--s)); border-radius: 50%; object-fit: cover; margin-inline-start: auto; flex-shrink: 0; }
            /* the category's photo by its title; once, not on a continued card */
            .cat-head.is-cont .photo { display: none; }
            .row { display: grid; grid-template-columns: 1fr auto 11mm; align-items: baseline; gap: 2mm; font-size: calc(14px * var(--s)); font-weight: 700; line-height: 1.35; }
            .item-block { padding: calc(.5mm * var(--s)) 0; }
            .row .size { color: var(--p); font-size: .9em; white-space: nowrap; }
            .row .price { text-align: end; font-weight: 800; }
            .row .en { grid-column: 1 / -1; font-size: .72em; color: var(--muted); font-weight: 600; }
            .row .price { color: var(--head); }
            .item.item-block { display: block; }
            footer.bottom { border: 0; margin-top: 4mm; padding: 0; justify-content: space-between; }
            footer.bottom .qr { width: 20mm; }
            .call { display: inline-flex; flex-direction: column; align-items: center; border: 2px solid var(--p); border-radius: 4mm; overflow: hidden; }
            .call b { background: var(--p); color: #fff; padding: 1mm 6mm; font-size: 15px; }
            .call span { padding: .5mm 5mm; font-size: 19px; font-weight: 800; color: var(--ink); }
        @endif
        @if ($ribbons)
            @page { size: A4 landscape; margin: 0; }
            .sheet { width: 297mm; height: 210mm; padding: 0 0 7mm; }
            .band { flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; height: 30mm; padding: 0 14mm; margin-bottom: 6mm; background: color-mix(in srgb, var(--s2) 12%, var(--paper)); }
            .band img { height: 22mm; }
            .band h1 { margin: 0; color: var(--p); font-size: 34px; font-weight: 800; letter-spacing: .04em; }
            .band p { margin: 0; color: var(--muted); font-size: 15px; letter-spacing: .3em; }
            .sheet .cols { grid-template-columns: repeat(3, 1fr); gap: 0; padding: 0 6mm; }
            .slot { padding: 0 6mm; }
            .slot + .slot { border-inline-start: 1.5px solid var(--line); }
            .cat-head { display: inline-flex; border: 0; background: var(--p); color: #fff; font-size: calc(14px * var(--s)); letter-spacing: .2em; padding: 1.2mm 9mm 1.2mm 4mm; clip-path: polygon(0 0, 100% 0, calc(100% - 4mm) 50%, 100% 100%, 0 100%); margin-bottom: calc(2mm * var(--s)); }
            [dir=rtl] .cat-head { letter-spacing: 0; padding: 1.2mm 4mm 1.2mm 9mm; clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, 4mm 50%); }
            .cat-head .en { color: #fff; opacity: .75; letter-spacing: 0; margin-inline-start: 3mm; }
            .item .line { font-weight: 500; }
            .flavours { font-size: .72em; color: var(--soft); }
            .item .price { color: var(--ink); font-weight: 600; }
            .tag { background: var(--p); color: #fff; font-size: .62em; font-weight: 700; padding: .2mm 1.2mm; border-radius: .6mm; margin-inline-start: 1.5mm; }
            .item { padding: calc(.75mm * var(--s)) 0; }
            .band .qr-call { display: flex; align-items: center; gap: 3mm; color: var(--soft); font-size: 11px; font-weight: 600; }
            .band .qr { width: 20mm; } .band .qr svg { width: 100%; height: auto; display: block; }
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
                <option value="cards" @selected($cards)>{{ __('Cards (landscape)') }}</option>
                <option value="ribbons" @selected($ribbons)>{{ __('Ribbon titles (landscape)') }}</option>
            </select></div>
        <div><label class="field-label">{{ __('Paper') }}</label>
            <select class="field min-h-9 py-0" name="theme" onchange="this.form.submit()">
                <option value="light" @selected(! $dark)>{{ __('Light') }}</option>
                <option value="dark" @selected($dark)>{{ __('Dark') }}</option>
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
        {{-- the cards template: four plain cards, the logo, «order now» and the QR under them --}}
        <div class="sheet">
            <div class="cols">
                @for ($s = 0; $s < 4; $s++)<div class="slot"></div>@endfor
            </div>
            @if ($p === $pages)
                <footer class="bottom">
                    @if ($settings['logo'])<img class="logo-tile" src="/storage/{{ $settings['logo'] }}" alt="" style="height: 22mm">@else<b style="color: var(--head); font-size: 22px">{{ $settings['brand_name'] }}</b>@endif
                    @if ($phone)<div class="call"><b>📞 {{ __('Order now') }}</b><span dir="ltr">{{ $phone }}</span></div>@endif
                    <div class="qr">{!! $qr !!}</div>
                </footer>
            @endif
        </div>
        @continue
    @endif
    <div class="sheet">
        @if ($ribbons)
            {{-- the ribbons template: the brand band with the logo on top of every page --}}
            <div class="band">
                <div class="qr-call"><div class="qr">{!! $qr !!}</div><span>{{ __('Scan to see the menu and order', [], $mainLang === 'ar' ? 'ar' : 'en') }}</span></div>
                @if ($settings['logo'])<img class="logo-tile" src="/storage/{{ $settings['logo'] }}" alt="">
                @else<div><h1>{{ $mainLang !== 'ar' ? ($settings['brand_name_en'] ?: $settings['brand_name']) : $settings['brand_name'] }}</h1>
                    @if ($t = $mainLang !== 'ar' ? $settings['tagline_en'] : $settings['tagline'])<p>{{ $t }}</p>@endif</div>@endif
            </div>
            <div class="cols"><div class="slot"></div><div class="slot"></div><div class="slot"></div></div>
        @endif
        {{-- the ribbons carry the QR in the band of every page, so no footer --}}
        @if ($p === 1 && ! $ribbons)
            <header class="top">
                <div>
                    <h1>{{ $mainLang !== 'ar' ? ($settings['brand_name_en'] ?: $settings['brand_name']) : $settings['brand_name'] }}</h1>
                    @if ($t = $mainLang !== 'ar' ? $settings['tagline_en'] : $settings['tagline'])<p>{{ $t }}</p>@endif
                </div>
                @if ($settings['logo'])<img class="logo-tile" src="/storage/{{ $settings['logo'] }}" alt="" style="height: 20mm">@endif
            </header>
        @endif
        @unless ($ribbons)<div class="cols"><div class="slot"></div><div class="slot"></div></div>@endunless
        @if ($p === $pages && ! $ribbons)
            <footer class="bottom">
                <div class="qr">{!! $qr !!}</div>
                <div>
                    <b style="color: var(--head)">{{ __('Scan to see the menu and order', [], $mainLang === 'ar' ? 'ar' : 'en') }}</b><br>
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
                            <span>{{ $text($item, 'name', $mainLang) }}@if ($ribbons && count($prices) === 1 && ($flavours = $flavourList($item)))<small class="flavours"> ({{ $flavours }})</small>@endif</span>
                            <span class="dots"></span>
                            @if (count($prices) === 1)<span class="price">{{ money($prices[0][1]) }}</span>
                            @elseif ($ribbons)@foreach ($sizeTags($prices) as $i => $tag)<span class="tag">{{ $tag }}</span><span class="price">{{ money($prices[$i][1]) }}</span>@endforeach
                            @endif
                        </div>
                        @if (($second = $text($item, 'name', $secondLang)) && $second !== $text($item, 'name', $mainLang))<div class="en" dir="{{ \App\Support\MenuLocales::dir($secondLang) }}">{{ $second }}</div>@endif
                        @if (count($prices) > 1 && ! $ribbons)
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
