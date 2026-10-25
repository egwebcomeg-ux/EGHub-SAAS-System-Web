{{--
    The EgHub screen player: eghub.app/screen (mode "device", paired with a code) and the older /tv/{token} link
    (mode "link", the link is the key). One self-contained page: no Tailwind build and no Vite, because TV browsers are
    old Chromium (Samsung Tizen 3-6 = Chromium 47-76, LG webOS 3-6 = Chrome 38-79). The CSS avoids grid, flex `gap`,
    custom properties, `inset` and dvh; the script is ES5 with Promise/XHR only (no arrows, let/const, async, ?. or ??).
    Sizes are rem, and the root font size is 1% of the screen's short side, so 1080p and 4K look the same.
    A split screen has two areas (zones): each is drawn at the size of a whole screen of its own shape and scaled down
    to its box, so every slide and design reads the same in a side column as on a full screen.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#000000">
<meta name="mobile-web-app-capable" content="yes">
<title>EgHub Screen</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
@fonts
<style>
html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background: #000; }
body { font-family: 'Cairo', 'Segoe UI', Tahoma, 'Noto Sans Arabic', 'Droid Arabic Naskh', Arial, sans-serif; color: #f3f4f6; cursor: none; -webkit-font-smoothing: antialiased; -webkit-user-select: none; user-select: none; }
* { box-sizing: border-box; }
#stage { position: absolute; left: 0; top: 0; width: 100%; height: 100%; }
#frame { position: absolute; left: 0; top: 0; overflow: hidden; background: #0f1420; -webkit-transform-origin: 0 0; transform-origin: 0 0; }
.zone { position: absolute; overflow: hidden; }
.zone-in { position: absolute; left: 0; top: 0; overflow: hidden; -webkit-transform-origin: 0 0; transform-origin: 0 0; }
.layer { position: absolute; left: 0; top: 0; width: 100%; height: 100%; opacity: 0; -webkit-transition: opacity .7s ease; transition: opacity .7s ease; overflow: hidden; }
.layer.on { opacity: 1; }
.slide { position: absolute; left: 0; top: 0; width: 100%; height: 100%; overflow: hidden; }
.top { position: absolute; left: 0; top: 0; width: 100%; height: 10%; padding: 0 3%; display: -webkit-box; display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; }
.top img { height: 70%; max-width: 18%; -o-object-fit: contain; object-fit: contain; border-radius: 1rem; background: #fff; padding: .4rem; margin-left: 2rem; }
.ltr .top img { margin-left: 0; margin-right: 2rem; }
.top .brand { font-size: 4.2rem; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; -webkit-flex: 1; flex: 1; }
.top .clock { font-size: 3.4rem; font-weight: 700; opacity: .75; direction: ltr; }
.body { position: absolute; left: 0; top: 10%; width: 100%; height: 90%; padding: 0 3% 2.5%; }
.zone-side .top { display: none; }
.zone-side .body { top: 0; height: 100%; padding-top: 3%; }
.qr-on .top { height: 15%; }
.qr-on .body { top: 15%; height: 85%; }
.qrc { display: -webkit-box; display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; margin-right: 3rem; height: 100%; padding: 1rem 0; }
.ltr .qrc { margin-right: 0; margin-left: 3rem; }
.qrc span { font-size: 2.4rem; font-weight: 800; margin-left: 1.4rem; max-width: 14rem; line-height: 1.2; text-align: center; }
.ltr .qrc span { margin-left: 0; margin-right: 1.4rem; }
.qrc .box, .qrf .box { background: #fff; border-radius: 1rem; padding: .6rem; }
.qrc .box { height: 100%; width: 12.5rem; }
.qrc svg, .qrf svg { width: 100%; height: 100%; display: block; }
.qrf { position: absolute; top: 2.5rem; z-index: 2; text-align: center; font-size: 2.4rem; font-weight: 800; color: #fff; }
.qrf .box { width: 16rem; height: 16rem; margin-bottom: .8rem; }
.qrf span { background: #0A2540; border-radius: .8rem; padding: .3rem 1.2rem; }
.rtl .qrf { left: 2.5rem; } .ltr .qrf { right: 2.5rem; }
.split { display: -webkit-box; display: -webkit-flex; display: flex; }
.split .main { -webkit-flex: 0 0 63%; flex: 0 0 63%; height: 100%; position: relative; }
.split .side { -webkit-flex: 1; flex: 1; height: 100%; margin-right: 2.5%; border-radius: 2rem; overflow: hidden; position: relative; background: #000; }
.ltr .split .side { margin-right: 0; margin-left: 2.5%; }
.portrait .split { -webkit-flex-direction: column-reverse; flex-direction: column-reverse; }
.portrait .split .main { -webkit-flex: none; flex: none; height: 61%; }
.portrait .split .side { -webkit-flex: none; flex: none; margin: 0 0 3% 0; height: 36%; }
.fill { position: absolute; left: 0; top: 0; width: 100%; height: 100%; -o-object-fit: cover; object-fit: cover; background: #000; }
.fit-box { position: relative; width: 100%; height: 100%; overflow: hidden; }
.fit { width: 100%; }
.cols { -webkit-column-gap: 2.4rem; -moz-column-gap: 2.4rem; column-gap: 2.4rem; -webkit-column-fill: auto; column-fill: auto; height: 100%; }
.cat { -webkit-column-break-inside: avoid; page-break-inside: avoid; break-inside: avoid; border-radius: 1.6rem; padding: .8em 1em .6em; margin: 0 0 1em; display: inline-block; width: 100%; }
.cat h2 { margin: 0 0 .45em; font-size: 1.25em; font-weight: 800; line-height: 1.2; }
.cat h2 small, .item small { display: block; font-size: .62em; font-weight: 600; opacity: .7; }
.row { display: -webkit-box; display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; padding: .28em 0; }
.row img { width: 2.9em; height: 2.9em; border-radius: .5em; -o-object-fit: cover; object-fit: cover; margin-left: .7em; -webkit-flex: 0 0 auto; flex: 0 0 auto; }
.ltr .row img { margin-left: 0; margin-right: .7em; }
.row .item { -webkit-flex: 1; flex: 1; min-width: 0; font-weight: 700; line-height: 1.2; overflow: hidden; }
.row .desc { font-size: .62em; font-weight: 500; opacity: .65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tag { display: inline-block; font-size: .5em; font-weight: 800; padding: .12em .55em; border-radius: 1em; margin: 0 .5em; vertical-align: middle; line-height: 1.4; }
.price { font-weight: 800; white-space: nowrap; margin-right: .8em; }
.ltr .price { margin-right: 0; margin-left: .8em; }
.price s { font-size: .65em; opacity: .55; font-weight: 600; margin: 0 .4em; }
.price i { font-style: normal; font-size: .55em; opacity: .7; margin-right: .2em; }
.grid { display: -webkit-box; display: -webkit-flex; display: flex; -webkit-flex-wrap: wrap; flex-wrap: wrap; margin: 0 -.6em; }
.card { padding: 0 .6em 1.2em; }
.card .in { border-radius: 1.2em; overflow: hidden; height: 100%; }
.card .ph { position: relative; width: 100%; padding-top: 62%; background: rgba(127,127,127,.15); }
.card .ph img { position: absolute; left: 0; top: 0; width: 100%; height: 100%; -o-object-fit: cover; object-fit: cover; }
.card .ph .tag { position: absolute; top: .5em; font-size: .6em; margin: 0 .5em; }
.card .tx { padding: .5em .7em .6em; }
.card .nm { font-weight: 800; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card .price { margin: 0; display: block; }
.feat { display: -webkit-box; display: -webkit-flex; display: flex; height: 100%; }
.feat .ph { -webkit-flex: 0 0 56%; flex: 0 0 56%; position: relative; border-radius: 2.4rem; overflow: hidden; background: #000; }
.feat .info { -webkit-flex: 1; flex: 1; padding: 0 4rem; display: -webkit-box; display: -webkit-flex; display: flex; -webkit-flex-direction: column; flex-direction: column; -webkit-justify-content: center; justify-content: center; }
.portrait .feat { -webkit-flex-direction: column; flex-direction: column; }
.portrait .feat .ph { -webkit-flex: 0 0 55%; flex: 0 0 55%; }
.portrait .feat .info { padding: 3rem 1rem 0; }
.feat .kicker { font-size: 3.4rem; font-weight: 700; opacity: .7; }
.feat h1 { font-size: 8rem; line-height: 1.1; margin: .2em 0; font-weight: 900; }
.feat p { font-size: 3.2rem; opacity: .75; margin: 0 0 .6em; line-height: 1.35; }
.feat .price { font-size: 9rem; margin: 0; }
.offer { height: 100%; display: -webkit-box; display: -webkit-flex; display: flex; -webkit-flex-direction: column; flex-direction: column; -webkit-justify-content: center; justify-content: center; text-align: center; }
.offer .pct { font-size: 15rem; font-weight: 900; line-height: 1.25; }
.offer h1 { font-size: 6.4rem; margin: .45em 0 .15em; font-weight: 900; line-height: 1.15; }
.offer .meta { font-size: 3.6rem; opacity: .85; font-weight: 700; }
.offer .cdn { display: inline-block; direction: ltr; unicode-bidi: embed; font-weight: 900; padding: .1em .5em; border-radius: .4em; margin: 0 .3em; }
.offer .grid { margin-top: 4rem; -webkit-justify-content: center; justify-content: center; font-size: 3rem; }
.ready { height: 100%; text-align: center; padding-top: 1rem; }
.ready h1 { font-size: 8rem; margin: 0 0 3rem; font-weight: 900; }
.ready .nums { display: -webkit-box; display: -webkit-flex; display: flex; -webkit-flex-wrap: wrap; flex-wrap: wrap; -webkit-justify-content: center; justify-content: center; }
.ready .num { min-width: 22rem; margin: 0 1.5rem 3rem; padding: 2rem 3rem; border-radius: 2.4rem; font-size: 14rem; font-weight: 900; line-height: 1; direction: ltr; }
.ready .prep { font-size: 3.6rem; opacity: .7; font-weight: 700; margin-top: 1rem; direction: ltr; }
.ready .empty { font-size: 4.6rem; opacity: .7; margin-top: 12rem; }
.idle { height: 100%; display: -webkit-box; display: -webkit-flex; display: flex; -webkit-flex-direction: column; flex-direction: column; -webkit-align-items: center; align-items: center; -webkit-justify-content: center; justify-content: center; }
.idle img { width: 26rem; height: 26rem; -o-object-fit: contain; object-fit: contain; border-radius: 3rem; background: #fff; padding: 2rem; }
.idle .brand { font-size: 7rem; font-weight: 900; margin-top: 3rem; text-align: center; }
.idle .clock { font-size: 5rem; opacity: .6; margin-top: 1rem; direction: ltr; }
#ticker { position: absolute; left: 0; bottom: 0; width: 100%; height: 7%; overflow: hidden; white-space: nowrap; display: none; font-size: 3.6rem; font-weight: 800; -webkit-box-align: center; -webkit-align-items: center; align-items: center; }
.has-ticker #ticker { display: -webkit-box; display: -webkit-flex; display: flex; }
#ticker span { display: inline-block; padding: 0 100%; line-height: 1.3; }
#ticker .move { -webkit-animation: tick 30s linear infinite; animation: tick 30s linear infinite; }
@-webkit-keyframes tick { 0% { -webkit-transform: translateX(0); } 100% { -webkit-transform: translateX(-100%); } }
@keyframes tick { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
.rtl #ticker .move { -webkit-animation-name: tickr; animation-name: tickr; }
@-webkit-keyframes tickr { 0% { -webkit-transform: translateX(0); } 100% { -webkit-transform: translateX(100%); } }
@keyframes tickr { 0% { transform: translateX(0); } 100% { transform: translateX(100%); } }
#dot { position: absolute; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: #f59e0b; left: 1.4rem; bottom: 1.4rem; display: none; opacity: .85; z-index: 9; }
#dot.on { display: block; }
#alert { position: absolute; left: 0; top: 0; width: 100%; display: none; z-index: 8; overflow: hidden; }
#alert.on { display: block; }
#alert.banner { height: 16%; }
#alert.full { height: 100%; }
#alert .in { position: absolute; left: 4%; right: 4%; top: 0; bottom: 0; display: -webkit-box; display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; -webkit-justify-content: center; justify-content: center; text-align: center; font-weight: 900; line-height: 1.25; word-wrap: break-word; }
#alert.banner .in { font-size: 5.4rem; }
#alert.full .in { font-size: 10rem; }
#pair, #note { position: absolute; left: 0; top: 0; width: 100%; height: 100%; background: #0A2540; color: #fff; display: none; z-index: 10; }
#pair.on, #note.on { display: block; }
#pair { cursor: default; }
#pair .wrap { position: absolute; left: 6%; right: 6%; top: 8%; bottom: 8%; display: -webkit-box; display: -webkit-flex; display: flex; -webkit-align-items: center; align-items: center; }
#pair .qr { -webkit-flex: 0 0 auto; flex: 0 0 auto; width: 34rem; height: 34rem; background: #fff; border-radius: 2.4rem; padding: 1.6rem; margin-left: 6rem; }
.ltr #pair .qr { margin-left: 0; margin-right: 6rem; }
#pair .qr svg { width: 100%; height: 100%; display: block; }
#pair .txt { -webkit-flex: 1; flex: 1; min-width: 0; }
.f-portrait #pair .wrap { -webkit-flex-direction: column-reverse; flex-direction: column-reverse; -webkit-justify-content: center; justify-content: center; text-align: center; }
.f-portrait #pair .qr { margin: 5rem 0 0; }
#pair .logo { font-size: 4rem; font-weight: 900; letter-spacing: .05em; color: #F37623; }
#pair h1 { font-size: 5.4rem; margin: 1.5rem 0 .5rem; line-height: 1.2; font-weight: 800; }
#pair .en { font-size: 2.8rem; opacity: .6; margin: 0 0 2.5rem; direction: ltr; }
#pair .code { font-size: 15rem; font-weight: 900; letter-spacing: .12em; direction: ltr; line-height: 1; margin: 1rem 0 2rem; font-family: 'Segoe UI', Arial, sans-serif; }
#pair .steps { font-size: 3rem; line-height: 1.5; opacity: .9; margin: 0; padding: 0; list-style: none; }
#pair .steps b { color: #F37623; direction: ltr; unicode-bidi: embed; }
#pair .left { font-size: 2.6rem; opacity: .6; margin-top: 2rem; }
#pair .btns { margin-top: 3rem; }
#pair button { font: inherit; font-size: 2.8rem; font-weight: 700; color: #fff; background: rgba(255,255,255,.12); border: .3rem solid rgba(255,255,255,.25); border-radius: 1.4rem; padding: 1rem 3rem; margin: 0 0 0 1.5rem; cursor: pointer; }
#pair button:focus { outline: none; border-color: #F37623; background: rgba(243,118,35,.25); }
#note .wrap { position: absolute; left: 10%; right: 10%; top: 35%; text-align: center; font-size: 4.6rem; font-weight: 800; line-height: 1.4; }
#note .wrap small { display: block; font-size: 2.8rem; opacity: .6; margin-top: 2rem; font-weight: 600; }
</style>
@include('screen.partials.designs')
</head>
<body>
<div id="frame"><div id="stage"></div><div id="ticker"></div><div id="alert"><div class="in" id="alert-text"></div></div><div id="dot" title="offline"></div>
    <div id="pair" role="dialog" aria-live="polite">
        <div class="wrap">
            <div class="qr" id="pair-qr"></div>
            <div class="txt">
                <div class="logo">EgHub</div>
                <h1>{{ __('Pair this screen with your restaurant') }}</h1>
                <p class="en">Pair this screen with your restaurant</p>
                <div class="code" id="pair-code">······</div>
                <ol class="steps">
                    <li>{!! __('1. Open the dashboard on your phone: :url', ['url' => '<b>'.e(preg_replace('#^https?://#', '', platform_url('app', '/tv-screens/pair'))).'</b>']) !!}</li>
                    <li>{{ __('2. Type this code and choose the screen, or scan the QR code.') }}</li>
                </ol>
                <div class="left" id="pair-left"></div>
                <div class="btns"><button type="button" id="pair-new">{{ __('New code') }}</button><button type="button" id="pair-full">{{ __('Full screen') }}</button></div>
            </div>
        </div>
    </div>
    <div id="note"><div class="wrap" id="note-text"></div></div>
</div>
<script type="application/json" id="cfg">{!! json_encode([
    'mode' => $mode, 'base' => $base, 'version' => $version, 'boot' => $boot,
    't' => [
        'expires' => __('The code changes in :s seconds', ['s' => '%s']),
        'connecting' => __('Connecting…'),
        'paused' => __('This screen is paused. Check the subscription in the dashboard.'),
        'no_network' => __('No internet. The screen starts as soon as the connection is back.'),
        'paired' => __('Paired! Loading the screen…'),
        'scan' => __('Scan to order'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@verbatim
<script>
(function () {
    'use strict';
    var cfg = JSON.parse(document.getElementById('cfg').textContent);
    var T = cfg.t;
    var KEY = 'eghub.screen.';
    var bridge = window.EgHubPlayer || null;
    var query = parseQuery(location.search);
    var mode = cfg.mode;
    var base = mode === 'device' ? '/screen' : location.pathname.replace(/\/+$/, '');
    var storeKey = mode === 'device' ? 'device' : 'link:' + base;
    var frame = document.getElementById('frame');
    var stage = document.getElementById('stage');
    var tickerEl = document.getElementById('ticker');
    var alertEl = document.getElementById('alert');
    var dot = document.getElementById('dot');
    var state = {
        token: null, data: null, version: null, reload: null, skew: 0, tz: 120, lists: { main: [], side: [] },
        lastOk: 0, started: Date.now(), media: {}, readyCache: {}, zoneLayout: null,
        pairing: null, pairTimer: null, pollTimer: null, liveTimer: null, alert: null, alertTimer: null, fw: 0, fh: 0
    };
    /* the areas of the screen: one ("main"), or a main and a side area; each loops its own slides */
    var zones = [];

    /* ---------- small helpers ---------- */
    function parseQuery(q) {
        var out = {};
        (q || '').replace(/^\?/, '').split('&').forEach(function (p) {
            if (!p) { return; }
            var kv = p.split('=');
            out[decodeURIComponent(kv[0])] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
        });
        return out;
    }
    function get(k) { try { return window.localStorage.getItem(KEY + k); } catch (e) { return null; } }
    function set(k, v) { try { if (v === null) { window.localStorage.removeItem(KEY + k); } else { window.localStorage.setItem(KEY + k, v); } } catch (e) {} }
    function callBridge(name, arg) {
        try { if (bridge && typeof bridge[name] === 'function') { return arg === undefined ? bridge[name]() : bridge[name](arg); } } catch (e) {}
        return null;
    }
    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (html !== undefined) { e.innerHTML = html; }
        return e;
    }
    function request(method, url, body, headers) {
        return new Promise(function (resolve, reject) {
            var x = new XMLHttpRequest();
            x.open(method, url, true);
            x.timeout = 15000;
            x.setRequestHeader('Accept', 'application/json');
            if (state.token) { x.setRequestHeader('X-Screen-Token', state.token); }
            if (headers) { for (var h in headers) { if (headers.hasOwnProperty(h)) { x.setRequestHeader(h, headers[h]); } } }
            if (body) { x.setRequestHeader('Content-Type', 'application/json'); }
            x.onload = function () {
                var json = null;
                try { json = JSON.parse(x.responseText); } catch (e) {}
                if (x.status >= 200 && x.status < 300) { state.lastOk = Date.now(); online(true); resolve(json || {}); return; }
                if (x.status === 401 || x.status === 403 || x.status === 404 || x.status === 429) { state.lastOk = Date.now(); online(true); }
                reject({ status: x.status, body: json });
            };
            x.onerror = x.ontimeout = function () { online(false); reject({ status: 0 }); };
            x.send(body ? JSON.stringify(body) : null);
        });
    }
    function online(ok) {
        var off = !ok || (state.lastOk && Date.now() - state.lastOk > 150000);
        dot.className = off ? 'on' : '';
    }

    /* ---------- time (Cairo, corrected by the server clock) ---------- */
    function syncClock(d) {
        if (d && d.now) { state.skew = d.now - Date.now(); }
        if (d && typeof d.tz === 'number') { state.tz = d.tz; }
    }
    function serverNow() { return Date.now() + state.skew; }
    function cairo() { return new Date(serverNow() + state.tz * 60000); }
    function two(n) { return (n < 10 ? '0' : '') + n; }
    function hhmm(d) { return two(d.getUTCHours()) + ':' + two(d.getUTCMinutes()); }
    function ymd(d) { return d.getUTCFullYear() + '-' + two(d.getUTCMonth() + 1) + '-' + two(d.getUTCDate()); }
    function clockText() {
        var d = cairo(), h = d.getUTCHours(), m = two(d.getUTCMinutes());
        if (state.data && state.data.screen.lang === 'en') { return two(h) + ':' + m; }
        return (h % 12 === 0 ? 12 : h % 12) + ':' + m + (h < 12 ? ' ص' : ' م');
    }
    function dayOk(days, d) { return !days || !days.length || days.indexOf(d.getUTCDay()) !== -1; }
    /* the same rules as App\Models\Concerns\HasScreenHours: a window may cross midnight */
    function hoursOk(h, d) {
        if (!h) { return true; }
        if (!h.from || !h.to || h.from === h.to) { return dayOk(h.days, d); }
        var now = hhmm(d);
        if (h.from < h.to) { return dayOk(h.days, d) && now >= h.from && now < h.to; }
        if (now >= h.from) { return dayOk(h.days, d); }
        return now < h.to && dayOk(h.days, new Date(d.getTime() - 86400000));
    }
    function offerRuns(o, d) {
        var day = ymd(d);
        if ((o.starts_on && day < o.starts_on) || (o.ends_on && day > o.ends_on)) { return false; }
        return hoursOk({ days: o.days, from: o.from, to: o.to }, d);
    }
    /* milliseconds until a running offer's hours end today (null without an end time) */
    function offerLeft(o, d) {
        if (!o.to) { return null; }
        var p = o.to.split(':'), end = Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate(), parseInt(p[0], 10), parseInt(p[1], 10));
        if (end <= d.getTime()) { end += 86400000; }
        return end - d.getTime();
    }

    /* ---------- size: the frame fills the screen, turned for a portrait screen hung on a landscape output ---------- */
    function layout() {
        var w = window.innerWidth, h = window.innerHeight;
        var o = state.data ? state.data.screen.orientation : 'landscape';
        var turn = (o === 'portrait' || o === 'portrait2') && w > h;
        var fw = turn ? h : w, fh = turn ? w : h;
        state.fw = fw;
        state.fh = fh;
        frame.style.width = fw + 'px';
        frame.style.height = fh + 'px';
        var t = '';
        if (turn) { t = o === 'portrait' ? 'translate(' + w + 'px,0) rotate(90deg)' : 'translate(0,' + h + 'px) rotate(-90deg)'; }
        frame.style.webkitTransform = t;
        frame.style.transform = t;
        /* the owner's text size (screen size and distance, or set by hand): 1 = 1% of the short side */
        var scale = state.data && state.data.screen.scale ? Math.max(0.8, Math.min(1.5, state.data.screen.scale)) : 1;
        document.documentElement.style.fontSize = (Math.min(fw, fh) * scale / 100) + 'px';
        var cls = [fh > fw ? 'f-portrait' : 'f-landscape'];
        if (state.data) {
            cls.push(state.data.screen.dir === 'ltr' ? 'ltr' : 'rtl');
            cls.push(state.data.screen.theme === 'light' ? 'light' : 'dark');
            if (state.data.screen.ticker) { cls.push('has-ticker'); }
        } else {
            cls.push(document.documentElement.dir === 'ltr' ? 'ltr' : 'rtl');
        }
        frame.className = cls.join(' ');
        placeZones();
    }
    /* where each area goes: a side column (or a band at the bottom when portrait), or two halves */
    function placeZones() {
        if (!zones.length) { return; }
        var fw = state.fw, fh = state.fh, d = state.data;
        var sh = Math.round(fh * (d && d.screen.ticker ? 0.93 : 1));
        stage.style.height = sh + 'px';
        var rects = [{ x: 0, y: 0, w: fw, h: sh }];
        if (zones.length > 1) {
            var gap = Math.round(Math.min(fw, fh) * 0.012), share = d.screen.zones === 'halves' ? 0.5 : 0.68, rtl = d.screen.dir !== 'ltr';
            if (fh > fw) {
                var mh = Math.round((sh - gap) * share);
                rects = [{ x: 0, y: 0, w: fw, h: mh }, { x: 0, y: mh + gap, w: fw, h: sh - mh - gap }];
            } else {
                var mw = Math.round((fw - gap) * share), sw = fw - gap - mw;
                rects = rtl ? [{ x: sw + gap, y: 0, w: mw, h: sh }, { x: 0, y: 0, w: sw, h: sh }] : [{ x: 0, y: 0, w: mw, h: sh }, { x: mw + gap, y: 0, w: sw, h: sh }];
            }
        }
        var shortSide = Math.min(fw, fh);
        for (var i = 0; i < zones.length; i++) {
            var z = zones[i], r = rects[i] || rects[0];
            /* a full screen keeps its own size; an area is drawn as a whole screen of its shape, then scaled to its box */
            var f = zones.length > 1 ? Math.min(r.w, r.h) / shortSide : 1;
            z.el.style.left = r.x + 'px';
            z.el.style.top = r.y + 'px';
            z.el.style.width = r.w + 'px';
            z.el.style.height = r.h + 'px';
            z.inner.style.width = (r.w / f) + 'px';
            z.inner.style.height = (r.h / f) + 'px';
            var tf = f === 1 ? '' : 'scale(' + f + ')';
            z.inner.style.webkitTransform = tf;
            z.inner.style.transform = tf;
            z.portrait = r.h > r.w;
            var qr = z.name === 'main' && d && d.screen.show_qr && d.brand.qr;
            z.inner.className = 'zone-in ' + (z.portrait ? 'portrait' : 'landscape') + (z.name === 'side' ? ' zone-side' : '') + (qr ? ' qr-on' : '');
        }
    }
    function buildZones() {
        var want = state.data && (state.data.screen.zones === 'side' || state.data.screen.zones === 'halves') ? state.data.screen.zones : 'full';
        if (want === state.zoneLayout && zones.length) { return; }
        state.zoneLayout = want;
        zones.forEach(function (z) { clearTimeout(z.timer); clearTimeout(z.readyTimer); clearInterval(z.tickTimer); });
        stage.innerHTML = '';
        zones = [];
        var names = want === 'full' ? ['main'] : ['main', 'side'];
        names.forEach(function (name) {
            var z = { name: name, el: el('div', 'zone'), inner: el('div', 'zone-in'), index: -1, page: 0, pages: 1, timer: null, layers: [], active: 0,
                menuPages: null, menuSlide: null, readyTimer: null, tickTimer: null, lastAdvance: Date.now(), portrait: false, cur: null };
            z.el.appendChild(z.inner);
            stage.appendChild(z.el);
            zones.push(z);
        });
    }

    /* ---------- colours of the current playlist (no CSS variables on old TVs) ---------- */
    function applyTheme() {
        var d = state.data, light = d.screen.theme === 'light', p = d.brand.primary || '#F26522', pf = window.EgDesigns ? EgDesigns.on(p) : '#fff';
        var css = '#frame{background:' + (light ? '#ffffff' : '#0f1420') + ';color:' + (light ? '#0A2540' : '#f3f4f6') + '}' +
            '.cat,.card .in{background:' + (light ? '#f3f5f9' : 'rgba(255,255,255,.05)') + '}' +
            '.cat h2,.price,.offer .pct,.top .brand{color:' + p + '}' +
            '.ready .num,.tag,.offer .cdn{background:' + p + ';color:' + pf + '}' +
            '.offer{color:' + (light ? '#0A2540' : '#fff') + '}' +
            '#ticker{background:' + p + ';color:' + pf + '}';
        var tag = document.getElementById('theme');
        if (!tag) { tag = el('style'); tag.id = 'theme'; document.head.appendChild(tag); }
        tag.textContent = css;
        document.documentElement.lang = d.screen.lang === 'en' ? 'en' : 'ar';
        document.documentElement.dir = d.screen.dir;
        if (window.EgDesigns) { EgDesigns.labels = d.labels.design || null; }
        if (d.screen.ticker) {
            tickerEl.innerHTML = '<span class="move" style="-webkit-animation-duration:' + Math.max(15, d.screen.ticker.length / 4) + 's;animation-duration:' + Math.max(15, d.screen.ticker.length / 4) + 's">' + esc(d.screen.ticker) + '</span>';
        } else {
            tickerEl.innerHTML = '';
        }
    }

    /* ---------- media: downloaded into the Cache API before a slide shows it; played from a blob URL ---------- */
    var MEDIA_CACHE = 'eghub-screen-media';
    var hasCache = !!(window.caches && window.Promise && window.fetch);
    function prefetch(list) {
        var keep = {};
        list.forEach(function (u) { keep[u] = true; });
        Object.keys(state.media).forEach(function (u) {
            if (!keep[u]) { if (state.media[u].blob) { try { URL.revokeObjectURL(state.media[u].blob); } catch (e) {} } delete state.media[u]; }
        });
        if (!hasCache) {
            list.forEach(function (u) { state.media[u] = state.media[u] || { ready: true, url: u }; });
            return Promise.resolve();
        }
        return caches.open(MEDIA_CACHE).then(function (cache) {
            cache.keys().then(function (reqs) {
                reqs.forEach(function (r) { var p = r.url.replace(location.origin, ''); if (!keep[p]) { cache['delete'](r); } });
            });
            var chain = Promise.resolve();
            list.forEach(function (u) {
                if (state.media[u] && state.media[u].ready) { return; }
                state.media[u] = state.media[u] || { ready: false, url: u };
                chain = chain.then(function () {
                    return cache.match(u).then(function (hit) {
                        if (hit) { return hit; }
                        return fetch(u, { credentials: 'same-origin' }).then(function (res) {
                            if (!res.ok) { throw new Error('media ' + res.status); }
                            return cache.put(u, res.clone()).then(function () { return res; }, function () { return res; });
                        });
                    }).then(function (res) {
                        return res.blob().then(function (b) {
                            state.media[u] = { ready: true, url: u, blob: URL.createObjectURL(b) };
                        });
                    })['catch'](function () { state.media[u] = { ready: false, url: u, failed: true }; });
                });
            });
            return chain;
        }, function () { hasCache = false; return prefetch(list); });
    }
    function src(u) {
        var m = u && state.media[u];
        if (m && m.blob) { return m.blob; }
        return u;
    }
    function mediaReady(u) {
        if (!u) { return true; }
        var m = state.media[u];
        if (!m) { return navigator.onLine !== false; }
        return m.ready || (m.failed && navigator.onLine !== false);
    }

    /* ---------- playlist ---------- */
    function useData(d, fromCache) {
        if (!d || !d.slides) { return; }
        syncClock(d);
        var first = !state.data;
        state.data = d;
        state.version = d.v;
        /* the newest «reload» the screen already obeyed: an old request never reloads it again */
        state.reload = Math.max(state.reload || 0, d.reload || 0, parseInt(get('reload-seen') || '0', 10) || 0);
        if (!fromCache) { set('playlist:' + storeKey, JSON.stringify(d)); }
        state.lists = { main: [], side: [] };
        d.slides.forEach(function (s) { state.lists[s.zone === 'side' ? 'side' : 'main'].push(s); });
        var before = state.zoneLayout;
        buildZones();
        applyTheme();
        layout();
        hideOverlays();
        /* a new layout starts every area again; otherwise each area goes on from where it is */
        var restart = before !== state.zoneLayout;
        prefetch(d.media || []).then(function () { zones.forEach(function (z) { if (z.index === -1 || restart) { next(z); } }); });
        if (first) { setTimeout(function () { zones.forEach(function (z) { if (z.index === -1) { next(z); } }); }, 2500); }
    }
    function eligible(s, now) {
        if (!hoursOk(s.hours, now)) { return false; }
        if (s.type === 'image' || s.type === 'video') { return mediaReady(s.url); }
        if (s.type === 'offer') {
            for (var i = 0; i < s.offers.length; i++) { if (offerRuns(s.offers[i], now)) { return true; } }
            return false;
        }
        if (s.type === 'ready') { return !!state.data.ready_allowed; }
        if (s.type === 'promo') { return (!s.ends || s.ends > serverNow()) && mediaReady(s.image); }
        return true;
    }
    function next(z) {
        clearTimeout(z.timer);
        clearInterval(z.tickTimer);
        z.lastAdvance = Date.now();
        var d = state.data;
        if (!d) { return; }
        var now = cairo(), list = state.lists[z.name] || [];
        if (!hoursOk(d.screen.hours, now) || !list.length) { z.index = -1; z.cur = null; show(z, idleSlide(z), 60); return; }
        var cur = list[z.index];
        if (cur && z.page + 1 < z.pages) { z.page++; showPage(z); return; }
        var n = list.length;
        for (var k = 1; k <= n; k++) {
            var i = (z.index + k + n) % n;
            if (eligible(list[i], now)) { z.index = i; z.page = 0; z.cur = list[i]; render(z, list[i]); return; }
        }
        z.index = -1;
        z.cur = null;
        show(z, idleSlide(z), 30);
    }

    /* ---------- drawing ---------- */
    function qrHtml(cls) {
        var d = state.data;
        if (!d || !d.screen.show_qr || !d.brand.qr) { return ''; }
        return '<div class="' + cls + '"><div class="box">' + d.brand.qr + '</div><span>' + esc(T.scan) + '</span></div>';
    }
    function header(z) {
        var b = state.data.brand;
        return '<div class="top">' + (b.logo ? '<img src="' + esc(src(b.logo)) + '" alt="">' : '') +
            '<div class="brand">' + esc(b.name) + '</div><div class="clock" data-clock>' + esc(clockText()) + '</div>' + (z.name === 'main' ? qrHtml('qrc') : '') + '</div>';
    }
    function priceHtml(it) {
        var cur = state.data.brand.currency;
        return '<span class="price">' + (it.was ? '<s>' + esc(it.was) + '</s>' : '') + esc(it.price) + (cur ? '<i>' + esc(cur) + '</i>' : '') + '</span>';
    }
    function tagHtml(it) {
        if (!it.tag) { return ''; }
        var L = state.data.labels;
        return '<b class="tag">' + esc(it.tag === 'new' ? L.tag_new : L.tag_best) + '</b>';
    }
    function idleSlide(z) {
        var b = state.data ? state.data.brand : { name: 'EgHub' };
        return el('div', 'slide', '<div class="idle">' + (b.logo ? '<img src="' + esc(src(b.logo)) + '" alt="">' : '') +
            '<div class="brand">' + esc(b.name) + '</div>' + (z.name === 'main' ? '<div class="clock" data-clock>' + esc(clockText()) + '</div>' : '') + '</div>');
    }
    function menuRows(z, cats, s) {
        var html = '';
        cats.forEach(function (c) {
            html += '<div class="cat"><h2>' + esc(c.name) + (c.alt ? '<small>' + esc(c.alt) + '</small>' : '') + '</h2>';
            c.items.forEach(function (it) {
                html += '<div class="row">' + (s.show_images && it.image ? '<img src="' + esc(src(it.image)) + '" alt="">' : '') +
                    '<div class="item">' + esc(it.name) + tagHtml(it) + (it.alt ? '<small>' + esc(it.alt) + '</small>' : '') +
                    (it.desc ? '<div class="desc">' + esc(it.desc) + '</div>' : '') + '</div>' + priceHtml(it) + '</div>';
            });
            html += '</div>';
        });
        var n = columnsFor(z, s, 2);
        return '<div class="fit cols" style="-webkit-column-count:' + n + ';-moz-column-count:' + n + ';column-count:' + n + '">' + html + '</div>';
    }
    function menuCards(z, items, s) {
        var w = (100 / columnsFor(z, s, 3)).toFixed(3) + '%', html = '';
        items.forEach(function (it) {
            html += '<div class="card" style="width:' + w + '"><div class="in">' + (s.show_images ? '<div class="ph">' + (it.image ? '<img src="' + esc(src(it.image)) + '" alt="">' : '') + tagHtml(it) + '</div>' : '') +
                '<div class="tx"><div class="nm">' + esc(it.name) + '</div>' + priceHtml(it) + '</div></div></div>';
        });
        return '<div class="fit grid">' + html + '</div>';
    }
    /* a portrait area is narrow: fewer columns than a wide one */
    function columnsFor(z, s, portraitMax) {
        return z.portrait ? Math.min(portraitMax, s.columns) : s.columns;
    }
    function sideHtml(side) {
        if (!side) { return ''; }
        return '<div class="side">' + (side.kind === 'video'
            ? '<video class="fill" muted autoplay loop playsinline preload="auto" src="' + esc(src(side.url)) + '"></video>'
            : '<img class="fill" src="' + esc(src(side.url)) + '" alt="">') + '</div>';
    }
    /* pages of a menu slide: the categories (or the cards) that fit at a readable size, then the next ones */
    function menuPages(s) {
        if (s.layout === 'grid') {
            var items = [];
            s.categories.forEach(function (c) { items = items.concat(c.items); });
            return chunkBy(items, s.per_page ? s.per_page * s.columns : 0);
        }
        return chunkBy(s.categories, s.per_page || 0);
    }
    function chunkBy(list, n) {
        if (!n) { return [list]; }
        var out = [];
        for (var i = 0; i < list.length; i += n) { out.push(list.slice(i, i + n)); }
        return out.length ? out : [[]];
    }
    function fit(box, maxRem, minRem) {
        var inner = box.firstChild;
        if (!inner) { return true; }
        var names = inner.querySelectorAll('.item, .nm');
        var over = function () {
            if (inner.scrollHeight > box.clientHeight + 2 || inner.scrollWidth > box.clientWidth + 2 || box.scrollWidth > box.clientWidth + 2) { return true; }
            /* a name wider than its place would run under the price */
            for (var n = 0; n < names.length; n++) { if (names[n].scrollWidth > names[n].clientWidth + 2) { return true; } }
            return false;
        };
        var lo = minRem, hi = maxRem;
        inner.style.fontSize = hi + 'rem';
        if (!over()) { return true; }
        for (var i = 0; i < 9; i++) {
            var mid = (lo + hi) / 2;
            inner.style.fontSize = mid + 'rem';
            if (over()) { hi = mid; } else { lo = mid; }
        }
        inner.style.fontSize = lo + 'rem';
        return !over();
    }
    /* what a ready design needs: the slide's words and price, the brand, the photo from the cache */
    function designData(s) {
        var d = state.data;
        return {
            title: s.title, alt: s.alt, desc: s.desc, price: s.price, was: s.was, badge: s.badge, bg: s.bg, ends: s.ends,
            image: s.image ? src(s.image) : null, now: serverNow(), lang: d.screen.lang === 'en' ? 'en' : 'ar',
            labels: d.labels.design || {},
            brand: { name: d.brand.name, logo: d.brand.logo ? src(d.brand.logo) : null, primary: d.brand.primary, secondary: d.brand.secondary, currency: d.brand.currency, qr: d.brand.qr }
        };
    }

    function render(z, s) {
        var node, seconds = s.duration || 15;
        z.pages = 1;
        if (s.type === 'menu') {
            if (s.layout === 'featured' && s.item) {
                var it = s.item;
                node = el('div', 'slide', header(z) + '<div class="body"><div class="feat"><div class="ph">' + (it.image ? '<img class="fill" src="' + esc(src(it.image)) + '" alt="">' : '') +
                    '</div><div class="info"><h1>' + esc(it.name) + tagHtml(it) + (it.alt ? '<small style="display:block;font-size:.45em;opacity:.7">' + esc(it.alt) + '</small>' : '') + '</h1>' +
                    (it.desc ? '<p>' + esc(it.desc) + '</p>' : '') + priceHtml(it) + '</div></div></div>');
                show(z, node, seconds);
                return;
            }
            z.menuPages = menuPages(s);
            z.pages = z.menuPages.length;
            z.menuSlide = s;
            showPage(z);
            return;
        }
        if (s.type === 'image') {
            node = el('div', 'slide', '<img class="fill" src="' + esc(src(s.url)) + '" alt="">' + (z.name === 'main' ? qrHtml('qrf') : ''));
            show(z, node, seconds);
            return;
        }
        if (s.type === 'video') {
            node = el('div', 'slide', '<video class="fill" muted playsinline preload="auto"' + (s.duration ? ' loop' : '') + ' src="' + esc(src(s.url)) + '"></video>' + (z.name === 'main' ? qrHtml('qrf') : ''));
            show(z, node, s.duration || 0);
            return;
        }
        if (s.type === 'promo' && window.EgDesigns) {
            node = el('div', 'slide', EgDesigns.html(s.template, designData(s), { portrait: z.portrait }));
            show(z, node, seconds, function (n) { EgDesigns.fit(n); });
            if (s.ends) { z.tickTimer = setInterval(function () { EgDesigns.tick(node, serverNow()); }, 1000); }
            return;
        }
        if (s.type === 'offer') {
            var now = cairo(), o = null;
            for (var i = 0; i < s.offers.length; i++) { if (offerRuns(s.offers[i], now)) { o = s.offers[i]; break; } }
            if (!o) { next(z); return; }
            var L = state.data.labels, cards = (s.items || []).slice(0, z.portrait ? 6 : 4), ms = offerLeft(o, now);
            /* a time-limited offer counts down its last hours */
            var cdn = ms !== null && ms < 6 * 3600000 ? ' · ' + esc(L.ends_in) + '<span class="cdn" data-left="' + (serverNow() + ms) + '">' + esc(window.EgDesigns ? EgDesigns.countdownText(ms, L.design || {}) : '') + '</span>' : '';
            node = el('div', 'slide', header(z) + '<div class="body"><div class="offer"><div class="pct">' + esc(L.off.replace('%s', o.percent)) + '</div><h1>' + esc(o.title) + '</h1>' +
                '<div class="meta">' + esc(o.category) + (o.to ? (o.category ? ' · ' : '') + esc(L.until.replace('%s', o.to)) : '') + cdn + '</div>' +
                (cards.length ? menuCards(z, cards, { columns: Math.min(cards.length, z.portrait ? 3 : 4), show_images: true }) : '') + '</div></div>');
            show(z, node, seconds);
            if (cdn) {
                z.tickTimer = setInterval(function () {
                    var c = node.querySelector('[data-left]');
                    if (c && window.EgDesigns) { c.textContent = EgDesigns.countdownText(parseInt(c.getAttribute('data-left'), 10) - serverNow(), L.design || {}); }
                }, 1000);
            }
            return;
        }
        if (s.type === 'ready') {
            node = el('div', 'slide', header(z) + '<div class="body"><div class="ready"><h1>' + esc(state.data.labels.ready) + '</h1><div class="nums" data-nums></div><div class="prep" data-prep></div></div></div>');
            show(z, node, seconds);
            drawReady(node, s, state.readyCache[s.branch || 0]);
            pollReady(z, node, s);
            return;
        }
        next(z);
    }
    function showPage(z) {
        var s = z.menuSlide, part = z.menuPages[z.page] || [];
        var inner = s.layout === 'grid' ? menuCards(z, part, s) : menuRows(z, part, s);
        var body = s.side ? '<div class="body split"><div class="main"><div class="fit-box">' + inner + '</div></div>' + sideHtml(s.side) + '</div>'
            : '<div class="body"><div class="fit-box">' + inner + '</div></div>';
        var node = el('div', 'slide', header(z) + body);
        show(z, node, s.duration || 15, function (n) {
            var box = n.querySelector('.fit-box');
            var ok = fit(box, s.layout === 'grid' ? 4 : 4.6, 1.9);
            /* too much for one page at a readable size: split this page in two and show the first half */
            if (!ok && part.length > 1) {
                var half = Math.ceil(part.length / 2);
                z.menuPages.splice(z.page, 1, part.slice(0, half), part.slice(half));
                z.pages = z.menuPages.length;
                box.innerHTML = s.layout === 'grid' ? menuCards(z, part.slice(0, half), s) : menuRows(z, part.slice(0, half), s);
                fit(box, s.layout === 'grid' ? 4 : 4.6, 1.2);
            } else if (!ok) {
                fit(box, 4.6, 1.0);
            }
        });
    }
    function show(z, node, seconds, measure) {
        if (!z.layers.length) {
            z.layers = [el('div', 'layer'), el('div', 'layer')];
            z.inner.appendChild(z.layers[0]);
            z.inner.appendChild(z.layers[1]);
        }
        var nextLayer = z.layers[1 - z.active], oldLayer = z.layers[z.active];
        nextLayer.innerHTML = '';
        nextLayer.appendChild(node);
        if (measure) { try { measure(node); } catch (e) {} }
        /* nothing inside may stay scrolled (a hidden overflow can still be scrolled by the browser) */
        nextLayer.scrollTop = 0;
        z.inner.scrollTop = 0;
        z.el.scrollTop = 0;
        stage.scrollTop = 0;
        frame.scrollTop = 0;
        frame.scrollLeft = 0;
        nextLayer.className = 'layer on';
        oldLayer.className = 'layer';
        z.active = 1 - z.active;
        setTimeout(function () {
            var vids = oldLayer.getElementsByTagName('video');
            for (var i = 0; i < vids.length; i++) { try { vids[i].pause(); } catch (e) {} }
            if (oldLayer.className === 'layer') { oldLayer.innerHTML = ''; }
        }, 900);
        var vids = node.getElementsByTagName('video');
        for (var i = 0; i < vids.length; i++) { playVideo(vids[i]); }
        clearTimeout(z.timer);
        var main = node.querySelector('.slide > video, video.fill');
        if (!seconds && main && node.firstChild === main) {
            /* a video without a duration plays to its end (capped at 10 minutes) */
            main.onended = function () { next(z); };
            main.onerror = function () { next(z); };
            z.timer = setTimeout(function () { next(z); }, 600000);
        } else {
            z.timer = setTimeout(function () { next(z); }, Math.max(5, seconds || 15) * 1000);
        }
    }
    function playVideo(v) {
        v.muted = true;
        v.setAttribute('muted', '');
        try { var p = v.play(); if (p && p['catch']) { p['catch'](function () {}); } } catch (e) {}
    }

    /* ---------- ready orders ---------- */
    function drawReady(node, s, data) {
        var nums = node.querySelector('[data-nums]'), prep = node.querySelector('[data-prep]');
        if (!nums || !data) { return; }
        var ready = (data.ready || []).slice(-12);
        nums.innerHTML = ready.length ? ready.map(function (n) { return '<div class="num">' + esc(n) + '</div>'; }).join('')
            : '<div class="empty">' + esc(state.data.labels.none_ready) + '</div>';
        prep.innerHTML = (data.preparing || []).length ? esc(state.data.labels.preparing) + ': ' + esc(data.preparing.slice(-15).join('  ·  ')) : '';
    }
    function pollReady(z, node, s) {
        clearTimeout(z.readyTimer);
        var tick = function () {
            if (!node.parentNode) { return; }
            request('GET', base + '/ready' + (s.branch ? '?branch=' + s.branch : '')).then(function (d) {
                state.readyCache[s.branch || 0] = d;
                drawReady(node, s, d);
            }, function () {});
            z.readyTimer = setTimeout(tick, 5000);
        };
        tick();
    }

    /* ---------- urgent message («رسالة عاجلة») ---------- */
    function showAlert(a) {
        clearTimeout(state.alertTimer);
        if (!a || (a.until && a.until <= serverNow())) {
            state.alert = null;
            alertEl.className = '';
            set('alert', null);
            return;
        }
        var same = state.alert && state.alert.id === a.id && state.alert.text === a.text;
        state.alert = a;
        set('alert', JSON.stringify(a));
        var color = a.color || '#F26522', fg = window.EgDesigns ? EgDesigns.on(color) : '#fff';
        alertEl.style.background = color;
        alertEl.style.color = fg;
        if (!same) { document.getElementById('alert-text').textContent = a.text; }
        alertEl.className = 'on ' + (a.style === 'full' ? 'full' : 'banner');
        if (!same) { fitAlert(); }
        if (a.until) { state.alertTimer = setTimeout(function () { showAlert(null); }, Math.min(a.until - serverNow(), 2147483000)); }
    }
    function fitAlert() {
        var box = document.getElementById('alert-text'), size = alertEl.className.indexOf('full') !== -1 ? 10 : 5.4;
        box.style.fontSize = size + 'rem';
        for (var i = 0; i < 10 && box.scrollHeight > box.clientHeight + 2 && size > 2; i++) {
            size = size * 0.86;
            box.style.fontSize = size + 'rem';
        }
    }
    /* the fast check: the message, a reload, and (from a paired device) which slide each area shows now */
    function live() {
        clearTimeout(state.liveTimer);
        if (pairEl.className === 'on') { return; }
        var every = 8000;
        var showing = zones.map(function (z) { return z.name + ':' + (z.cur ? z.cur.id : 0); }).join(',');
        request('GET', base + '/live' + (mode === 'device' && showing ? '?showing=' + encodeURIComponent(showing) : '')).then(function (d) {
            if (d.poll) { every = Math.max(4, d.poll) * 1000; }
            showAlert(d.alert || null);
            if (d.reload && state.reload !== null && d.reload > state.reload) { set('reload-seen', String(d.reload)); hardReload(); }
            state.liveTimer = setTimeout(live, every);
        }, function (err) {
            handleError(err);
            /* offline: a timed message still ends on time; one until cleared stays until the line is back */
            state.liveTimer = setTimeout(live, 15000);
        });
    }

    /* ---------- staying fresh ---------- */
    function loadPlaylist() {
        return request('GET', base + '/playlist').then(function (d) { useData(d, false); return d; }, handleError);
    }
    function poll() {
        clearTimeout(state.pollTimer);
        if (pairEl.className === 'on') { return; }
        var every = ((state.data && state.data.poll) || 45) * 1000;
        state.pollTimer = setTimeout(poll, every);
        if (!state.data) { loadPlaylist(); return; }
        request('GET', base + '/version').then(function (d) {
            syncClock(d);
            document.getElementById('note').className = '';
            if (d.reload && state.reload !== null && d.reload > state.reload) { set('reload-seen', String(d.reload)); hardReload(); return; }
            if (d.v && d.v !== state.version) { loadPlaylist(); }
        }, handleError);
    }
    function handleError(err) {
        if (!err) { return; }
        if (err.status === 401 && mode === 'device') { state.token = null; set('token', null); callBridge('saveToken', ''); startPairing(); return; }
        if (err.status === 403 && err.body && err.body.state === 'paused') { note(T.paused); return; }
        if (err.status === 404 && mode === 'link') { note(T.paused); }
    }
    function hardReload() {
        /* only with a connection: offline, a reload could leave a blank screen (the service worker covers eghub.app/screen) */
        request('GET', base + '/version').then(function () {
            if (callBridge('reload') === null) { location.reload(); }
        }, function () {});
    }
    function heartbeat() {
        if (mode !== 'device' || !state.token) { return; }
        request('POST', base + '/heartbeat', {
            app: callBridge('getAppVersion') || cfg.version,
            device: query.device || callBridge('getDeviceType') || guessDevice(),
            model: callBridge('getDeviceModel') || '',
            screen: window.screen ? (window.screen.width * (window.devicePixelRatio || 1)) + 'x' + (window.screen.height * (window.devicePixelRatio || 1)) : '',
            online: navigator.onLine !== false
        }).then(function (d) {
            if (d.reload && state.reload !== null && d.reload > state.reload) { set('reload-seen', String(d.reload)); hardReload(); }
        }, handleError);
        /* a fresh token about once a month */
        var rotated = parseInt(get('rotated') || '0', 10);
        if (!rotated) { set('rotated', String(Date.now())); } else if (Date.now() - rotated > 30 * 86400000) {
            request('POST', base + '/rotate', {}).then(function (d) {
                if (d.token) { state.token = d.token; set('token', d.token); callBridge('saveToken', d.token); set('rotated', String(Date.now())); }
            }, function () {});
        }
    }
    function guessDevice() {
        var ua = navigator.userAgent;
        if (/Tizen/i.test(ua)) { return 'samsung-tizen'; }
        if (/Web0S|webOS/i.test(ua)) { return 'lg-webos'; }
        if (/AFT|Silk/i.test(ua)) { return 'fire-tv'; }
        if (/Android/i.test(ua) && /TV|AFT|BRAVIA|MiBOX|Mi Box|SHIELD/i.test(ua)) { return 'android-tv'; }
        if (/Android/i.test(ua)) { return 'android'; }
        return 'pc';
    }
    function everyMinute() {
        var clocks = document.querySelectorAll('[data-clock]'), t = clockText();
        for (var i = 0; i < clocks.length; i++) { clocks[i].textContent = t; }
        heartbeat();
        online(navigator.onLine !== false && (!state.lastOk || Date.now() - state.lastOk < 150000));
        /* a stuck area (nothing changed for 15 minutes) starts again; once a night around 4:00 the screen reloads */
        var d = cairo();
        zones.forEach(function (z) { if (Date.now() - z.lastAdvance > 900000) { next(z); } });
        if (d.getUTCHours() === 4 && d.getUTCMinutes() < 10 && Date.now() - state.started > 3600000 && get('night') !== ymd(d)) {
            set('night', ymd(d));
            hardReload();
        }
    }

    /* ---------- pairing (device mode, no token yet) ---------- */
    var pairEl = document.getElementById('pair');
    function startPairing() {
        clearTimeout(state.pairTimer);
        clearTimeout(state.pollTimer);
        clearTimeout(state.liveTimer);
        zones.forEach(function (z) { clearTimeout(z.timer); clearTimeout(z.readyTimer); clearInterval(z.tickTimer); });
        state.data = null;
        state.zoneLayout = null;
        zones = [];
        stage.innerHTML = '';
        tickerEl.innerHTML = '';
        showAlert(null);
        layout();
        pairEl.className = 'on';
        document.getElementById('note').className = '';
        document.getElementById('pair-code').textContent = '······';
        document.getElementById('pair-left').textContent = T.connecting;
        setTimeout(function () { var b = document.getElementById('pair-new'); if (b) { b.focus(); } }, 100);
        request('POST', base + '/pair', {}).then(function (d) {
            state.pairing = { secret: d.secret, until: Date.now() + d.expires_in * 1000 };
            document.getElementById('pair-code').textContent = d.code.substr(0, 3) + ' ' + d.code.substr(3);
            document.getElementById('pair-qr').innerHTML = d.qr;
            checkPairing();
        }, function () {
            document.getElementById('pair-left').textContent = T.no_network;
            state.pairTimer = setTimeout(startPairing, 15000);
        });
    }
    function checkPairing() {
        clearTimeout(state.pairTimer);
        var p = state.pairing;
        if (!p) { return; }
        var left = Math.round((p.until - Date.now()) / 1000);
        if (left <= 0) { startPairing(); return; }
        document.getElementById('pair-left').textContent = T.expires.replace('%s', left);
        request('GET', base + '/pair/status', null, { 'X-Pair-Secret': p.secret }).then(function (d) {
            if (d.state === 'paired' && d.token) {
                state.pairing = null;
                state.token = d.token;
                set('token', d.token);
                set('rotated', String(Date.now()));
                callBridge('saveToken', d.token);
                document.getElementById('pair-left').textContent = T.paired;
                loadPlaylist().then(function () { poll(); live(); heartbeat(); });
                return;
            }
            if (d.state === 'expired' || d.state === 'used') { startPairing(); return; }
            state.pairTimer = setTimeout(checkPairing, 3000);
        }, function () { state.pairTimer = setTimeout(checkPairing, 5000); });
    }
    function hideOverlays() {
        pairEl.className = '';
        document.getElementById('note').className = '';
    }
    function note(text) {
        document.getElementById('note-text').innerHTML = esc(text);
        document.getElementById('note').className = 'on';
    }
    function fullScreen() {
        var d = document.documentElement, f = d.requestFullscreen || d.webkitRequestFullscreen || d.webkitRequestFullScreen;
        try { if (f) { var r = f.call(d); if (r && r['catch']) { r['catch'](function () {}); } } } catch (e) {}
    }
    document.getElementById('pair-new').onclick = function () { startPairing(); };
    document.getElementById('pair-full').onclick = fullScreen;
    /* a TV remote: left / right move between the two buttons, OK presses; any key on the playing screen asks for full screen */
    document.addEventListener('keydown', function (e) {
        var k = e.keyCode;
        if (pairEl.className === 'on') {
            var a = document.getElementById('pair-new'), b = document.getElementById('pair-full');
            if (k === 37 || k === 39) { (document.activeElement === a ? b : a).focus(); e.preventDefault(); }
            if (k === 13 && document.activeElement && document.activeElement.click) { document.activeElement.click(); e.preventDefault(); }
            return;
        }
        if (k === 13) { fullScreen(); }
    });

    /* ---------- start ---------- */
    function keepAwake() {
        callBridge('keepAwake');
        try { if (navigator.wakeLock && navigator.wakeLock.request) { navigator.wakeLock.request('screen')['catch'](function () {}); } } catch (e) {}
    }
    window.addEventListener('resize', function () {
        layout();
        if (alertEl.className.indexOf('on') !== -1) { fitAlert(); }
        zones.forEach(function (z) { if (z.cur && (z.cur.type === 'menu' || z.cur.type === 'promo')) { clearInterval(z.tickTimer); z.page = 0; render(z, z.cur); } });
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { keepAwake(); } });
    window.addEventListener('online', function () { online(true); poll(); live(); });
    window.addEventListener('offline', function () { online(false); });
    if (mode === 'device' && 'serviceWorker' in navigator) {
        try { navigator.serviceWorker.register('/screen/sw.js', { scope: '/screen' })['catch'](function () {}); } catch (e) {}
    }
    layout();
    keepAwake();
    setInterval(everyMinute, 60000);
    /* a message that was on before a restart shows again at once (it ends on its own time) */
    try { var kept = get('alert'); if (kept) { showAlert(JSON.parse(kept)); } } catch (e) {}

    if (mode === 'device') {
        state.token = get('token') || callBridge('getToken') || null;
        var cached = get('playlist:' + storeKey);
        if (state.token && cached) { try { useData(JSON.parse(cached), true); } catch (e) {} }
        /* with no stored token the httpOnly cookie may still know the screen: ask once before showing a code (a 401 starts pairing) */
        loadPlaylist().then(function (d) {
            if (pairEl.className === 'on') { return; }
            if (!d && !state.data && document.getElementById('note').className !== 'on') { note(T.no_network); }
            poll();
            live();
            heartbeat();
        });
    } else {
        var saved = get('playlist:' + storeKey);
        if (cfg.boot) { useData(cfg.boot, false); } else if (saved) { try { useData(JSON.parse(saved), true); } catch (e) {} }
        poll();
        live();
    }
})();
</script>
@endverbatim
</body>
</html>
