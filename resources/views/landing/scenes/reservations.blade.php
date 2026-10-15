{{-- Mona picks Friday, 4 guests, a free time → books 8:30 with a note → one tap confirms → the board strip, seated. --}}
<x-landing.sc-phone class="top-6 start-10">
    <div class="bg-[#7a1f2b] px-3 pt-6 pb-3 text-white">
        <div class="text-sm font-extrabold">{{ __('Sofra') }}</div>
        <div class="text-[10px] text-white/75">{{ __('Book a table') }}</div>
    </div>
    <div class="space-y-2 p-2.5 text-[10px]">
        <div class="grid grid-cols-2 gap-1.5">
            <div class="rounded-lg border border-navy-200 bg-white px-2 py-1"><span class="block text-navy-500">{{ __('Day') }}</span><b>{{ __('Friday') }}</b></div>
            <div class="rounded-lg border border-navy-200 bg-white px-2 py-1"><span class="block text-navy-500">{{ __('Guests') }}</span><b>4</b></div>
        </div>
        <div>
            <b class="text-[10px]">{{ __('Choose the time') }}</b>
            <div class="mt-1 grid grid-cols-3 gap-1" dir="ltr">
                @foreach (['7:00', '7:30', '8:00', '8:30', '9:00', '9:30'] as $i => $t)
                    <span @if ($t === '8:30') data-on="2-" @endif @class(['rounded-md py-1 text-center font-bold ring-1', 'bg-white text-navy-300 ring-navy-100 line-through' => $i === 2, 'bg-white text-navy-700 ring-navy-200 [&.is-on]:bg-brand-600 [&.is-on]:text-white [&.is-on]:ring-brand-600' => $i !== 2])>{{ $t }}</span>
                @endforeach
            </div>
        </div>
        <div data-show="2-" class="rounded-lg border border-navy-200 bg-white px-2 py-1 text-navy-600">{{ __('Notes (optional)') }}: <b class="text-navy-900">{{ __('A birthday') }}</b></div>
        <div data-on="2" class="rounded-lg bg-navy-100 py-1.5 text-center text-[11px] font-bold text-navy-500 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Book the table') }}</div>
    </div>
    <span data-show="2" class="sc-tap" style="top: 268px; inset-inline-start: 88px"></span>
    <div data-show="3-" class="absolute inset-x-2 bottom-3 z-10 rounded-xl bg-white p-2 text-[10px] shadow-lg ring-1 ring-navy-100">
        <div class="flex items-center gap-1.5 font-extrabold">
            <span class="grid size-5 place-items-center rounded-full bg-emerald-600 text-white"><x-landing.icon name="check" class="size-3" /></span>
            <x-landing.swap :values="['3' => __('Booking sent'), '4-' => __('Booking confirmed')]" />
        </div>
        <div class="mt-1 text-navy-500">{{ __('Friday') }} · {{ __(':time pm', ['time' => '8:30']) }} · 4 {{ __('Guests') }}</div>
    </div>
</x-landing.sc-phone>

<x-landing.sc-window :title="__('Reservations')" class="top-10 end-6 h-[300px] w-[360px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="flex items-center justify-between"><b>{{ __('Friday') }}</b><span class="text-navy-500">{{ __(':n seats per time', ['n' => 24]) }}</span></div>
        <div class="rounded-xl border border-navy-100 p-2">
            <div class="flex justify-between"><b>{{ __('Karim') }}</b><span class="text-navy-500">{{ __(':time pm', ['time' => '7:30']) }} · 2</span></div>
            <div class="mt-1"><span class="rounded-full bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700">{{ __('Confirmed') }}</span></div>
        </div>
        <div data-show="2-" class="sc-pop relative rounded-xl border border-brand-300 bg-white p-2 shadow-sm">
            <div class="flex justify-between"><b>{{ __('Mona Ahmed') }}</b><span class="text-navy-500">{{ __(':time pm', ['time' => '8:30']) }} · 4</span></div>
            <div class="mt-0.5 text-navy-500">{{ __('A birthday') }}</div>
            <div class="mt-1.5 flex items-center gap-1.5">
                <span data-on="3-" class="rounded-full bg-amber-50 px-2 py-0.5 font-bold text-amber-800 [&.is-on]:bg-emerald-50 [&.is-on]:text-emerald-700"><x-landing.swap :values="['2' => __('Waiting for confirmation'), '3' => __('Confirmed'), '4-' => __('Seated')]" /></span>
                <span data-on="3" class="ms-auto rounded-lg border border-navy-200 px-2 py-0.5 font-bold [&.is-on]:border-emerald-600 [&.is-on]:bg-emerald-600 [&.is-on]:text-white">{{ __('Confirm') }}</span>
            </div>
            <span data-show="3" class="sc-tap" style="top: 78px; inset-inline-end: 36px"></span>
        </div>
    </div>
    {{-- 4: today's bookings on the order board --}}
    <div data-show="4" class="sc-slide absolute inset-x-0 bottom-0 border-t border-navy-100 bg-navy-900 px-3 py-2 text-[11px] text-white">
        <div class="flex items-center gap-2 font-extrabold"><x-landing.icon name="calendar" class="size-4 text-brand-500" />{{ __('Today’s bookings on the order board') }}</div>
        <div class="mt-1.5 flex gap-1.5">
            <span class="rounded-md bg-white/10 px-2 py-0.5">7:30 · {{ __('Karim') }} · 2</span>
            <span class="rounded-md bg-brand-600 px-2 py-0.5 font-bold">8:30 · {{ __('Mona') }} · 4 ✓</span>
        </div>
    </div>
</x-landing.sc-window>
