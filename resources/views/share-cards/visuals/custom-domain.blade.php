{{-- custom domain: the menu at the place's own address (rosto.com, the page's own example) --}}
<div class="card" style="left: 0; top: 60px; width: 424px; height: 330px; overflow: hidden">
    <div class="row" style="gap: 8px; padding: 14px 16px; background: #F0F6FC; direction: ltr">
        <span style="width: 12px; height: 12px; border-radius: 50%; background: #F87171"></span><span style="width: 12px; height: 12px; border-radius: 50%; background: #FBBF24"></span><span style="width: 12px; height: 12px; border-radius: 50%; background: #34D399"></span>
        <span class="row t" style="flex: 1; gap: 8px; margin-left: 10px; padding: 8px 14px; border-radius: 999px; background: #fff; font-size: 22px"><x-landing.icon name="shield" class="i" style="width: 22px; height: 22px; color: #16A34A" />rosto.com</span>
    </div>
    <div style="padding: 20px 22px">
        <div class="t" style="font-size: 26px">{{ __('Rosto') }}</div>
        <div class="row" style="margin-top: 12px; gap: 8px"><span class="chip or">{{ __('Drinks') }}</span><span class="chip soft">{{ __('Desserts') }}</span></div>
        @foreach ([__('Latte'), __('Cappuccino')] as $itemName)
            <div class="row" style="margin-top: 14px; gap: 12px">
                <span class="thumb" style="background: #FFE5D4; display: grid; place-items: center; color: #F26522"><x-landing.icon name="cup" class="i" style="width: 26px; height: 26px" /></span>
                <span class="t" style="flex: 1; font-size: 19px">{{ $itemName }}</span><span class="line or" style="width: 44px"></span>
            </div>
        @endforeach
    </div>
</div>
<div class="toast" style="right: 10px; bottom: 50px">
    <span class="ic"><x-landing.icon name="globe" class="i" /></span><span style="direction: ltr">rosto.com</span>
</div>
