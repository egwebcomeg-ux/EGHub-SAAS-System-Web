{{-- featured listing: a paid first place, marked "featured", in the directory pages --}}
@foreach ([true, false, false] as $featured)
    <div class="card" style="left: {{ $featured ? 0 : 24 }}px; top: {{ 30 + $loop->index * 140 }}px; width: {{ $featured ? 424 : 376 }}px; padding: 18px; {{ $featured ? 'border: 4px solid #F26522' : 'opacity: .85' }}">
        <div class="row" style="gap: 14px">
            <span class="ic {{ $featured ? 'solid' : 'blue' }}" style="width: 64px; height: 64px"><x-landing.icon name="store" class="i" style="width: 32px; height: 32px" /></span>
            <div style="flex: 1">
                <div class="line" style="width: {{ $featured ? 60 : 50 }}%"></div>
                <div style="margin-top: 8px; color: #F59E0B; font-size: 20px; letter-spacing: 2px; direction: ltr; text-align: start">★★★★★</div>
            </div>
            @if ($featured)<span class="chip or"><x-landing.icon name="star" class="i" style="width: 18px; height: 18px" />{{ __('Featured') }}</span>@endif
        </div>
    </div>
@endforeach
