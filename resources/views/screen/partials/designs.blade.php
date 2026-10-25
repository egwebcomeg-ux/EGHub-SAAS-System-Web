{{--
    The ready designs of a screen's "promo" slide (App\Support\ScreenDesigns), drawn by the player and by the
    dashboard's preview page from the same code. Old TV browsers: ES5 only, flexbox with -webkit- prefixes, no grid,
    no CSS variables, no `gap`. Solid colours only (the owner's rule): no gradients, glows or shadows.
    Sizes are rem: 1rem is 1% of the short side of the area the design fills, so it reads the same on any screen and
    follows the screen's text size. window.EgDesigns.html(key, data, {portrait}) returns the slide's HTML;
    EgDesigns.tick(node, serverNowMs) updates its countdown; EgDesigns.fit(node) shrinks a long title to fit.
--}}
<style>
.dz { position: absolute; left: 0; top: 0; width: 100%; height: 100%; overflow: hidden; line-height: 1.15; font-size: 4rem; }
.dz * { box-sizing: border-box; }
.dz .fx { display: -webkit-box; display: -webkit-flex; display: flex; -webkit-box-orient: horizontal; -webkit-flex-direction: row; flex-direction: row; }
.dz .fxc { display: -webkit-box; display: -webkit-flex; display: flex; -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; }
.dz .fxc > * { -webkit-flex-shrink: 0; flex-shrink: 0; }
.dz .info, .dz .band, .dz .tx, .dz .p2, .dz .half.a { overflow: hidden; }
.dz .f1 { -webkit-box-flex: 1; -webkit-flex: 1 1 0; flex: 1 1 0; min-width: 0; min-height: 0; }
.dz .mid { -webkit-box-pack: center; -webkit-justify-content: center; justify-content: center; }
.dz .aic { -webkit-box-align: center; -webkit-align-items: center; align-items: center; }
.dz .tc { text-align: center; }
.dz .ph { position: relative; overflow: hidden; }
.dz .ph img { position: absolute; left: 0; top: 0; width: 100%; height: 100%; -o-object-fit: cover; object-fit: cover; }
.dz .ph .ini { position: absolute; left: 0; top: 0; width: 100%; height: 100%; display: -webkit-box; display: -webkit-flex; display: flex; -webkit-box-align: center; -webkit-align-items: center; align-items: center; -webkit-box-pack: center; -webkit-justify-content: center; justify-content: center; font-size: 22rem; font-weight: 900; opacity: .35; }
.dz-logo { height: 11rem; max-width: 34rem; -o-object-fit: contain; object-fit: contain; background: #fff; border-radius: 1.4rem; padding: .7rem; display: block; }
.dz-brand { font-size: 4.4rem; font-weight: 800; }
.dz-title { font-size: 10.5rem; font-weight: 900; line-height: 1.12; overflow: hidden; word-wrap: break-word; max-height: 3.5em; }
.dz-title small { display: block; font-size: .42em; font-weight: 700; opacity: .75; margin-top: .25em; }
.dz-desc { font-size: 4.2rem; font-weight: 600; line-height: 1.35; opacity: .85; overflow: hidden; max-height: 11.5rem; margin-top: 1.8rem; }
.dz-price { font-weight: 900; white-space: nowrap; display: inline-block; }
.dz-price b { font-size: 14rem; font-weight: 900; line-height: 1.05; }
.dz-price i { font-style: normal; font-size: 4.8rem; font-weight: 800; margin: 0 1.2rem; }
.dz-was { font-size: 5.6rem; font-weight: 700; opacity: .65; text-decoration: line-through; direction: ltr; unicode-bidi: embed; display: inline-block; white-space: nowrap; }
.dz-badge { display: inline-block; font-size: 4.4rem; font-weight: 900; padding: 1.1rem 3rem; border-radius: 10rem; white-space: nowrap; }
.dz-cd { font-size: 4.2rem; font-weight: 800; margin-top: 2.6rem; }
.dz-cd b { display: inline-block; direction: ltr; unicode-bidi: embed; font-size: 6.4rem; font-weight: 900; padding: .6rem 2.2rem; border-radius: 1.4rem; margin: 0 1.4rem; }
.portrait .dz .info { text-align: center; -webkit-box-align: center; -webkit-align-items: center; align-items: center; }
.portrait .dz .info .dz-row { -webkit-box-pack: center; -webkit-justify-content: center; justify-content: center; }
.dz-row { margin-top: 2.4rem; }
.dz-row > * { margin-left: 2.4rem; }
.ltr .dz-row > *, .dz-row.ltr > * { margin-left: 0; margin-right: 2.4rem; }

/* 1 spotlight: photo on one side, words on the other (stacked when portrait) */
.dz-spotlight .wrap { position: absolute; left: 4%; right: 4%; top: 7%; bottom: 7%; }
.dz-spotlight .ph { -webkit-box-flex: 0; -webkit-flex: 0 0 52%; flex: 0 0 52%; border-radius: 3rem; }
.dz-spotlight .info { padding: 0 5rem; }
.portrait .dz-spotlight .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; }
.portrait .dz-spotlight .ph { -webkit-flex: 0 0 50%; flex: 0 0 50%; }
.portrait .dz-spotlight .info { padding: 5rem 1rem 0; }

/* 2 poster: the photo fills the top, a solid band under it */
.dz-poster .ph { position: absolute; left: 0; top: 0; width: 100%; height: 64%; }
.dz-poster .band { position: absolute; left: 0; bottom: 0; width: 100%; height: 36%; padding: 0 5%; }
.dz-poster .dz-title { font-size: 9rem; max-height: 21rem; }
.dz-poster .dz-logo { position: absolute; top: 4rem; height: 10rem; }
.rtl .dz-poster .dz-logo { right: 4rem; } .ltr .dz-poster .dz-logo { left: 4rem; }
.portrait .dz-poster .ph { height: 60%; }
.portrait .dz-poster .band { height: 40%; -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; -webkit-box-pack: center; -webkit-justify-content: center; justify-content: center; text-align: center; }
.portrait .dz-poster .band .f1 { -webkit-flex: none; flex: none; }

/* 3 price tag: the photo full screen, a round tag with the price, the name on a solid strip */
.dz-price-tag .ph { position: absolute; left: 0; top: 0; width: 100%; height: 100%; }
.dz-price-tag .tag { position: absolute; bottom: 7%; width: 40rem; height: 40rem; border-radius: 50%; text-align: center; padding-top: 9rem; }
.rtl .dz-price-tag .tag { left: 5%; } .ltr .dz-price-tag .tag { right: 5%; }
.dz-price-tag .tag .dz-price b { font-size: 12rem; }
.dz-price-tag .tag .dz-price i { display: block; margin: 0; font-size: 4.2rem; }
.dz-price-tag .strip { position: absolute; top: 7%; max-width: 62%; padding: 2.6rem 4rem; border-radius: 2.4rem; }
.rtl .dz-price-tag .strip { right: 5%; } .ltr .dz-price-tag .strip { left: 5%; }
.dz-price-tag .strip .dz-title { font-size: 8.6rem; max-height: 20rem; }
.portrait .dz-price-tag .strip { max-width: 90%; }

/* 4 two colours: words on a solid brand half, the photo on the other */
.dz-split .half { position: absolute; top: 0; width: 50%; height: 100%; }
.dz-split .a { padding: 0 5rem; }
.rtl .dz-split .a { right: 0; } .ltr .dz-split .a { left: 0; }
.rtl .dz-split .b { left: 0; } .ltr .dz-split .b { right: 0; }
.dz-split .b .ph { position: absolute; left: 7%; right: 7%; top: 9%; bottom: 9%; border-radius: 3rem; }
.portrait .dz-split .half { width: 100%; height: 50%; left: 0; right: auto; }
.portrait .dz-split .a { top: auto; bottom: 0; text-align: center; }
.portrait .dz-split .b { top: 0; }

/* 5 new item: a big «جديد» */
.dz-new-item .wrap { position: absolute; left: 5%; right: 5%; top: 8%; bottom: 8%; }
.dz-new-item .big { font-size: 20rem; font-weight: 900; line-height: 1; }
.dz-new-item .ph { -webkit-flex: 0 0 46%; flex: 0 0 46%; border-radius: 50%; height: 0; padding-top: 46%; -webkit-align-self: center; align-self: center; }
.dz-new-item .info { padding: 0 5rem; }
.portrait .dz-new-item .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column-reverse; flex-direction: column-reverse; text-align: center; }
.portrait .dz-new-item .ph { -webkit-flex: none; flex: none; width: 78rem; padding-top: 78rem; margin: 0 auto; }
.portrait .dz-new-item .info { padding: 0 0 4rem; }

