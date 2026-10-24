{{-- POS: the cashier screen (item buttons and the ticket) and its printed receipt --}}
<div class="abs" style="left: 0; top: 40px; width: 424px; height: 290px; border-radius: 22px; background: #0B1222; padding: 12px; box-shadow: 0 30px 70px rgba(0,0,0,.5)">
    <div style="height: 100%; border-radius: 12px; background: #F0F6FC; display: grid; grid-template-columns: 1fr 140px; gap: 10px; padding: 10px; direction: ltr">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px">
            @foreach (['#FFE5D4', '#FEF3C7', '#FFE4E6', '#E1EBF5', '#DCFCE7', '#FFE5D4', '#FEF3C7', '#E1EBF5', '#FFE4E6'] as $tint)
                <div style="border-radius: 10px; background: {{ $tint }}; display: grid; place-items: center; color: #F26522"><x-landing.icon :name="$loop->index % 2 ? 'plate' : 'cup'" class="i" style="width: 26px; height: 26px" /></div>
            @endforeach
        </div>
        <div style="border-radius: 10px; background: #fff; padding: 10px; display: flex; flex-direction: column; gap: 8px">
            @for ($line = 0; $line < 4; $line++)<div class="row" style="gap: 6px"><span class="line soft" style="flex: 1; height: 8px"></span><span class="line" style="width: 24px; height: 8px"></span></div>@endfor
            <div style="flex: 1"></div>
            <div class="t" style="border-radius: 8px; background: #F26522; color: #fff; text-align: center; padding: 6px 0; font-size: 15px" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">{{ __('Pay') }}</div>
        </div>
    </div>
</div>
<div class="card" style="left: 250px; top: 250px; width: 170px; padding: 16px; border-radius: 8px; transform: rotate(5deg)">
    <div class="t" style="text-align: center; font-size: 18px">{{ __('Rosto') }}</div>
    @for ($line = 0; $line < 3; $line++)<div class="row" style="margin-top: 9px; gap: 6px"><span class="line soft" style="flex: 1; height: 7px"></span><span class="line" style="width: 22px; height: 7px"></span></div>@endfor
    <div class="row" style="margin-top: 12px; padding-top: 8px; border-top: 2px dashed #C3D5E8; justify-content: space-between"><span class="t" style="font-size: 15px">{{ __('Total') }}</span><span class="line or" style="width: 34px; height: 8px"></span></div>
</div>
<div class="toast" style="left: 0; bottom: 40px">
    <span class="ic solid"><x-landing.icon name="cash" class="i" /></span>{{ __('Cash') }}
    <span class="chip soft">{{ __('Card') }}</span>
</div>
