{{-- a guest's phone showing a menu (Rosto, the demo cafe of the landing scenes): $phoneLeft, $phoneTop, $phoneScale, $phoneButton --}}
<div class="phone" style="left: {{ $phoneLeft ?? 92 }}px; top: {{ $phoneTop ?? 14 }}px; transform: scale({{ $phoneScale ?? 1 }}); transform-origin: top left">
    <div class="scr">
        <div style="background: #0A2540; color: #fff; padding: 30px 20px 18px">
            <div class="t" style="font-size: 24px">{{ __('Rosto') }}</div>
            <div class="line" style="margin-top: 8px; width: 90px; height: 9px; background: rgba(255,255,255,.45)"></div>
        </div>
        <div class="row" style="gap: 8px; padding: 14px 16px 6px">
            <span class="chip or">{{ __('Drinks') }}</span><span class="chip soft">{{ __('Desserts') }}</span>
        </div>
        @foreach ([[__('Latte'), '#FFE5D4'], [__('Cappuccino'), '#FEF3C7'], [__('Espresso'), '#FFE4E6']] as [$itemName, $tint])
            <div class="row" style="margin: 9px 14px 0; padding: 8px; border: 2px solid #E1E7F1; border-radius: 16px">
                <span class="thumb" style="background: {{ $tint }}; display: grid; place-items: center; color: #F26522"><x-landing.icon name="cup" class="i" style="width: 26px; height: 26px" /></span>
                <div style="flex: 1">
                    <div class="t" style="font-size: 18px">{{ $itemName }}</div>
                    <div class="line or" style="margin-top: 6px; width: 46px; height: 9px"></div>
                </div>
            </div>
        @endforeach
        <div class="t" style="position: absolute; inset-inline: 14px; bottom: 16px; border-radius: 16px; background: #F26522; color: #fff; text-align: center; padding: 11px 0; font-size: 19px">{{ $phoneButton ?? __('Menu') }}</div>
    </div>
</div>
