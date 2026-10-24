{{-- blog: a pile of short articles --}}
@foreach ([[70, 10, 5, '#FEF3C7', 'cup'], [30, 90, -3, '#E1EBF5', 'chart'], [0, 180, 0, '#FFE5D4', 'stamp']] as [$left, $top, $angle, $tint, $icon])
    <div class="card" style="left: {{ $left }}px; top: {{ $top }}px; width: 380px; padding: 18px; transform: rotate({{ $angle }}deg)">
        <div style="height: {{ $loop->last ? 150 : 60 }}px; border-radius: 16px; background: {{ $tint }}; display: grid; place-items: center; color: #F26522">
            @if ($loop->last)<x-landing.icon :name="$icon" class="i" style="width: 70px; height: 70px" />@endif
        </div>
        @if ($loop->last)
            <div class="line" style="margin-top: 18px; width: 85%; height: 14px; border-radius: 7px"></div>
            <div class="line" style="margin-top: 10px; width: 60%; height: 14px; border-radius: 7px"></div>
            <div class="line soft" style="margin-top: 16px; width: 92%"></div>
            <div class="line soft" style="margin-top: 9px; width: 70%"></div>
        @endif
    </div>
@endforeach
<div class="toast" style="right: 0; bottom: 0">
    <span class="ic"><x-landing.icon name="book" class="i" /></span>{{ __('Blog') }}
</div>
