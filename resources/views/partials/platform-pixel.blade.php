{{--
    EgHub's own Meta pixel (admin › الإعدادات › platform_meta_pixel): the marketing site and the directory count
    PageView and Contact (a tap on a WhatsApp / contact-sales link); the first dashboard page after a self sign-up counts
    CompleteRegistration once ($event). Never on restaurant menus, which carry the restaurant's own pixels.
--}}
@php
    $platformPixel = preg_replace('/\D/', '', (string) \App\Models\PlatformSetting::get('platform_meta_pixel', ''));
    $pixelEvent = $event ?? null;
@endphp
@if ($platformPixel !== '' && ($pixelEvent || ! tenancy()->initialized))
<script data-platform-pixel>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{{ $platformPixel }}');
fbq('track', 'PageView');
@if ($pixelEvent)
fbq('track', @json($pixelEvent));
@else
document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href*="wa.me/"], [data-contact-sales-link]');
    if (a) { try { fbq('track', 'Contact'); } catch (err) {} }
});
@endif
</script>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ $platformPixel }}&ev=PageView&noscript=1"></noscript>
@endif
