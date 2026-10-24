{{-- loyalty card: stamps on the guest's phone, the last one is on the house --}}
<div class="card" style="left: 12px; top: 70px; width: 400px; padding: 24px 26px; transform: rotate(-3deg)">
    <div class="row" style="justify-content: space-between">
        <span class="row t" style="gap: 10px; font-size: 24px"><span class="ic"><x-landing.icon name="cup" class="i" /></span>{{ __('Rosto') }}</span>
        <span class="chip soft">{{ __('Stamps') }}</span>
    </div>
    <div style="margin-top: 22px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; justify-items: center">
        @for ($stamp = 1; $stamp <= 8; $stamp++)
            @if ($stamp === 8)
                <span style="width: 66px; height: 66px; border-radius: 50%; border: 3px dashed #F26522; display: grid; place-items: center; color: #F26522"><x-landing.icon name="gift" class="i" style="width: 32px; height: 32px" /></span>
            @elseif ($stamp <= 5)
                <span style="width: 66px; height: 66px; border-radius: 50%; background: #F26522; color: #fff; display: grid; place-items: center"><x-landing.icon name="cup" class="i" style="width: 32px; height: 32px" /></span>
            @else
                <span style="width: 66px; height: 66px; border-radius: 50%; border: 3px solid #E1E7F1"></span>
            @endif
        @endfor
    </div>
    <div class="row" style="margin-top: 20px; gap: 8px; direction: ltr">
        @for ($stamp = 1; $stamp <= 8; $stamp++)
            <span style="flex: 1; height: 8px; border-radius: 4px; background: {{ $stamp <= 5 ? '#F26522' : '#E1E7F1' }}"></span>
        @endfor
    </div>
</div>
<div class="toast" style="right: 0; bottom: 40px">
    <span class="ic solid"><x-landing.icon name="gift" class="i" /></span>{{ __('Reward') }}
</div>
