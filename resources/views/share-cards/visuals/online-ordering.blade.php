{{-- online ordering: pickup or delivery from the place's own link, no commission --}}
<div class="phone" style="left: 150px; top: 14px">
    <div class="scr" style="padding: 30px 16px 0">
        <div class="t" style="font-size: 22px">{{ __('Rosto') }}</div>
        <div class="row" style="margin-top: 12px; gap: 6px; flex-wrap: wrap">
            <span class="chip or">{{ __('Delivery') }}</span><span class="chip soft">{{ __('Pickup') }}</span>
        </div>
        @foreach ([[__('Pizza'), '#FFE5D4', 'plate'], [__('Sandwiches'), '#FEF3C7', 'bag'], [__('Desserts'), '#FFE4E6', 'cup']] as [$itemName, $tint, $icon])
            <div class="row" style="margin-top: 13px; gap: 10px">
                <span class="thumb" style="background: {{ $tint }}; display: grid; place-items: center; color: #F26522"><x-landing.icon :name="$icon" class="i" style="width: 26px; height: 26px" /></span>
                <div style="flex: 1">
                    <div class="t" style="font-size: 18px">{{ $itemName }}</div>
                    <div class="line or" style="margin-top: 6px; width: 46px; height: 9px"></div>
                </div>
            </div>
        @endforeach
        <div class="t" style="position: absolute; inset-inline: 14px; bottom: 16px; border-radius: 16px; background: #F26522; color: #fff; text-align: center; padding: 11px 0; font-size: 19px">{{ __('Checkout') }}</div>
    </div>
</div>
<div class="toast" style="left: 0; top: 60px">
    <span class="ic"><x-landing.icon name="bag" class="i" /></span>{{ __('New order') }}
</div>
<div class="toast" style="left: 10px; bottom: 50px">
    <span class="ic blue"><x-landing.icon name="scooter" class="i" /></span>{{ __('On the way') }}
</div>
