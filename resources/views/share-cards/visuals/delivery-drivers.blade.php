{{-- delivery drivers: the driver on the map, on the way to the customer's pin --}}
<div class="card" style="left: 10px; top: 30px; width: 404px; height: 340px; overflow: hidden; background: #EEF3F8">
    <svg width="404" height="340" viewBox="0 0 404 340" style="position: absolute; inset: 0" aria-hidden="true">
        <g stroke="#fff" stroke-width="18" fill="none" stroke-linecap="round"><path d="M-10 80 H420"/><path d="M-10 250 H420"/><path d="M120 -10 V350"/><path d="M300 -10 V350"/><path d="M-10 330 L200 160 L420 20"/></g>
        <g fill="#DDE8D6"><rect x="140" y="100" width="140" height="130" rx="14"/><rect x="10" y="100" width="90" height="130" rx="14"/></g>
        <path d="M70 250 H120 V80 H300 V40" fill="none" stroke="#F26522" stroke-width="7" stroke-dasharray="14 10" stroke-linecap="round"/>
        <circle cx="70" cy="250" r="12" fill="#005BA6" stroke="#fff" stroke-width="5"/>
    </svg>
    <div class="abs" style="left: 272px; top: 0"><svg width="56" height="72" viewBox="0 0 24 30" aria-hidden="true"><path d="M12 29s10-9.5 10-17A10 10 0 0 0 2 12c0 7.5 10 17 10 17z" fill="#F26522"/><circle cx="12" cy="12" r="4.5" fill="#fff"/></svg></div>
    <div class="abs" style="left: 120px; top: 118px; transform: translate(-50%, -50%)"><span class="ic solid" style="width: 62px; height: 62px; border: 4px solid #fff"><x-landing.icon name="scooter" class="i" style="width: 34px; height: 34px" /></span></div>
</div>
<div class="toast" style="left: 0; bottom: 40px">
    <span class="ic blue"><x-landing.icon name="scooter" class="i" /></span>{{ __('Driver') }}
    <span class="chip or">{{ __('On the way') }}</span>
</div>
