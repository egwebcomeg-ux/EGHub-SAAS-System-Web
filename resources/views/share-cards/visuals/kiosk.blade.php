{{-- self-order kiosk: a tablet at the counter, the guest orders and takes a number --}}
<div class="abs" style="left: 120px; top: 4px; width: 250px; height: 380px; border-radius: 26px; background: #0B1222; padding: 12px; box-shadow: 0 30px 70px rgba(0,0,0,.5)">
    <div style="height: 100%; border-radius: 16px; background: #fff; padding: 14px; position: relative">
        <div class="t" style="font-size: 20px">{{ __('Rosto') }}</div>
        <div style="margin-top: 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 9px">
            @foreach ([[__('Latte'), '#FFE5D4'], [__('Cappuccino'), '#FEF3C7'], [__('Espresso'), '#FFE4E6'], [__('Pizza'), '#E1EBF5']] as [$itemName, $tint])
                <div style="border-radius: 12px; background: {{ $tint }}; padding: 10px 6px; text-align: center">
                    <span style="color: #F26522"><x-landing.icon :name="$loop->last ? 'plate' : 'cup'" class="i" style="width: 34px; height: 34px" /></span>
                    <div class="t" style="font-size: 15px">{{ $itemName }}</div>
                </div>
            @endforeach
        </div>
        <div class="t" style="position: absolute; inset-inline: 14px; bottom: 14px; border-radius: 12px; background: #F26522; color: #fff; text-align: center; padding: 9px 0; font-size: 17px">{{ __('Checkout') }}</div>
    </div>
</div>
<div class="abs" style="left: 225px; top: 384px; width: 40px; height: 70px; background: #0B1222"></div>
<div class="abs" style="left: 165px; top: 450px; width: 160px; height: 16px; border-radius: 8px; background: #0B1222"></div>
<div class="card" style="left: 0; top: 230px; width: 170px; padding: 16px; text-align: center; transform: rotate(-5deg)">
    <div class="t muted" style="font-size: 16px">{{ __('Order number') }}</div>
    <div style="font-size: 50px; font-weight: 900; line-height: 1.1; direction: ltr">#42</div>
</div>
