{{-- restaurant directory: places on the map with their menus and ratings --}}
<div class="abs" style="left: 150px; top: 0"><svg width="110" height="140" viewBox="0 0 24 30" aria-hidden="true"><path d="M12 29s10-9.5 10-17A10 10 0 0 0 2 12c0 7.5 10 17 10 17z" fill="#F26522"/><circle cx="12" cy="12" r="4.5" fill="#fff"/></svg></div>
@foreach ([['cup', '#FFE5D4'], ['plate', '#FEF3C7'], ['store', '#E1EBF5']] as [$icon, $tint])
    <div class="card" style="left: {{ $loop->index * 14 }}px; top: {{ 150 + $loop->index * 112 }}px; width: {{ 424 - $loop->index * 28 }}px; padding: 16px 18px">
        <div class="row" style="gap: 14px">
            <span class="thumb" style="width: 62px; height: 62px; border-radius: 16px; background: {{ $tint }}; display: grid; place-items: center; color: #F26522"><x-landing.icon :name="$icon" class="i" style="width: 32px; height: 32px" /></span>
            <div style="flex: 1">
                <div class="line" style="width: {{ 64 - $loop->index * 8 }}%"></div>
                <div style="margin-top: 8px; color: #F59E0B; font-size: 19px; letter-spacing: 2px; direction: ltr; text-align: start">★★★★★</div>
            </div>
            <span class="chip soft">{{ __('Menu') }}</span>
        </div>
    </div>
@endforeach
