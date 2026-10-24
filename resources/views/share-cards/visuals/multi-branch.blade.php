{{-- branches: one menu for every branch, each with its own prices --}}
<svg class="abs" style="left: 0; top: 0" width="424" height="506" viewBox="0 0 424 506" aria-hidden="true">
    <g stroke="#FF854D" stroke-opacity=".7" stroke-width="4" stroke-dasharray="10 9" fill="none"><path d="M212 250 L80 90"/><path d="M212 250 L344 90"/><path d="M212 250 L212 420"/></g>
</svg>
<div class="abs" style="left: 152px; top: 190px; width: 120px; height: 120px; border-radius: 30px; background: #F26522; display: grid; place-items: center; box-shadow: 0 20px 50px rgba(0,0,0,.4)">
    <x-landing.icon name="menu" class="i" style="width: 60px; height: 60px; color: #fff" />
</div>
@foreach ([[10, 30], [274, 30], [137, 380]] as [$left, $top])
    <div class="card" style="left: {{ $left }}px; top: {{ $top }}px; width: 150px; padding: 14px; text-align: center">
        <span class="ic blue" style="margin: 0 auto; width: 54px; height: 54px"><x-landing.icon name="store" class="i" /></span>
        <div class="line" style="margin: 10px auto 0; width: 70%; height: 9px"></div>
        <div class="line or" style="margin: 8px auto 0; width: 40%; height: 9px"></div>
    </div>
@endforeach
<div class="toast" style="right: -6px; top: 240px; padding: 10px 16px; font-size: 21px">{{ __('Branches') }}</div>
