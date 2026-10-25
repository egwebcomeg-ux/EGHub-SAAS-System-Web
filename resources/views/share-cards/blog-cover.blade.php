{{--
    A blog article cover (1600×900, the 16:9 of ImageService's "post" profile), drawn by headless Chrome from
    `php artisan egmenu:blog-covers`. A solid background (navy or white, no gradients or glows) with flat dots, the
    article's own illustration (share-cards/blog-art/{art}) on a light disc, two or three words and the small EgHub logo.
    Everything important stays inside the middle 1480×780, so the 1200×630 share crop keeps it.
    $card: BlogSeeder::coverCards() entry {label, art, theme}
--}}
@php
    $isWhite = $card['theme'] === 'white';
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=cairo:800,900">
<style>
    * { box-sizing: border-box; }
    html, body { margin: 0; width: 1600px; height: 900px; overflow: hidden; }
    body { position: relative; background: {{ $isWhite ? '#FFFFFF' : '#061A2E' }}; color: {{ $isWhite ? '#061A2E' : '#FFFFFF' }}; font-family: Cairo, sans-serif; -webkit-font-smoothing: antialiased; }
    .dots { position: absolute; inset: 0; }
    .disc { position: absolute; left: 150px; top: 110px; width: 680px; height: 680px; border-radius: 50%; background: {{ $isWhite ? '#EEF3F8' : '#F4F7FB' }}; }
    .art { position: absolute; left: 110px; top: 70px; width: 760px; height: 760px; }
    .text { position: absolute; right: 120px; top: 150px; bottom: 150px; width: 600px; display: flex; flex-direction: column; justify-content: center; }
    .logo { position: absolute; top: 0; right: 0; width: 190px; height: 67px; }
    .label { margin: 0; font-weight: 900; font-size: 150px; line-height: 1.2; text-wrap: balance; }
    .bar { margin-top: 34px; width: 150px; height: 16px; border-radius: 8px; background: #F26522; }
</style>
</head>
<body>
<svg class="dots" width="1600" height="900" aria-hidden="true">
    <defs><pattern id="dots" width="28" height="28" patternUnits="userSpaceOnUse"><circle cx="14" cy="14" r="1.7" fill="{{ $isWhite ? '#061A2E' : '#FFFFFF' }}" fill-opacity="{{ $isWhite ? '.07' : '.075' }}"/></pattern></defs>
    <rect width="1600" height="900" fill="url(#dots)"/>
</svg>
<div class="disc"></div>
@include('share-cards.blog-art.' . $card['art'])

<div class="text">
    <svg class="logo" viewBox="0 -720 2660 940" role="img" aria-label="EgHub" style="direction: ltr">
        <g transform="scale(1 -1)"><g fill="#F26522"><path fill-rule="evenodd" transform="translate(0 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 A250 250 0 0 1 459.7 86.0 L402.1 125.2 A181 181 0 0 0 93.6 213.9 L520.5 214 A250 250 0 0 1 271 500 Z M271 429 A179 179 0 0 0 446.6 284.8 L95.4 284.8 A179 179 0 0 0 271 429 Z"/><path fill-rule="evenodd" transform="translate(552 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 L443.7 0.2 A180 180 0 0 0 271 -129 L138 -129 L138 -199 L271 -199 A250 250 0 0 1 521 51 L521 250 A250 250 0 0 1 271 500 Z M450 250 L450 70 L271 70 A180 180 0 0 0 91 250 A180 180 0 0 0 271 429 A180 180 0 0 0 450 250 Z"/></g><g fill="{{ $isWhite ? '#061A2E' : '#ffffff' }}"><path fill-rule="evenodd" transform="translate(1103 0)" d="M20 0 L20 700 L91 700 L91 426.1 A249 249 0 0 0 518 252 L518 0 L447 0 L447 252 A178 178 0 0 1 91 252 L91 0 Z"/><path fill-rule="evenodd" transform="translate(1651 0)" d="M20 500 L20 191 A202.5 191 0 0 1 222.5 0 A202.5 191 0 0 1 425 191 L425 500 L354 500 L354 194 A131 123 0 0 0 223 71 A131 123 0 0 0 92 194 L92 500 Z"/><path fill-rule="evenodd" transform="translate(2106 0)" d="M20 700 L90 700 L90 500 L270 500 A250 250 0 0 0 520 250 A250 250 0 0 0 270 0 A250 250 0 0 0 20 250 Z M90 250 L90 429.5 L270 429.5 A179.5 179.5 0 0 0 449.5 250 A179.5 179.5 0 0 0 270 70.5 A179.5 179.5 0 0 0 90 250 Z"/></g></g>
    </svg>
    <h1 class="label" data-fit="150,96,2">{{ $card['label'] }}</h1>
    <div class="bar"></div>
</div>

<script>
    Promise.all(['900 150px Cairo', '800 40px Cairo'].map((font) => document.fonts.load(font, 'ابج abc'))).then(() => document.fonts.ready).then(() => {
        document.querySelectorAll('[data-fit]').forEach((el) => {
            const [max, min, lines] = el.dataset.fit.split(',').map(Number);
            let size = max;
            el.style.fontSize = size + 'px';
            while (size > min && (el.getBoundingClientRect().height > parseFloat(getComputedStyle(el).lineHeight) * lines + 2 || el.scrollWidth > el.clientWidth + 1)) {
                size -= 1;
                el.style.fontSize = size + 'px';
            }
        });
        document.body.dataset.ready = '1';
    });
</script>
</body>
</html>
