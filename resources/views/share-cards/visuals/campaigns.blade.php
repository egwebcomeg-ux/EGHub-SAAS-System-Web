{{-- WhatsApp campaigns: one message with a coupon to the customers you pick --}}
<div class="abs" style="left: 0; top: 20px"><span class="ic solid" style="width: 112px; height: 112px"><x-landing.icon name="megaphone" class="i" style="width: 60px; height: 60px" /></span></div>
<div class="card" style="left: 70px; top: 150px; width: 340px; padding: 20px">
    <div class="row" style="gap: 6px; direction: ltr">
        @foreach (['#FFE5D4', '#E1EBF5', '#DCFCE7', '#FEF3C7'] as $tint)
            <span class="ic" style="width: 40px; height: 40px; background: {{ $tint }}; color: #0A2540; margin-inline-start: {{ $loop->first ? 0 : -10 }}px; border: 3px solid #fff"><x-landing.icon name="users" class="i" style="width: 20px; height: 20px" /></span>
        @endforeach
    </div>
    <div style="margin-top: 14px; padding: 14px; border-radius: 16px; background: #DCF8C6">
        <div class="line" style="width: 85%"></div>
        <div class="line" style="margin-top: 10px; width: 60%"></div>
        <div class="row" style="margin-top: 14px; gap: 10px; padding: 10px 12px; border: 3px dashed #F26522; border-radius: 12px; background: #fff">
            <span class="ic"><x-landing.icon name="percent" class="i" /></span>
            <span class="t" style="font-size: 22px; letter-spacing: 2px; direction: ltr">BACK20</span>
        </div>
    </div>
    <div class="t" style="margin-top: 14px; border-radius: 14px; background: #F26522; color: #fff; text-align: center; padding: 10px 0; font-size: 20px">{{ __('Send') }}</div>
</div>
