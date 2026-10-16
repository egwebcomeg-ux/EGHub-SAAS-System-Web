{{--
    Ad tracking pixels of the restaurant (App\Services\Marketing\TrackingPixels), public menu pages only.
    Defines window.egmTrack(event, params) for ViewContent / AddToCart / Purchase from the menu script; PageView fires here.
    $purchase (optional): ['id' => order number, 'value' => total, 'items' => [{id, name, qty}]] fires Purchase once per order.
--}}
@php
    $pixelIds = \App\Services\Marketing\TrackingPixels::ids();
    $purchase = $purchase ?? null;
@endphp
@if (array_filter($pixelIds))
<script data-egm-pixels>
(function () {
    var ga4 = { ViewContent: 'view_item', AddToCart: 'add_to_cart', Purchase: 'purchase' };
    var tiktok = { ViewContent: 'ViewContent', AddToCart: 'AddToCart', Purchase: 'PlaceAnOrder' };
    window.egmTrack = function (event, params) {
        params = Object.assign({ currency: 'EGP' }, params || {});
        try { if (window.fbq) fbq('track', event, params); } catch (e) {}
        try { if (window.gtag && ga4[event]) gtag('event', ga4[event], Object.assign({ transaction_id: params.order_id }, params)); } catch (e) {}
        try { if (window.ttq && tiktok[event]) ttq.track(tiktok[event], params); } catch (e) {}
    };
})();
</script>
@if ($pixelIds['meta'])
<script data-egm-pixel="meta">
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{{ $pixelIds['meta'] }}');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ $pixelIds['meta'] }}&ev=PageView&noscript=1"></noscript>
@endif
@if ($pixelIds['ga4'])
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $pixelIds['ga4'] }}" data-egm-pixel="ga4"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '{{ $pixelIds['ga4'] }}', { currency: 'EGP' });
</script>
@endif
@if ($pixelIds['tiktok'])
<script data-egm-pixel="tiktok">
!function (w, d, t) {
  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
  ttq.load('{{ $pixelIds['tiktok'] }}');
  ttq.page();
}(window, document, 'ttq');
</script>
@endif
@if ($purchase)
<script data-egm-purchase>
(function () {
    var p = @json($purchase, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    var key = 'egm-purchased-' + p.id;
    try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch (e) {}
    window.addEventListener('load', function () {
        window.egmTrack('Purchase', { order_id: String(p.id), value: p.value, currency: 'EGP', num_items: p.items.length,
            content_type: 'product', content_ids: p.items.map(function (i) { return String(i.id); }), contents: p.items });
    });
})();
</script>
@endif
@endif
