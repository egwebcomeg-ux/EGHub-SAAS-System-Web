{{--
    A blog article cover (1600×900, the 16:9 of ImageService's "post" profile), drawn by headless Chrome from
    `php artisan egmenu:blog-covers`. Same family as the share cards (card.blade.php): navy with the orange glow and
    dots, the EgHub wordmark, Cairo; the article's own icon and topic chips on the end side.
    Everything important stays inside the middle 1480×780, so the 1200×630 share crop keeps it.
    $card: BlogSeeder::coverCards() entry {headline, eyebrow, icon, chips} · $domain
--}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=cairo:600,700,800,900">
<style>
    * { box-sizing: border-box; }
    html, body { margin: 0; width: 1600px; height: 900px; overflow: hidden; }
    body {
        position: relative; color: #fff; font-family: Cairo, sans-serif; -webkit-font-smoothing: antialiased;
        background:
            radial-gradient(52% 70% at 88% 6%, rgba(242, 101, 34, .42), rgba(242, 101, 34, 0) 72%),
            radial-gradient(40% 55% at 70% 100%, rgba(0, 91, 166, .30), rgba(0, 91, 166, 0) 70%),
            radial-gradient(circle at 14px 14px, rgba(255, 255, 255, .075) 1.6px, transparent 2px) 0 0 / 28px 28px,
            #061A2E;
    }
    .text { position: absolute; right: 110px; top: 96px; bottom: 96px; width: 860px; display: flex; flex-direction: column; }
    .logo { display: block; width: 176px; height: 62px; }
    .middle { flex: 1; display: flex; flex-direction: column; justify-content: center; }
    .eyebrow { display: inline-flex; align-self: flex-start; align-items: center; gap: 12px; color: #FF854D; font-weight: 800; font-size: 34px; }
    .eyebrow svg { width: 38px; height: 38px; }
    .headline { margin: 18px 0 0; font-weight: 900; font-size: 92px; line-height: 1.22; text-wrap: balance; }
    .pill { align-self: flex-start; display: inline-flex; align-items: center; height: 64px; padding: 0 32px; border-radius: 999px; border: 2px solid rgba(255,255,255,.25); font-weight: 700; font-size: 28px; direction: ltr; }
    .visual { position: absolute; left: 110px; top: 150px; width: 470px; height: 600px; }
    .tile { position: absolute; left: 40px; top: 40px; width: 390px; height: 390px; border-radius: 56px; background: #fff; color: #F26522; display: grid; place-items: center; box-shadow: 0 40px 90px rgba(0,0,0,.45); transform: rotate(-4deg); }
    .tile svg { width: 190px; height: 190px; }
    .ring { position: absolute; border: 5px solid rgba(255,133,77,.5); border-radius: 50%; }
    .chips { position: absolute; left: 0; right: 0; top: 470px; display: flex; flex-wrap: wrap; gap: 14px; justify-content: center; direction: rtl; }
    .chip { display: inline-flex; align-items: center; height: 54px; padding: 0 24px; border-radius: 999px; background: #fff; color: #0A2540; font-weight: 800; font-size: 26px; box-shadow: 0 16px 36px rgba(0,0,0,.35); }
    .chip:first-child { background: #F26522; color: #0A2540; }
</style>
</head>
<body>
<div class="text">
    <svg class="logo" viewBox="0 -720 2660 940" role="img" aria-label="EgHub" style="direction: ltr">
        <g transform="scale(1 -1)"><g fill="#F26522"><path fill-rule="evenodd" transform="translate(0 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 A250 250 0 0 1 459.7 86.0 L402.1 125.2 A181 181 0 0 0 93.6 213.9 L520.5 214 A250 250 0 0 1 271 500 Z M271 429 A179 179 0 0 0 446.6 284.8 L95.4 284.8 A179 179 0 0 0 271 429 Z"/><path fill-rule="evenodd" transform="translate(552 0)" d="M271 500 A250 250 0 0 1 21 250 A250 250 0 0 1 271 0 L443.7 0.2 A180 180 0 0 0 271 -129 L138 -129 L138 -199 L271 -199 A250 250 0 0 1 521 51 L521 250 A250 250 0 0 1 271 500 Z M450 250 L450 70 L271 70 A180 180 0 0 0 91 250 A180 180 0 0 0 271 429 A180 180 0 0 0 450 250 Z"/></g><g fill="#ffffff"><path fill-rule="evenodd" transform="translate(1103 0)" d="M20 0 L20 700 L91 700 L91 426.1 A249 249 0 0 0 518 252 L518 0 L447 0 L447 252 A178 178 0 0 1 91 252 L91 0 Z"/><path fill-rule="evenodd" transform="translate(1651 0)" d="M20 500 L20 191 A202.5 191 0 0 1 222.5 0 A202.5 191 0 0 1 425 191 L425 500 L354 500 L354 194 A131 123 0 0 0 223 71 A131 123 0 0 0 92 194 L92 500 Z"/><path fill-rule="evenodd" transform="translate(2106 0)" d="M20 700 L90 700 L90 500 L270 500 A250 250 0 0 0 520 250 A250 250 0 0 0 270 0 A250 250 0 0 0 20 250 Z M90 250 L90 429.5 L270 429.5 A179.5 179.5 0 0 0 449.5 250 A179.5 179.5 0 0 0 270 70.5 A179.5 179.5 0 0 0 90 250 Z"/></g></g>
    </svg>
    <div class="middle">
        <div class="eyebrow"><x-landing.icon name="book" />{{ __('Blog') }} · {{ $card['eyebrow'] }}</div>
        <h1 class="headline" data-fit="92,58,3">{{ $card['headline'] }}</h1>
    </div>
    <span class="pill">{{ $domain }}/blog</span>
</div>

<div class="visual">
    <div class="ring" style="left: -10px; top: -10px; width: 490px; height: 490px"></div>
    <div class="ring" style="left: 60px; top: 60px; width: 350px; height: 350px; opacity: .5"></div>
    <div class="tile"><x-landing.icon :name="$card['icon']" /></div>
    <div class="chips">
        @foreach ($card['chips'] as $chip)
            <span class="chip">{{ $chip }}</span>
        @endforeach
    </div>
</div>

<script>
    Promise.all(['900 92px Cairo', '800 26px Cairo', '700 28px Cairo'].map((font) => document.fonts.load(font, 'ابج abc'))).then(() => document.fonts.ready).then(() => {
        document.querySelectorAll('[data-fit]').forEach((el) => {
            const [max, min, lines] = el.dataset.fit.split(',').map(Number);
            let size = max;
            el.style.fontSize = size + 'px';
            while (size > min && el.getBoundingClientRect().height > parseFloat(getComputedStyle(el).lineHeight) * lines + 2) {
                size -= 1;
                el.style.fontSize = size + 'px';
            }
        });
        document.body.dataset.ready = '1';
    });
</script>
</body>
</html>
