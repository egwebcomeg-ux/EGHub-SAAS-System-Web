{{-- wallet: prepaid balance on the customer's card, gift cards and cashback --}}
<div class="card" style="left: 20px; top: 40px; width: 384px; height: 232px; padding: 24px; background: #0A2540; color: #fff; transform: rotate(-4deg); border: 2px solid rgba(255,255,255,.12)">
    <div class="row" style="justify-content: space-between">
        <span class="t" style="font-size: 24px">{{ __('Rosto') }}</span>
        <span class="ic solid"><x-landing.icon name="wallet" class="i" /></span>
    </div>
    <div class="t" style="margin-top: 34px; font-size: 18px; color: #C5CFE0">{{ __('Balance') }}</div>
    <div class="line or" style="margin-top: 10px; width: 150px; height: 18px; border-radius: 9px"></div>
    <div class="row" style="position: absolute; bottom: 22px; inset-inline-start: 24px; gap: 8px; direction: ltr">
        @for ($dot = 0; $dot < 4; $dot++)<span class="line" style="width: 40px; height: 8px; background: rgba(255,255,255,.4)"></span>@endfor
    </div>
</div>
<div class="toast" style="right: 0; top: 300px">
    <span class="ic"><x-landing.icon name="gift" class="i" /></span>{{ __('Gift card') }}
</div>
<div class="toast" style="left: 0; top: 380px">
    <span class="ic green"><x-landing.icon name="percent" class="i" /></span>{{ __('Cashback') }}
</div>
<div class="toast" style="right: 20px; bottom: 0">
    <span class="ic solid"><x-landing.icon name="wallet" class="i" /></span>{{ __('Top up') }}
</div>
