{{-- reports: the day's sales, best sellers and busy hours --}}
<div class="card" style="left: 10px; top: 30px; width: 404px; padding: 22px">
    <div class="row" style="justify-content: space-between">
        <span class="row t" style="gap: 10px; font-size: 22px"><span class="ic"><x-landing.icon name="chart" class="i" /></span>{{ __('Sales') }}</span>
        <span class="chip soft">{{ __('Today') }}</span>
    </div>
    <div class="row" style="margin-top: 22px; height: 170px; align-items: flex-end; gap: 12px; direction: ltr">
        @foreach ([38, 55, 46, 72, 64, 92, 80] as $bar)
            <span style="flex: 1; height: {{ $bar }}%; border-radius: 8px 8px 3px 3px; background: {{ $loop->index === 5 ? '#F26522' : '#C3D5E8' }}"></span>
        @endforeach
    </div>
    <div style="margin-top: 10px; height: 3px; border-radius: 2px; background: #E1E7F1"></div>
</div>
<div class="toast" style="right: 0; bottom: 60px">
    <span class="ic blue"><x-landing.icon name="clock" class="i" /></span>{{ __('Busy hours') }}
</div>
