{{-- Mona buys a 10-coffee package → the cashier takes one use → her 5th visit makes her Gold (10% off) → 5% cashback lands in her balance. --}}
<x-landing.sc-window :title="__('Loyalty card') . ' · ' . __('Counter')" class="top-6 start-5 h-[350px] w-[370px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="flex items-center justify-between rounded-xl border border-navy-100 p-2.5">
            <div>
                <b class="text-xs">{{ __('Mona Ahmed') }}</b>
                <span data-show="3-" class="sc-pop ms-1 rounded-full bg-[#C9A227] px-1.5 py-0.5 text-[9px] font-bold text-white">★ {{ __('Gold') }} · 10%</span>
                <div class="text-navy-500"><x-landing.swap :values="['1-2' => '4', '3-' => '5']" /> {{ __('visits') }} · {{ __('Balance') }} <b data-on="4" class="sc-flash tabular-nums"><x-landing.swap :values="['1-3' => '0', '4' => '6.50']" /></b> {{ __('EGP') }}</div>
            </div>
            <div class="text-end"><div class="text-navy-500">{{ __('Stamps') }}</div><b class="text-xl font-black text-brand-700">7<span class="text-xs text-navy-400">/8</span></b></div>
        </div>
        <div class="flex gap-1.5">
            <div class="flex-1 rounded-lg border border-navy-200 px-2 py-1.5 font-bold">{{ __('10 coffees') }} · 150 {{ __('EGP') }} · 30 {{ __('days') }}</div>
            <div data-on="1" class="rounded-lg bg-navy-100 px-3 py-1.5 font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Sell') }}</div>
        </div>
        <span data-show="1" class="sc-tap" style="top: 92px; inset-inline-end: 24px"></span>
        <div data-show="1-" class="sc-fade flex items-center justify-between rounded-xl bg-amber-50 p-2.5">
            <div><b class="text-amber-900">🎟 {{ __('10 coffees') }}</b><div class="text-amber-800" dir="ltr"><x-landing.swap :values="['1' => '10/10', '2-' => '9/10']" /> · 14/11</div></div>
            <div data-on="2" class="rounded-lg bg-navy-100 px-3 py-1.5 font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Use one') }}</div>
        </div>
        <span data-show="2" class="sc-tap" style="top: 142px; inset-inline-end: 30px"></span>
        <div data-show="3" class="sc-pop flex items-center gap-2 rounded-xl bg-navy-900 px-3 py-2 font-bold text-white"><x-landing.icon name="star" class="size-4 text-[#C9A227]" />{{ __('5th visit: Mona is now Gold, 10% off every order') }}</div>
        <div data-show="4" class="sc-pop flex items-center gap-2 rounded-xl bg-emerald-600 px-3 py-2 font-bold text-white"><x-landing.icon name="wallet" class="size-4" />{{ __('Order #92 · 130 EGP → 6.50 EGP cashback added to the balance') }}</div>
    </div>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-6">
    <div class="sc-fade absolute inset-0 bg-navy-50 p-2 text-[10px]">
        <div class="mt-3 overflow-hidden rounded-2xl bg-[#b5451b] p-3 text-white">
            <div class="flex items-center justify-between text-[11px] font-bold"><span>{{ __('Rosto') }}</span><span data-show="3-" class="sc-pop rounded-full bg-[#C9A227] px-1.5 py-0.5 text-[9px]">★ {{ __('Gold') }}</span></div>
            <div class="mt-1 text-white/75">{{ __('Mona Ahmed') }}</div>
            <div class="mt-2 flex gap-1">@for ($i = 0; $i < 8; $i++)<span class="size-3 rounded-full {{ $i < 7 ? 'bg-white' : 'bg-white/30' }}"></span>@endfor</div>
        </div>
        <div data-show="1-" class="sc-fade mt-2 rounded-xl bg-amber-50 p-2 ring-1 ring-amber-200">
            <div class="flex items-center justify-between font-bold text-amber-900"><span>🎟 {{ __('10 coffees') }}</span><b class="text-base" dir="ltr"><x-landing.swap :values="['1' => '10', '2-' => '9']" /><span class="text-[9px] text-amber-700">/10</span></b></div>
            <div class="mt-1.5 flex gap-0.5">@for ($i = 0; $i < 10; $i++)<span class="h-1.5 flex-1 rounded-full {{ $i === 0 ? 'bg-amber-300' : 'bg-amber-800' }}"></span>@endfor</div>
        </div>
        <div data-show="3-" class="sc-fade mt-2 rounded-xl bg-white p-2 ring-1 ring-navy-100">
            <div class="flex items-center gap-2"><span class="grid size-7 place-items-center rounded-lg bg-[#C9A227] font-black text-white">★</span><div><div class="text-navy-500">{{ __('Your membership') }}</div><b class="text-[#C9A227]">{{ __('Gold') }}</b> · 10%</div></div>
        </div>
        <div data-show="4" class="sc-pop mt-2 flex items-center justify-between rounded-xl bg-white p-2 ring-1 ring-navy-100">
            <div><div class="text-navy-500">{{ __('My balance') }}</div><b class="text-base tabular-nums">6.50 <span class="text-[9px]">{{ __('EGP') }}</span></b></div>
            <span class="rounded-lg bg-emerald-50 px-2 py-1 font-bold text-emerald-700">{{ __('Cashback') }} +6.50</span>
        </div>
    </div>
</x-landing.sc-phone>