/* 6 discount: the old price crossed out, the new one big, the percent in a circle */
.dz-discount .wrap { position: absolute; left: 5%; right: 5%; top: 8%; bottom: 8%; }
.dz-discount .pct { width: 30rem; height: 30rem; border-radius: 50%; font-size: 9rem; font-weight: 900; text-align: center; padding-top: 9.5rem; direction: ltr; }
.dz-discount .dz-was { font-size: 7rem; }
.dz-discount .dz-price b { font-size: 16rem; }
.dz-discount .ph { -webkit-flex: 0 0 40%; flex: 0 0 40%; border-radius: 3rem; }
.dz-discount .info { padding: 0 5rem; }
.portrait .dz-discount .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; text-align: center; }
.portrait .dz-discount .ph { -webkit-flex: 0 0 40%; flex: 0 0 40%; }
.portrait .dz-discount .info { padding: 4rem 0 0; -webkit-box-align: center; -webkit-align-items: center; align-items: center; }

/* 7 clean: centred, a round photo */
.dz-minimal .wrap { position: absolute; left: 6%; right: 6%; top: 6%; bottom: 6%; }
.dz-minimal .ph { width: 52rem; height: 52rem; border-radius: 50%; margin: 0 auto 3.4rem; }
.dz-minimal .line { width: 16rem; height: .8rem; margin: 2.6rem auto; border-radius: 1rem; }
.dz-minimal .dz-title { font-size: 9.2rem; max-height: 21rem; }
.portrait .dz-minimal .ph { width: 74rem; height: 74rem; }

