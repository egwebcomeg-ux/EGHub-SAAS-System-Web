{{-- call center: the caller found by their number, their address already saved --}}
<div class="ring" style="left: 0; top: 0; width: 150px; height: 150px; opacity: .45"></div>
<div class="ring" style="left: 20px; top: 20px; width: 110px; height: 110px; opacity: .8"></div>
<div class="abs" style="left: 35px; top: 35px"><span class="ic solid" style="width: 80px; height: 80px"><x-landing.icon name="phone" class="i" style="width: 40px; height: 40px" /></span></div>
<div class="card" style="left: 60px; top: 160px; width: 350px; padding: 22px">
    <div class="row" style="gap: 12px">
        <span class="ic blue" style="width: 54px; height: 54px"><x-landing.icon name="users" class="i" /></span>
        <div style="flex: 1"><div class="line" style="width: 60%"></div><div class="line soft" style="margin-top: 9px; width: 40%"></div></div>
    </div>
    <div class="row" style="margin-top: 18px; gap: 12px; padding: 12px; border-radius: 14px; background: #F0F6FC">
        <span class="ic"><x-landing.icon name="flag" class="i" /></span>
        <div style="flex: 1"><div class="t" style="font-size: 18px">{{ __('Address') }}</div><div class="line soft" style="margin-top: 7px; width: 80%; background: #C3D5E8"></div></div>
    </div>
    <div class="row" style="margin-top: 16px; gap: 10px">
        <span class="chip soft">{{ __('Delivery') }}</span>
        <span class="t" style="flex: 1; border-radius: 12px; background: #F26522; color: #fff; text-align: center; padding: 8px 0; font-size: 18px">{{ __('Send') }}</span>
    </div>
</div>
<div class="toast" style="right: 0; top: 40px">
    <span class="ic blue"><x-landing.icon name="phone" class="i" /></span>{{ __('Call center') }}
</div>
