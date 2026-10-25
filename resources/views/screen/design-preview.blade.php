{{--
    One ready design drawn exactly as the player draws it (screen.partials.designs), inside the dashboard picker's
    small iframes. The restaurant's brand comes with the page; the item or the text typed in the form comes from the
    parent page (postMessage, same origin only). Sized like a screen: 1rem = 1% of the short side.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $lang === 'en' ? 'ltr' : 'rtl' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ \App\Support\ScreenDesigns::label($key) }}</title>
@fonts
<style>
html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background: #0A2540; }
body { font-family: 'Cairo', 'Segoe UI', Tahoma, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
#box { position: absolute; left: 0; top: 0; width: 100%; height: 100%; overflow: hidden; }
</style>
@include('screen.partials.designs')
</head>
<body>
<div id="box" class="{{ $portrait ? 'portrait' : 'landscape' }} {{ $lang === 'en' ? 'ltr' : 'rtl' }}"></div>
<script type="application/json" id="cfg">{!! json_encode(['key' => $key, 'brand' => $brand, 'labels' => $labels, 'lang' => $lang, 'sample' => [
    'title' => __('Item name'), 'desc' => __('A short line about it'), 'price' => '120', 'was' => '150', 'bg' => 'dark',
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@verbatim
<script>
(function () {
    'use strict';
    var cfg = JSON.parse(document.getElementById('cfg').textContent);
    var box = document.getElementById('box');
    var data = cfg.sample, timer = null;
    window.EgDesigns.labels = cfg.labels;
    function draw() {
        var w = window.innerWidth, h = window.innerHeight;
        document.documentElement.style.fontSize = (Math.min(w, h) / 100) + 'px';
        box.className = (h > w ? 'portrait' : 'landscape') + ' ' + (cfg.lang === 'en' ? 'ltr' : 'rtl');
        var d = {};
        for (var k in data) { if (data.hasOwnProperty(k)) { d[k] = data[k]; } }
        d.brand = cfg.brand;
        d.labels = cfg.labels;
        d.lang = cfg.lang;
        d.now = Date.now();
        box.innerHTML = window.EgDesigns.html(data.key || cfg.key, d, {});
        window.EgDesigns.fit(box);
        clearInterval(timer);
        if (d.ends) { timer = setInterval(function () { window.EgDesigns.tick(box, Date.now()); }, 1000); }
    }
    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin || !e.data || e.data.type !== 'eghub-design') { return; }
        data = e.data.data || {};
        draw();
    });
    window.addEventListener('resize', draw);
    draw();
    try { window.parent.postMessage({ type: 'eghub-design-ready', key: cfg.key }, location.origin); } catch (e) {}
})();
</script>
@endverbatim
</body>
</html>
