{{-- terms, privacy, refund: a plain document with the card's icon --}}
<div class="card" style="left: 80px; top: 20px; width: 300px; height: 400px; border-radius: 14px; padding: 34px 30px; transform: rotate(3deg)">
    <div class="line" style="width: 60%; height: 16px; border-radius: 8px"></div>
    @foreach ([92, 80, 88, 60, 0, 90, 84, 70, 0, 86, 50] as $width)
        @if ($width === 0)
            <div style="height: 14px"></div>
        @else
            <div class="line soft" style="margin-top: 14px; width: {{ $width }}%"></div>
        @endif
    @endforeach
</div>
<div class="abs" style="left: 20px; top: 330px"><span class="ic solid" style="width: 110px; height: 110px; border: 6px solid #061A2E"><x-landing.icon :name="$card['icon']" class="i" style="width: 54px; height: 54px" /></span></div>
