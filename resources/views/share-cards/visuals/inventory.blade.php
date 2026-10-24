{{-- inventory: stock that goes down with every order, and what each recipe costs --}}
<div class="card" style="left: 10px; top: 30px; width: 404px; padding: 22px">
    <div class="row t" style="gap: 10px; font-size: 22px"><span class="ic"><x-landing.icon name="box" class="i" /></span>{{ __('Stock') }}</div>
    @foreach ([[0.82, '#16A34A'], [0.55, '#F59E0B'], [0.14, '#E11D48']] as [$level, $color])
        <div class="row" style="margin-top: 18px; gap: 12px">
            <span class="thumb" style="background: #F0F6FC; display: grid; place-items: center; color: #3E5F80"><x-landing.icon name="box" class="i" style="width: 24px; height: 24px" /></span>
            <div style="flex: 1">
                <div class="line" style="width: {{ 40 + $loop->index * 12 }}%; height: 9px"></div>
                <div style="margin-top: 8px; height: 12px; border-radius: 6px; background: #E1E7F1; overflow: hidden"><div style="width: {{ $level * 100 }}%; height: 100%; background: {{ $color }}; border-radius: 6px"></div></div>
            </div>
        </div>
    @endforeach
</div>
<div class="card" style="left: 140px; top: 330px; width: 270px; padding: 16px 18px; transform: rotate(3deg)">
    <div class="row" style="justify-content: space-between"><span class="t" style="font-size: 19px">{{ __('Recipe') }}</span><span class="chip soft">{{ __('Latte') }}</span></div>
    <div class="row" style="margin-top: 10px; justify-content: space-between"><span class="t muted" style="font-size: 17px">{{ __('Cost') }}</span><span class="line or" style="width: 50px"></span></div>
</div>
