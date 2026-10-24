{{--
    One share card (1200×630), drawn by headless Chrome from `php artisan egmenu:share-images` (App\Support\ShareCards).
    Same family as public/og-image.svg: navy with the orange glow and dots, the EgHub wordmark (the exact paths of
    components/logo.blade.php, "hub" in white), Cairo. Text on the start side, the page's own drawing on the other.
    Everything important stays inside the middle 1100×580 (WhatsApp and Facebook crop the edges a little).
    $card: ShareCards::all() entry · $pageUrl: the page address (the drawings' QR codes open it) · $domain: the pill ·
    $background: the navy, dots and glow, drawn by the command (Chrome would dither CSS gradients into heavy noise).
--}}
@php
    $dir = app()->getLocale() === 'en' ? 'ltr' : 'rtl';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=cairo:500,600,700,800,900">
<style>
    * { box-sizing: border-box; }
    html, body { margin: 0; width: 1200px; height: 630px; overflow: hidden; }
    body { position: relative; background: #061A2E url('{{ $background }}') no-repeat; color: #fff; font-family: Cairo, sans-serif; -webkit-font-smoothing: antialiased; }

    .text { position: absolute; inset-inline-start: 76px; top: 62px; bottom: 62px; width: 600px; display: flex; flex-direction: column; }
    .legal .text { width: 640px; }
    .logo { display: block; flex: none; width: 147px; height: 52px; }
    .middle { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 10px 0; min-height: 0; }
    .eyebrow { display: inline-flex; align-self: flex-start; align-items: center; gap: 10px; color: #FF854D; font-weight: 800; font-size: 26px; line-height: 1.2; }
    .eyebrow svg { width: 30px; height: 30px; }
    .headline { margin: 14px 0 0; font-weight: 900; font-size: 78px; line-height: 1.22; letter-spacing: -0.5px; text-wrap: balance; }
    .subline { margin: 16px 0 0; color: #C5CFE0; font-weight: 600; font-size: 32px; line-height: 1.45; text-wrap: balance; }
    .pills { display: flex; gap: 14px; align-items: center; }
    .pill { display: inline-flex; align-items: center; height: 56px; padding: 0 28px; border-radius: 999px; border: 2px solid rgba(255,255,255,.25); font-weight: 700; font-size: 25px; direction: ltr; }
    .pill.cta { border-color: #F26522; background: #F26522; direction: inherit; font-weight: 800; }

    .visual { position: absolute; inset-inline-end: 66px; top: 62px; width: 424px; height: 506px; color: #0A2540; }
    [dir=rtl] .visual { direction: rtl; }

    /* drawing parts */
    .abs { position: absolute; }
    .phone { position: absolute; width: 240px; height: 480px; border-radius: 44px; background: #0A2540; border: 10px solid #0B1222; box-shadow: 0 30px 70px rgba(0,0,0,.5); }
    .phone > .scr { position: absolute; inset: 9px; border-radius: 30px; background: #fff; overflow: hidden; }
    .card { position: absolute; background: #fff; color: #0A2540; border-radius: 26px; box-shadow: 0 26px 64px rgba(0,0,0,.42); }
    .toast { position: absolute; display: flex; align-items: center; gap: 12px; background: #fff; color: #0A2540; border-radius: 20px; padding: 12px 20px 12px 16px; font-weight: 800; font-size: 23px; line-height: 1.2; white-space: nowrap; box-shadow: 0 18px 44px rgba(0,0,0,.42); }
    [dir=rtl] .toast { padding: 12px 16px 12px 20px; }
    .ic { flex: none; display: grid; place-items: center; width: 44px; height: 44px; border-radius: 50%; background: #FFF4ED; color: #F26522; }
    .ic svg { width: 26px; height: 26px; }
    .ic.big { width: 64px; height: 64px; }
    .ic.big svg { width: 36px; height: 36px; }
    .ic.solid { background: #F26522; color: #fff; }
    .ic.green { background: #DCFCE7; color: #15803D; }
    .ic.blue { background: #E1EBF5; color: #005BA6; }
    .line { height: 12px; border-radius: 6px; background: #0A2540; }
    .line.soft { background: #E1E7F1; }
    .line.or { background: #F26522; }
    .chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 999px; font-weight: 800; font-size: 18px; line-height: 1.3; white-space: nowrap; }
    .chip.or { background: #F26522; color: #fff; }
    .chip.soft { background: #F0F6FC; color: #00447C; }
    .chip.green { background: #DCFCE7; color: #166534; }
    .chip.amber { background: #FEF3C7; color: #92400E; }
    .chip.red { background: #FFE4E6; color: #9F1239; }
    .t { font-weight: 800; line-height: 1.25; }
    .muted { color: #6B87A3; }
    .row { display: flex; align-items: center; gap: 12px; }
    .thumb { flex: none; width: 46px; height: 46px; border-radius: 12px; }
    .ring { position: absolute; border: 4px solid rgba(255,133,77,.55); border-radius: 50%; }
    svg.i { width: 1em; height: 1em; }
</style>
</head>
<body class="{{ $card['visual'] === 'legal' ? 'legal' : '' }}">

<div class="text">
    <svg class="logo" viewBox="0 -720 2660 940" role="img" aria-label="EgHub" style="direction: ltr">
        <g transform="scale(1 -1)"><g fill="#F26522"><path fill-rule="evenodd" transform="translate(0 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 A250 250 0 0 1 459.7 86.0 L402.1 125.2 A181 181 0 0 0 93.6 213.9 L520.5 214 A250 250 0 0 1 271 500 Z M271 429 A179 179 0 0 0 446.6 284.8 L95.4 284.8 A179 179 0 0 0 271 429 Z"/><path fill-rule="evenodd" transform="translate(552 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 L443.7 0.2 A180 180 0 0 0 271 -129 L138 -129 L138 -199 L271 -199 A250 250 0 0 1 521 51 L521 250 A250 250 0 0 1 271 500 Z M450 250 L450 70 L271 70 A180 180 0 0 0 91 250 A180 180 0 0 0 271 429 A180 180 0 0 0 450 250 Z"/></g><g fill="#ffffff"><path fill-rule="evenodd" transform="translate(1103 0)" d="M20 0 L20 700 L91 700 L91 426.1 A249 249 0 0 0 518 252 L518 0 L447 0 L447 252 A178 178 0 0 1 91 252 L91 0 Z"/><path fill-rule="evenodd" transform="translate(1651 0)" d="M20 500 L20 191 A202.5 191 0 0 1 222.5 0 A202.5 191 0 0 1 425 191 L425 500 L354 500 L354 194 A131 123 0 0 0 223 71 A131 123 0 0 0 92 194 L92 500 Z"/><path fill-rule="evenodd" transform="translate(2106 0)" d="M20 700 L90 700 L90 500 L270 500 A250 250 0 0 0 520 250 A250 250 0 0 0 270 0 A250 250 0 0 0 20 250 Z M90 250 L90 429.5 L270 429.5 A179.5 179.5 0 0 0 449.5 250 A179.5 179.5 0 0 0 270 70.5 A179.5 179.5 0 0 0 90 250 Z"/></g></g>
    </svg>
    <div class="middle">
        @if ($card['eyebrow'] !== 'EgHub')
            <div class="eyebrow"><x-landing.icon :name="$card['icon']" class="i" />{{ $card['eyebrow'] }}</div>
        @endif
        <h1 class="headline" data-fit="80,44,2">{{ $card['headline'] }}</h1>
        @if ($card['subline'] !== '')
            <p class="subline" data-fit="32,23,3">{{ $card['subline'] }}</p>
        @endif
    </div>
    <div class="pills">
        <span class="pill">{{ $domain }}</span>
        @if ($card['cta'])
            <span class="pill cta">{{ __('Start free') }}</span>
        @endif
    </div>
</div>

<div class="visual">
    @include('share-cards.visuals.' . $card['visual'])
</div>

<script>
    // shrink a text until it fits its line budget (data-fit="largest,smallest,lines"), then the whole column
    // load the Cairo weights first: measuring with a fallback font would break the lines in the wrong places
    Promise.all(['900 80px Cairo', '600 32px Cairo', '800 24px Cairo', '700 24px Cairo'].map((font) => document.fonts.load(font, 'ابج abc'))).then(() => document.fonts.ready).then(() => {
        const fit = () => document.querySelectorAll('[data-fit]').forEach((el) => {
            const [max, min, lines] = el.dataset.fit.split(',').map(Number);
            let size = max;
            el.style.fontSize = size + 'px';
            while (size > min && el.getBoundingClientRect().height > parseFloat(getComputedStyle(el).lineHeight) * lines + 2) {
                size -= 1;
                el.style.fontSize = size + 'px';
            }
        });
        fit();
        const middle = document.querySelector('.middle');
        let guard = 0;
        while (middle.scrollHeight > middle.clientHeight + 1 && guard++ < 30) {
            document.querySelectorAll('[data-fit]').forEach((el) => {
                const parts = el.dataset.fit.split(',').map(Number);
                el.dataset.fit = [Math.max(parts[1], parseFloat(el.style.fontSize) - 2), parts[1], parts[2]].join(',');
            });
            fit();
        }
        document.body.dataset.ready = '1';
    });
</script>
</body>
</html>
