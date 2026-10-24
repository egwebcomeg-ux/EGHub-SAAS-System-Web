{{-- TV menu screens: the menu board on the shop TV, today's prices --}}
<div class="abs" style="left: 0; top: 70px; width: 424px; height: 252px; border-radius: 18px; background: #0B1222; padding: 12px; box-shadow: 0 30px 70px rgba(0,0,0,.5)">
    <div style="height: 100%; border-radius: 8px; background: #fff; padding: 18px 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 6px 26px">
        @foreach ([[__('Drinks'), [__('Latte'), __('Cappuccino'), __('Espresso')]], [__('Desserts'), [null, null, null]]] as [$section, $items])
            <div>
                <div class="t" style="font-size: 21px; color: #F26522">{{ $section }}</div>
                @foreach ($items as $itemName)
                    <div class="row" style="margin-top: 14px; gap: 10px; height: 24px">
                        @if ($itemName)
                            <span class="t" style="flex: 1; font-size: 17px">{{ $itemName }}</span>
                        @else
                            <span class="line" style="flex: 1; height: 9px"></span>
                        @endif
                        <span class="line or" style="flex: none; width: 34px; height: 9px"></span>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
<div class="abs" style="left: 192px; top: 322px; width: 40px; height: 34px; background: #0B1222"></div>
<div class="abs" style="left: 132px; top: 352px; width: 160px; height: 14px; border-radius: 7px; background: #0B1222"></div>
<div class="toast" style="right: 6px; bottom: 50px">
    <span class="ic"><x-landing.icon name="zap" class="i" /></span>{{ __('Updates by itself') }}
</div>
