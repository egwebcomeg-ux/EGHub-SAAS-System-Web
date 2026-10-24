{{-- table reservations: a day, a time and the number of guests, then a confirmation --}}
<div class="card" style="left: 30px; top: 10px; width: 364px; padding: 22px">
    <div class="row" style="justify-content: space-between">
        <span class="row t" style="gap: 10px; font-size: 22px"><span class="ic"><x-landing.icon name="calendar" class="i" /></span>{{ __('Book a table') }}</span>
    </div>
    <div style="margin-top: 16px; display: grid; grid-template-columns: repeat(7, 1fr); gap: 7px; direction: ltr">
        @for ($day = 1; $day <= 28; $day++)
            <span @style(['height: 34px', 'border-radius: 10px', 'display: grid', 'place-items: center', 'font-weight: 800', 'font-size: 15px',
                'background: #F26522; color: #fff' => $day === 17, 'background: #F0F6FC; color: #3E5F80' => $day !== 17])>{{ $day }}</span>
        @endfor
    </div>
    <div class="row" style="margin-top: 16px; gap: 8px; direction: ltr; justify-content: center">
        <span class="chip soft">7:30</span><span class="chip or">8:00</span><span class="chip soft">8:30</span>
    </div>
    <div class="row" style="margin-top: 14px; justify-content: center; gap: 10px">
        <span class="ic blue"><x-landing.icon name="users" class="i" /></span><span class="t" style="font-size: 20px">{{ __('Guests') }}: 4</span>
    </div>
</div>
<div class="toast" style="left: 10px; bottom: 26px">
    <span class="ic green"><x-landing.icon name="check" class="i" /></span>{{ __('Reservation confirmed') }}
</div>
