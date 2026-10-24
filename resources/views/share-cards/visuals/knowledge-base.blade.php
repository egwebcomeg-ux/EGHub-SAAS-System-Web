{{-- knowledge base: the answers the team gives every day, found in a second --}}
<div class="card" style="left: 10px; top: 30px; width: 404px; padding: 22px">
    <div class="row" style="gap: 10px; padding: 12px 16px; border-radius: 14px; background: #F0F6FC; color: #6B87A3">
        <x-landing.icon name="book" class="i" style="width: 26px; height: 26px" /><span class="t" style="font-size: 20px">{{ __('Search') }}…</span>
    </div>
    @foreach ([true, false] as $open)
        <div style="margin-top: 16px; padding: 16px; border-radius: 16px; border: 2px solid {{ $open ? '#F26522' : '#E1E7F1' }}">
            <div class="row" style="gap: 10px"><span class="chip or">{{ __('Question') }}</span><span class="line" style="flex: 1"></span></div>
            @if ($open)
                <div class="row" style="margin-top: 14px; gap: 10px; align-items: flex-start"><span class="chip green">{{ __('Answer') }}</span>
                    <div style="flex: 1"><div class="line soft"></div><div class="line soft" style="margin-top: 9px; width: 75%"></div><div class="line soft" style="margin-top: 9px; width: 55%"></div></div>
                </div>
            @endif
        </div>
    @endforeach
</div>
<div class="toast" style="right: 0; bottom: 30px">
    <span class="ic blue"><x-landing.icon name="phone" class="i" /></span>{{ __('Call center') }}
</div>