/* 8 frame: the photo inside a thick frame of the brand colour */
.dz-frame .wrap { position: absolute; left: 5%; right: 5%; top: 8%; bottom: 8%; }
.dz-frame .ph { -webkit-flex: 0 0 54%; flex: 0 0 54%; border-style: solid; border-width: 2rem; border-radius: 3.4rem; }
.dz-frame .info { padding: 0 5rem; }
.dz-frame .chip { display: inline-block; padding: 1.4rem 4rem; border-radius: 2rem; margin-top: 3rem; }
.dz-frame .chip .dz-price b { font-size: 9rem; }
.portrait .dz-frame .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; text-align: center; }
.portrait .dz-frame .ph { -webkit-flex: 0 0 52%; flex: 0 0 52%; }
.portrait .dz-frame .info { padding: 4rem 0 0; }

/* 9 stamp: a round price stamp on the corner of the photo */
.dz-stamp .wrap { position: absolute; left: 5%; right: 5%; top: 7%; bottom: 7%; }
.dz-stamp .phw { position: relative; -webkit-flex: 0 0 56%; flex: 0 0 56%; }
.dz-stamp .ph { position: absolute; left: 0; top: 0; width: 100%; height: 100%; border-radius: 3rem; }
.dz-stamp .st { position: absolute; bottom: -2rem; width: 32rem; height: 32rem; border-radius: 50%; border: 1.2rem solid; text-align: center; padding-top: 7.5rem; -webkit-transform: rotate(-8deg); transform: rotate(-8deg); }
.rtl .dz-stamp .st { left: -2rem; } .ltr .dz-stamp .st { right: -2rem; }
.dz-stamp .st .dz-price b { font-size: 9.6rem; }
.dz-stamp .st .dz-price i { display: block; margin: 0; font-size: 3.6rem; }
.dz-stamp .info { padding: 0 6rem; }
.portrait .dz-stamp .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; text-align: center; }
.portrait .dz-stamp .phw { -webkit-flex: 0 0 56%; flex: 0 0 56%; }
.portrait .dz-stamp .info { padding: 6rem 0 0; }

/* 10 combo: what is in the meal, one per line, and its price */
.dz-combo .wrap { position: absolute; left: 5%; right: 5%; top: 8%; bottom: 8%; }
.dz-combo .ph { -webkit-flex: 0 0 46%; flex: 0 0 46%; border-radius: 3rem; }
.dz-combo .info { padding: 0 5rem; }
.dz-combo ul { list-style: none; margin: 2.4rem 0 0; padding: 0; font-size: 3.8rem; font-weight: 700; }
.dz-combo li { margin: 0 0 1.1rem; }
.dz-combo li span { display: inline-block; width: 1.6rem; height: 1.6rem; border-radius: 50%; margin: 0 0 .4rem 1.4rem; vertical-align: middle; }
.ltr .dz-combo li span { margin: 0 1.4rem .4rem 0; }
.portrait .dz-combo .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; }
.portrait .dz-combo .ph { -webkit-flex: 0 0 42%; flex: 0 0 42%; }
.portrait .dz-combo .info { padding: 4rem 1rem 0; }

