{{-- offers: a happy hour that starts and ends by itself, old prices crossed out --}}
<div class="card" style="left: 20px; top: 40px; width: 384px; overflow: hidden">
    <div class="row" style="background: #F26522; color: #fff; padding: 18px 22px; gap: 12px">
        <span class="ic" style="background: #fff"><x-landing.icon name="clock" class="i" /></span>
        <span class="t" style="font-size: 28px; font-weight: 900">{{ __('Happy hour') }}</span>
    </div>
    <div style="padding: 8px 22px 22px">
        @foreach ([__('Latte'), __('Cappuccino'), __('Espresso')] as $itemName)
            <div class="row" style="margin-top: 16px; gap: 12px">
                <span class="thumb" style="background: #FFE5D4; display: grid; place-items: center; color: #F26522"><x-landing.icon name="cup" class="i" style="width: 26px; height: 26px" /></span>
                <span class="t" style="flex: 1; font-size: 20px">{{ $itemName }}</span>
                <span style="position: relative; width: 44px; height: 10px; border-radius: 5px; background: #C3D5E8"><span style="position: absolute; inset-inline: -4px; top: 3px; height: 3px; background: #9F1239; transform: rotate(-12deg)"></span></span>
                <span class="line or" style="width: 44px; height: 10px"></span>
            </div>
        @endforeach
    </div>
</div>
<div class="toast" style="left: 0; bottom: 40px">
    <span class="ic solid"><x-landing.icon name="percent" class="i" /></span>{{ __('Combo') }}
</div>
