{{-- pricing: the plans side by side, the free one first (no prices drawn: they live in the database) --}}
@foreach ([[0, 70, -5, false], [212, 40, 4, true]] as [$left, $top, $angle, $highlight])
    <div class="card" style="left: {{ $left }}px; top: {{ $top }}px; width: 212px; height: 380px; padding: 22px; transform: rotate({{ $angle }}deg); {{ $highlight ? 'border: 4px solid #F26522' : '' }}">
        @if ($loop->first)
            <span class="chip green">{{ __('Free') }}</span>
        @else
            <span class="ic solid" style="width: 34px; height: 34px"><x-landing.icon name="star" class="i" style="width: 20px; height: 20px" /></span>
        @endif
        <div class="line" style="margin-top: 18px; width: 60%; height: 14px; border-radius: 7px"></div>
        @for ($feature = 0; $feature < ($loop->first ? 3 : 6); $feature++)
            <div class="row" style="margin-top: 14px; gap: 10px">
                <span style="color: #16A34A"><x-landing.icon name="check" class="i" style="width: 22px; height: 22px" /></span>
                <span class="line soft" style="flex: 1; height: 9px"></span>
            </div>
        @endfor
        <div style="position: absolute; inset-inline: 22px; bottom: 22px; height: 44px; border-radius: 14px; background: {{ $highlight ? '#F26522' : '#F0F6FC' }}"></div>
    </div>
@endforeach