/* 11 card: one card in the middle, like a menu page */
.dz-menu-card .card { position: absolute; top: 7%; bottom: 7%; left: 50%; width: 72rem; margin-left: -36rem; border-radius: 3.4rem; overflow: hidden; }
.dz-menu-card .ph { height: 50%; }
.dz-menu-card .tx { padding: 3.4rem 4rem; }
.dz-menu-card .dz-title { font-size: 7.8rem; max-height: 18rem; }
.dz-menu-card .dz-price b { font-size: 11rem; }
.dz-menu-card .side { position: absolute; top: 7%; width: 30rem; }
.rtl .dz-menu-card .side { right: 5%; } .ltr .dz-menu-card .side { left: 5%; }
.portrait .dz-menu-card .card { width: 86rem; margin-left: -43rem; top: 16%; bottom: 6%; }
.portrait .dz-menu-card .side { top: 4%; width: auto; left: 7%; right: 7%; text-align: center; }

/* 12 coupon: a cut-out ticket */
.dz-ticket .tk { position: absolute; left: 7%; right: 7%; top: 16%; bottom: 16%; border: .9rem dashed; border-radius: 3.4rem; }
.dz-ticket .p1 { -webkit-flex: 0 0 40%; flex: 0 0 40%; border-style: dashed; border-width: 0; }
.rtl .dz-ticket .p1 { border-left-width: .9rem; } .ltr .dz-ticket .p1 { border-right-width: .9rem; }
.dz-ticket .p2 { padding: 0 5rem; }
.dz-ticket .dz-price b { font-size: 14rem; }
.portrait .dz-ticket .tk { top: 10%; bottom: 10%; -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; }
.portrait .dz-ticket .p1 { -webkit-flex: 0 0 40%; flex: 0 0 40%; border-left-width: 0; border-right-width: 0; border-bottom-width: .9rem; }
.portrait .dz-ticket .p2 { padding: 4rem; text-align: center; }

/* 13 countdown: the time left in big boxes */
.dz-countdown .wrap { position: absolute; left: 5%; right: 5%; top: 8%; bottom: 8%; }
.dz-countdown .boxes { direction: ltr; margin-top: 3.6rem; }
.dz-countdown .bx { width: 21rem; padding: 2.6rem 0 2rem; border-radius: 2.4rem; text-align: center; margin: 0 1.2rem; }
.dz-countdown .bx b { display: block; font-size: 10rem; font-weight: 900; line-height: 1; }
.dz-countdown .bx span { display: block; font-size: 2.8rem; font-weight: 700; margin-top: 1rem; opacity: .8; }
.dz-countdown .ph { -webkit-flex: 0 0 34%; flex: 0 0 34%; border-radius: 3rem; }
.dz-countdown .info { padding: 0 5rem; }
.portrait .dz-countdown .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; text-align: center; }
.portrait .dz-countdown .ph { -webkit-flex: 0 0 36%; flex: 0 0 36%; }
.portrait .dz-countdown .info { padding: 4rem 0 0; -webkit-box-align: center; -webkit-align-items: center; align-items: center; }
.portrait .dz-countdown .bx { width: 19rem; }

/* 14 announcement: big words only */
.dz-headline .wrap { position: absolute; left: 8%; right: 8%; top: 8%; bottom: 8%; }
.dz-headline .dz-title { font-size: 13rem; max-height: 60rem; }
.dz-headline .bar { width: 22rem; height: 1.2rem; border-radius: 1rem; margin: 4rem auto; }
.dz-headline .dz-desc { font-size: 5.6rem; max-height: 22rem; }

