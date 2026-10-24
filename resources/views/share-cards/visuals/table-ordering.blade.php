{{-- table ordering: the guest sends the order from the table's QR, the board rings --}}
<div class="phone" style="left: 150px; top: 14px">
    <div class="scr" style="padding: 30px 16px 0">
        <div class="row" style="justify-content: space-between">
            <span class="t" style="font-size: 22px">{{ __('Rosto') }}</span>
            <span class="chip soft">{{ __('Table') }} 5</span>
        </div>
        @foreach ([[__('Latte'), '#FFE5D4', 2], [__('Cappuccino'), '#FEF3C7', 1], [__('Espresso'), '#FFE4E6', 1]] as [$itemName, $tint, $quantity])
            <div class="row" style="margin-top: 14px; gap: 10px">
                <span class="thumb" style="background: {{ $tint }}; display: grid; place-items: center; color: #F26522"><x-landing.icon name="cup" class="i" style="width: 26px; height: 26px" /></span>
                <span class="t" style="flex: 1; font-size: 18px">{{ $itemName }}</span>
                <span class="chip soft" style="direction: ltr">× {{ $quantity }}</span>
            </div>
        @endforeach
        <div class="row" style="margin-top: 18px; padding-top: 12px; border-top: 2px dashed #E1E7F1; justify-content: space-between">
            <span class="t" style="font-size: 18px">{{ __('Total') }}</span><span class="line or" style="width: 56px"></span>
        </div>
        <div class="t" style="position: absolute; inset-inline: 14px; bottom: 16px; border-radius: 16px; background: #F26522; color: #fff; text-align: center; padding: 11px 0; font-size: 19px">{{ __('Checkout') }}</div>
    </div>
</div>
<div class="toast" style="left: 0; top: 330px">
    <span class="ic solid"><x-landing.icon name="bell" class="i" /></span>{{ __('New order') }}
    <span class="chip soft">{{ __('Table') }} 5</span>
</div>
