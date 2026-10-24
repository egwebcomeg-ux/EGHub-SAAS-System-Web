{{-- services: a QR table stand (as in the page's stand studio) and the other one-time jobs --}}
<div class="abs" style="left: 40px; top: 10px; width: 230px; height: 330px; border-radius: 22px 22px 8px 8px; background: #fff; box-shadow: 0 30px 70px rgba(0,0,0,.5); padding: 20px; text-align: center; color: #0A2540">
    <div class="t" style="font-size: 24px">{{ __('Your cafe') }}</div>
    <div style="margin-top: 12px">@include('share-cards.qr', ['data' => $pageUrl, 'size' => 170])</div>
    <div class="t" style="margin-top: 10px; font-size: 18px; color: #F26522">{{ __('Scan me') }}</div>
</div>
<div class="abs" style="left: 20px; top: 336px; width: 270px; height: 26px; border-radius: 6px; background: #0B1222; box-shadow: 0 16px 30px rgba(0,0,0,.4)"></div>
@foreach ([['printer', 300, 30], ['pencil', 300, 140], ['users', 300, 250], ['globe', 300, 360]] as [$icon, $left, $top])
    <div class="card" style="left: {{ $left }}px; top: {{ $top }}px; width: 96px; height: 96px; display: grid; place-items: center; border-radius: 24px">
        <span class="ic big"><x-landing.icon :name="$icon" class="i" /></span>
    </div>
@endforeach
