{{-- pager: the guest's phone rings "your order is ready" while the counter presses call --}}
<div class="ring" style="left: 2px; top: 50px; width: 420px; height: 420px; opacity: .35"></div>
<div class="ring" style="left: 52px; top: 100px; width: 320px; height: 320px; opacity: .7"></div>
<div class="phone" style="left: 92px; top: 14px">
    <div class="scr" style="background: #F26522; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 0 18px 64px">
        <span class="ic big" style="background: #fff; width: 88px; height: 88px"><x-landing.icon name="bell" class="i" style="width: 48px; height: 48px" /></span>
        <div class="t" style="margin-top: 18px; font-size: 30px; font-weight: 900">{{ __('Your order is ready!') }}</div>
        <div style="font-size: 64px; font-weight: 900; line-height: 1.1; direction: ltr">#42</div>
        <div class="t" style="margin-top: 22px; width: 100%; border-radius: 16px; background: #fff; color: #0A2540; padding: 12px 0; font-size: 19px">✓ {{ __('I picked it up') }}</div>
    </div>
</div>
<div class="toast" style="left: 0; bottom: 4px">
    <span class="ic solid"><x-landing.icon name="bell" class="i" /></span>
    <span style="direction: ltr">#42</span>
    <span class="chip or">{{ __('Call the guest') }}</span>
</div>
