{{-- corporate vouchers: a company pays ahead, its staff redeem vouchers at the counter --}}
@foreach ([[60, 20, -6, 0.55], [40, 110, -2, 0.8], [20, 200, 2, 1]] as [$left, $top, $angle, $opacity])
    <div class="card" style="left: {{ $left }}px; top: {{ $top }}px; width: 360px; height: 180px; display: flex; overflow: hidden; transform: rotate({{ $angle }}deg); opacity: {{ $opacity }}">
        <div style="width: 110px; background: #F26522; color: #fff; display: grid; place-items: center"><x-landing.icon name="store" class="i" style="width: 54px; height: 54px" /></div>
        <div style="flex: 1; padding: 22px; border-inline-start: 4px dashed #fff">
            <div class="t" style="font-size: 26px">{{ __('Voucher') }}</div>
            <div class="line" style="margin-top: 12px; width: 70%"></div>
            <div class="line soft" style="margin-top: 10px; width: 50%"></div>
            @if ($loop->last)
                <div style="position: absolute; bottom: 16px; inset-inline-end: 16px">@include('share-cards.qr', ['data' => $pageUrl, 'size' => 70])</div>
            @endif
        </div>
    </div>
@endforeach
<div class="toast" style="left: 0; bottom: 30px">
    <span class="ic green"><x-landing.icon name="check" class="i" /></span>{{ __('Voucher') }} ✓
</div>