/* 15 scan to order: the menu QR, big */
.dz-scan .wrap { position: absolute; left: 6%; right: 6%; top: 8%; bottom: 8%; }
.dz-scan .qr { -webkit-flex: 0 0 auto; flex: 0 0 auto; width: 70rem; height: 70rem; background: #fff; border-radius: 3.4rem; padding: 3rem; }
.dz-scan .qr svg { width: 100%; height: 100%; display: block; }
.dz-scan .info { padding: 0 6rem; }
.portrait .dz-scan .wrap { -webkit-box-orient: vertical; -webkit-flex-direction: column; flex-direction: column; text-align: center; }
.portrait .dz-scan .qr { width: 80rem; height: 80rem; margin: 0 auto; }
.portrait .dz-scan .info { padding: 6rem 0 0; -webkit-box-align: center; -webkit-align-items: center; align-items: center; }
</style>
@verbatim
<script>
(function () {
    'use strict';
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function rgb(hex) {
        var h = String(hex || '').replace('#', '');
        if (h.length === 3) { h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2); }
        if (!/^[0-9a-fA-F]{6}$/.test(h)) { return null; }
        return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
    }
    function lum(hex) {
        var c = rgb(hex);
        if (!c) { return 0; }
        var a = [];
        for (var i = 0; i < 3; i++) { var v = c[i] / 255; a.push(v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)); }
        return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    }
    /* a solid mix of two colours (still one flat colour: no gradient) */
    function mix(a, b, t) {
        var x = rgb(a), y = rgb(b);
        if (!x || !y) { return a; }
        var o = '#';
        for (var i = 0; i < 3; i++) { var v = Math.round(x[i] + (y[i] - x[i]) * t); o += (v < 16 ? '0' : '') + v.toString(16); }
        return o;
    }
    var NAVY = '#0A2540';
    /* readable text on a colour: white on dark ones, navy on light ones */
    function on(hex) { return lum(hex) > 0.42 ? NAVY : '#ffffff'; }
    function palette(d) {
        var p = d.brand.primary || '#F26522', sec = d.brand.secondary || NAVY;
        var dark = lum(sec) < 0.2 ? sec : NAVY;
        if (d.bg === 'light') {
            return { bg: '#ffffff', fg: dark, soft: '#F2F4F8', ac: p, acFg: on(p), line: mix('#ffffff', dark, 0.15) };
        }
        if (d.bg === 'brand') {
            var fg = on(p), white = fg === '#ffffff';
            return { bg: p, fg: fg, soft: mix(p, white ? '#000000' : '#ffffff', 0.14), ac: white ? '#ffffff' : dark, acFg: white ? (lum(p) < 0.42 ? p : dark) : '#ffffff', line: mix(p, fg, 0.3) };
        }
        return { bg: dark, fg: '#ffffff', soft: mix(dark, '#ffffff', 0.09), ac: p, acFg: on(p), line: mix(dark, '#ffffff', 0.25) };
    }
    function two(n) { return (n < 10 ? '0' : '') + n; }
    function left(ms) {
        if (ms < 0) { ms = 0; }
        var s = Math.floor(ms / 1000);
        return { d: Math.floor(s / 86400), h: Math.floor(s % 86400 / 3600), m: Math.floor(s % 3600 / 60), s: s % 60 };
    }
    function countdownText(ms, L) {
        var t = left(ms);
        return (t.d ? t.d + ' ' + L.days + ' ' : '') + two(t.h) + ':' + two(t.m) + ':' + two(t.s);
    }

    /* ---------- shared pieces ---------- */
    function logo(d) { return d.brand.logo ? '<img class="dz-logo" src="' + esc(d.brand.logo) + '" alt="">' : '<div class="dz-brand">' + esc(d.brand.name) + '</div>'; }
    function photo(d, cls, c, style, optional) {
        if (optional && !d.image) { return ''; }
        var ini = (d.title || d.brand.name || '').charAt(0);
        return '<div class="ph ' + (cls || '') + '" style="background:' + c.soft + ';' + (style || '') + '">' + (d.image ? '<img src="' + esc(d.image) + '" alt="">' : '<div class="ini" style="color:' + c.fg + '">' + esc(ini) + '</div>') + '</div>';
    }
    function title(d, style) {
        return '<div class="dz-title" style="' + (style || '') + '">' + esc(d.title) + (d.alt ? '<small>' + esc(d.alt) + '</small>' : '') + '</div>';
    }
    function desc(d) { return d.desc ? '<div class="dz-desc">' + esc(d.desc) + '</div>' : ''; }
    function price(d, color, big) {
        if (!d.price) { return ''; }
        return '<span class="dz-price" style="color:' + color + '"><b' + (big ? ' style="font-size:' + big + 'rem"' : '') + '>' + esc(d.price) + '</b>' + (d.brand.currency ? '<i>' + esc(d.brand.currency) + '</i>' : '') + '</span>';
    }
    function was(d) { return d.was ? '<span class="dz-was">' + esc(d.was) + '</span>' : ''; }
    function badge(d, fallback, c) {
        var t = d.badge || fallback;
        return t ? '<span class="dz-badge" style="background:' + c.ac + ';color:' + c.acFg + '">' + esc(t) + '</span>' : '';
    }
    function cd(d, c) {
        if (!d.ends) { return ''; }
        return '<div class="dz-cd">' + esc(d.labels.ends_in) + '<b data-countdown="' + d.ends + '" style="background:' + c.ac + ';color:' + c.acFg + '">' + esc(countdownText(d.ends - (d.now || Date.now()), d.labels)) + '</b></div>';
    }
    function priceRow(d, c) {
        if (!d.price && !d.was) { return ''; }
        return '<div class="dz-row fx aic">' + price(d, c.ac) + was(d) + '</div>';
    }
    function percent(d) {
        var a = parseFloat(String(d.was || '').replace(/,/g, '')), b = parseFloat(String(d.price || '').replace(/,/g, ''));
        return a > 0 && b > 0 && a > b ? Math.round((a - b) * 100 / a) : 0;
    }

    /* ---------- the designs ---------- */
    var T = {};
    T.spotlight = function (d, c) {
        return '<div class="wrap fx">' + photo(d, '', c, '', true) + '<div class="info f1 fxc mid"><div style="margin-bottom:3rem">' + logo(d) + '</div>' +
            (d.badge ? '<div style="margin-bottom:2.4rem">' + badge(d, '', c) + '</div>' : '') + title(d, 'max-height:30rem') + desc(d) + priceRow(d, c) + cd(d, c) + '</div></div>';
    };
    T.poster = function (d, c) {
        return photo(d, '', c) + logo(d) + '<div class="band fx aic" style="background:' + c.bg + '"><div class="f1">' + title(d) + desc(d) + '</div>' +
            '<div class="tc" style="padding:0 2rem">' + was(d) + '<div>' + price(d, c.ac) + '</div>' + cd(d, c) + '</div></div>';
    };
    T['price-tag'] = function (d, c) {
        return photo(d, '', c) + '<div class="strip" style="background:' + c.bg + ';color:' + c.fg + '">' + (d.badge ? '<div style="margin-bottom:2rem">' + badge(d, '', c) + '</div>' : '') + title(d) + cd(d, c) + '</div>' +
            (d.price ? '<div class="tag" style="background:' + c.ac + ';color:' + c.acFg + '">' + price(d, c.acFg) + (d.was ? '<div class="dz-was" style="font-size:3.6rem">' + esc(d.was) + '</div>' : '') + '</div>' : '');
    };
    T.split = function (d, c) {
        return '<div class="half a info fxc mid" style="background:' + c.ac + ';color:' + c.acFg + '"><div style="margin-bottom:3rem">' + logo(d) + '</div>' + title(d) + desc(d) +
            '<div class="dz-row">' + price(d, c.acFg) + '</div>' + (d.was ? '<div>' + was(d) + '</div>' : '') + cd({ ends: d.ends, labels: d.labels, now: d.now }, { ac: c.bg, acFg: c.fg }) + '</div>' +
            '<div class="half b" style="background:' + c.bg + '">' + photo(d, '', c) + '</div>';
    };
    T['new-item'] = function (d, c) {
        return '<div class="wrap fx"><div class="info f1 fxc mid"><div class="big" style="color:' + c.ac + '">' + esc(d.badge || d.labels['new']) + '</div>' +
            '<div style="margin-top:3rem">' + title(d, 'max-height:28rem') + '</div>' + desc(d) + priceRow(d, c) + cd(d, c) + '</div>' + photo(d, '', c, 'border:1.6rem solid ' + c.ac, true) + '</div>';
    };
    T.discount = function (d, c) {
        var pc = percent(d);
        return '<div class="wrap fx">' + photo(d, '', c, '', true) + '<div class="info f1 fxc mid">' +
            (pc ? '<div class="pct" style="background:' + c.ac + ';color:' + c.acFg + '">-' + pc + '%</div>' : '<div>' + badge(d, d.labels.offer, c) + '</div>') +
            '<div style="margin-top:3rem">' + title(d, 'font-size:8.6rem;max-height:20rem') + '</div>' +
            (d.was ? '<div style="margin-top:2rem;font-size:4.4rem;font-weight:700">' + esc(d.labels.instead_of) + ' ' + was(d) + '</div>' : '') + '<div>' + price(d, c.ac) + '</div>' + cd(d, c) + '</div></div>';
    };
    T.minimal = function (d, c) {
        return '<div class="wrap fxc mid tc">' + photo(d, '', c, '', true) + title(d) + '<div class="line" style="background:' + c.ac + '"></div>' +
            '<div>' + was(d) + ' ' + price(d, c.fg, 9) + '</div>' + cd(d, c) + '</div>';
    };
    T.frame = function (d, c) {
        return '<div class="wrap fx">' + photo(d, '', c, 'border-color:' + c.ac, true) + '<div class="info f1 fxc mid"><div style="margin-bottom:3rem">' + logo(d) + '</div>' + title(d, 'max-height:28rem') + desc(d) +
            (d.price ? '<div><span class="chip" style="background:' + c.ac + ';color:' + c.acFg + '">' + price(d, c.acFg) + '</span></div>' : '') + (d.was ? '<div style="margin-top:1.4rem">' + was(d) + '</div>' : '') + cd(d, c) + '</div></div>';
    };
    T.stamp = function (d, c) {
        return '<div class="wrap fx"><div class="phw">' + photo(d, '', c) + (d.price ? '<div class="st" style="background:' + c.ac + ';color:' + c.acFg + ';border-color:' + c.bg + '">' + price(d, c.acFg) + '</div>' : '') + '</div>' +
            '<div class="info f1 fxc mid">' + (d.badge ? '<div style="margin-bottom:2.4rem">' + badge(d, '', c) + '</div>' : '') + title(d, 'max-height:30rem') + desc(d) + (d.was ? '<div style="margin-top:2rem;font-size:4.4rem;font-weight:700">' + esc(d.labels.instead_of) + ' ' + was(d) + '</div>' : '') + cd(d, c) + '</div></div>';
    };
    T.combo = function (d, c) {
        var parts = String(d.desc || '').split(/\s*[+،,\n]\s*/), list = '';
        for (var i = 0; i < parts.length && i < 6; i++) { if (parts[i]) { list += '<li><span style="background:' + c.ac + '"></span>' + esc(parts[i]) + '</li>'; } }
        return '<div class="wrap fx">' + photo(d, '', c, '', true) + '<div class="info f1 fxc mid"><div style="margin-bottom:2.4rem">' + badge(d, d.labels.combo, c) + '</div>' + title(d, 'font-size:8.8rem;max-height:21rem') +
            (list ? '<ul>' + list + '</ul>' : '') + priceRow(d, c) + cd(d, c) + '</div></div>';
    };
    T['menu-card'] = function (d, c) {
        return '<div class="side">' + logo(d) + (d.badge ? '<div style="margin-top:3rem">' + badge(d, '', c) + '</div>' : '') + '</div>' +
            '<div class="card fxc" style="background:' + c.soft + '">' + photo(d, '', c) + '<div class="tx info f1 fxc mid">' + title(d) + desc(d) + priceRow(d, c) + cd(d, c) + '</div></div>';
    };
    T.ticket = function (d, c) {
        return '<div class="tk fx" style="border-color:' + c.ac + ';background:' + c.soft + '"><div class="p1 fxc mid aic tc" style="border-color:' + c.ac + '">' +
            (d.price ? price(d, c.ac) : '<div class="dz-title" style="color:' + c.ac + '">' + esc(d.badge || d.labels.offer) + '</div>') + (d.was ? '<div>' + was(d) + '</div>' : '') + '</div>' +
            '<div class="p2 info f1 fxc mid"><div style="margin-bottom:2.6rem">' + logo(d) + '</div>' + title(d, 'font-size:8.8rem;max-height:21rem') + desc(d) + cd(d, c) + '</div></div>';
    };
    T.countdown = function (d, c) {
        var boxes = '';
        if (d.ends) {
            var t = left(d.ends - (d.now || Date.now())), u = d.lang === 'en' ? ['days', 'hours', 'min', 'sec'] : ['يوم', 'ساعة', 'دقيقة', 'ثانية'];
            var v = [t.d, two(t.h), two(t.m), two(t.s)], k = ['d', 'h', 'm', 's'];
            boxes = '<div class="boxes fx" data-boxes="' + d.ends + '">';
            for (var i = t.d ? 0 : 1; i < 4; i++) { boxes += '<div class="bx" style="background:' + c.soft + '"><b data-part="' + k[i] + '" style="color:' + c.ac + '">' + v[i] + '</b><span>' + esc(u[i]) + '</span></div>'; }
            boxes += '</div>';
        }
        return '<div class="wrap fx">' + photo(d, '', c, '', true) + '<div class="info f1 fxc mid"><div>' + badge(d, d.labels.offer, c) + '</div><div style="margin-top:2.6rem">' + title(d, 'font-size:8.6rem;max-height:20rem') + '</div>' +
            (boxes ? '<div style="font-size:3.4rem;font-weight:800;margin-top:3rem">' + esc(d.labels.ends_in) + '</div>' + boxes : '') + priceRow(d, c) + '</div></div>';
    };
    T.headline = function (d, c) {
        return '<div class="wrap fxc mid tc"><div style="margin:0 auto 5rem">' + logo(d) + '</div>' + title(d) + '<div class="bar" style="background:' + c.ac + '"></div>' + desc(d) +
            (d.price ? '<div style="margin-top:3rem">' + price(d, c.ac, 9) + '</div>' : '') + cd(d, c) + '</div>';
    };
    T.scan = function (d, c) {
        return '<div class="wrap fx aic"><div class="qr">' + (d.brand.qr || '') + '</div><div class="info f1 fxc mid"><div style="margin-bottom:3.4rem">' + logo(d) + '</div>' +
            '<div class="dz-title" style="color:' + c.ac + '">' + esc(d.title || d.labels.scan) + '</div><div class="dz-desc" style="font-size:4.4rem">' + esc(d.desc || d.labels.scan_hint) + '</div></div></div>';
    };

    function html(key, d, opts) {
        var c = palette(d), fn = T[key] || T.spotlight;
        return '<div class="dz dz-' + esc(T[key] ? key : 'spotlight') + '" style="background:' + c.bg + ';color:' + c.fg + '">' + fn(d, c) + '</div>';
    }
    function tick(node, now) {
        var els = node.querySelectorAll('[data-countdown]'), i;
        for (i = 0; i < els.length; i++) {
            els[i].textContent = countdownText(parseInt(els[i].getAttribute('data-countdown'), 10) - now, window.EgDesigns.labels || { days: 'd' });
        }
        var boxes = node.querySelectorAll('[data-boxes]');
        for (i = 0; i < boxes.length; i++) {
            var t = left(parseInt(boxes[i].getAttribute('data-boxes'), 10) - now), parts = boxes[i].querySelectorAll('[data-part]');
            for (var j = 0; j < parts.length; j++) {
                var k = parts[j].getAttribute('data-part');
                parts[j].textContent = k === 'd' ? t.d : two(t[k]);
            }
        }
    }
    /* a long name: smaller until it fits the room its design gives it */
    function fit(node) {
        var els = node.querySelectorAll('.dz-title');
        for (var i = 0; i < els.length; i++) {
            var e = els[i], css = window.getComputedStyle(e), size = parseFloat(css.fontSize), n = 0;
            /* only a title its design limits (max-height); Arabic letters reach a little past their line, so allow that */
            if (!css.maxHeight || css.maxHeight === 'none') { continue; }
            while (n < 12 && (e.scrollHeight > e.clientHeight + size * 0.5 || e.scrollWidth > e.clientWidth + size * 0.2) && size > 8) {
                size = size * 0.9;
                e.style.fontSize = size + 'px';
                n++;
            }
        }
    }
    /* a column with more than its room (a small area, long words): its pieces get smaller together */
    function fitBlocks(node) {
        var boxes = node.querySelectorAll('.info, .band, .tx, .p2, .half.a, .strip, .wrap.fxc');
        for (var i = 0; i < boxes.length; i++) {
            var box = boxes[i], kids = box.children, z = 1;
            while (z > 0.5 && (box.scrollHeight > box.clientHeight + 4 || box.scrollWidth > box.clientWidth + 4)) {
                z = Math.round((z - 0.06) * 100) / 100;
                for (var k = 0; k < kids.length; k++) { kids[k].style.zoom = z; }
            }
        }
    }
    function fitAll(node) { fit(node); fitBlocks(node); }
    window.EgDesigns = { html: html, tick: tick, fit: fitAll, on: on, palette: palette, countdownText: countdownText, labels: null };
})();
</script>
@endverbatim
