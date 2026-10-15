{{-- Pick "not visited for 30 days" → write the message with coupon BACK20 → schedule Thursday 6 pm → sent, coupon used. --}}
<x-landing.sc-window :title="__('WhatsApp campaigns')" class="top-4 start-5 h-[372px] w-[380px]">
    <div class="space-y-2 p-2.5 text-[11px]">
        <div>
            <b>{{ __('Who receives it') }}</b>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                <span data-on="1-" class="rounded-full border border-navy-200 px-2.5 py-1 font-bold [&.is-on]:border-brand-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Not visited for 30 days') }}</span>
                <span class="rounded-full border border-navy-200 px-2.5 py-1 font-bold text-navy-500">{{ __('Regulars') }}</span>
                <span class="rounded-full border border-navy-200 px-2.5 py-1 font-bold text-navy-500">{{ __('Birthdays this month') }}</span>
            </div>
            <div data-show="1-" class="mt-1.5 text-navy-500">{{ __(':n guests match', ['n' => 41]) }}</div>
        </div>
        <div class="rounded-xl border border-navy-200 p-2">
            <b>{{ __('Message') }}</b>
            <p class="mt-1 min-h-[34px] leading-relaxed text-navy-700"><span data-show="2-" class="sc-fade">{{ __('We miss you at Rosto, {name}! 20% off your next visit with the code BACK20.') }}</span></p>
            <div data-show="2-" class="mt-1 inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 font-bold text-emerald-800 ring-1 ring-emerald-200"><x-landing.icon name="tag" class="size-3.5" />BACK20 · 20% · {{ __('14 days') }}</div>
        </div>
        <div class="grid grid-cols-[1fr_auto] items-center gap-2">
            <div class="rounded-lg border border-navy-200 px-2 py-1.5"><span class="text-navy-500">{{ __('Send') }}:</span> <b><x-landing.swap :values="['1-2' => __('Now'), '3-' => __('Thursday, 6:00 pm')]" /></b></div>
            <span data-on="3" class="rounded-lg bg-navy-100 px-3 py-1.5 font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Schedule') }}</span>
        </div>
        <span data-show="3" class="sc-tap" style="top: 224px; inset-inline-end: 36px"></span>
        <div data-show="3-" class="rounded-xl bg-navy-50 p-2">
            <div class="flex items-center justify-between"><b>{{ __('We miss you') }}</b><span data-on="4" class="rounded-full bg-amber-50 px-2 py-0.5 font-bold text-amber-800 [&.is-on]:bg-emerald-50 [&.is-on]:text-emerald-700"><x-landing.swap :values="['3' => __('Scheduled'), '4-' => __('Done')]" /></span></div>
            <div class="mt-1 flex gap-3 text-navy-600">
                <span>{{ __('Sent') }} <b><x-landing.swap :values="['3' => '0', '4-' => '39']" /></b></span>
                <span>{{ __('Failed') }} <b>0</b></span>
                <span>{{ __('Skipped') }} <b><x-landing.swap :values="['3' => '0', '4-' => '2']" /></b></span>
            </div>
        </div>
    </div>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-6">
    <div class="flex items-center gap-2 bg-[#075e54] px-3 pt-5 pb-2 text-white">
        <span class="grid size-6 place-items-center rounded-full bg-[#4a3426] text-[10px] font-black">R</span>
        <div class="text-[11px] font-extrabold">{{ __('Rosto') }}</div>
    </div>
    <div class="h-full space-y-2 bg-[#ece5dd] p-2 text-[10px] leading-snug">
        <div data-show="4" class="sc-pop rounded-xl rounded-ss-sm bg-white p-2 shadow-sm">
            {{ __('We miss you at Rosto, Mona! 20% off your next visit with the code BACK20.') }}
            <span class="block text-end text-[9px] text-navy-400" dir="ltr">18:00</span>
        </div>
        <div data-show="4" style="transition-delay: 1200ms" class="sc-pop flex items-center gap-1.5 rounded-xl bg-emerald-600 p-2 font-bold text-white">
            <x-landing.icon name="check" class="size-3.5" />{{ __('BACK20 used at the counter') }}
        </div>
    </div>
</x-landing.sc-phone>
