{{-- Mona tops up 500 → pays 170 from the balance → her friend scans a 300 gift card → it lands in the friend's balance. --}}
<x-landing.sc-window :title="__('Wallet and gift cards')" class="top-6 start-5 h-[350px] w-[370px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="flex gap-1.5">
            <div class="flex-1 rounded-lg border border-navy-200 px-2 py-1.5 font-bold" dir="ltr">0101 234 5678</div>
            <div class="rounded-lg border border-navy-200 px-2 py-1.5 font-bold">500 {{ __('EGP') }}</div>
            <div data-on="1" class="rounded-lg bg-navy-100 px-3 py-1.5 font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Top up') }}</div>
        </div>
        <span data-show="1" class="sc-tap" style="top: 26px; inset-inline-end: 30px"></span>
        <div class="flex items-center justify-between rounded-xl border border-navy-100 p-2.5">
            <div><b class="text-xs">{{ __('Mona Ahmed') }}</b><div class="text-navy-500">{{ __('Balance') }}</div></div>
            <b data-on="1-2" class="sc-flash rounded-lg px-2 text-xl font-black tabular-nums"><x-landing.swap :values="['1' => '500', '2-' => '330']" /> <span class="text-xs font-bold text-navy-500">{{ __('EGP') }}</span></b>
        </div>
        <div class="space-y-1.5">
            <div data-show="1-" class="flex justify-between rounded-lg bg-emerald-50 px-2 py-1.5"><span>{{ __('Top-up at the counter') }} · {{ __('Ahmed') }}</span><b class="text-emerald-700" dir="ltr">+500</b></div>
            <div data-show="2-" class="flex justify-between rounded-lg bg-navy-50 px-2 py-1.5"><span>{{ __('Order') }} #81 · {{ __('Latte') }}, {{ __('Cheesecake') }}</span><b dir="ltr">−170</b></div>
            <div data-show="4" class="flex justify-between rounded-lg bg-brand-50 px-2 py-1.5"><span>{{ __('Gift card redeemed') }} · <span dir="ltr">0122 …</span></span><b class="text-brand-700" dir="ltr">300</b></div>
        </div>
        <div data-show="2" class="sc-pop flex items-center gap-2 rounded-xl bg-navy-900 px-3 py-2 font-bold text-white"><x-landing.icon name="wallet" class="size-4 text-brand-500" />{{ __('Paid from the balance: 170 EGP') }}</div>
    </div>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-6">
    {{-- 1-2: Mona's card with her balance --}}
    <div data-show="1-2" class="sc-fade absolute inset-0 bg-navy-50 p-2">
        <div class="mt-4 overflow-hidden rounded-2xl bg-[#b5451b] p-3 text-white">
            <div class="text-[11px] font-bold">{{ __('Rosto') }}</div>
            <div class="mt-1 text-[10px] text-white/75">{{ __('Mona Ahmed') }}</div>
            <div class="mt-3 text-[10px] text-white/75">{{ __('Balance') }}</div>
            <div class="text-2xl font-black tabular-nums"><x-landing.swap :values="['1' => '500', '2-' => '330']" /> <span class="text-xs">{{ __('EGP') }}</span></div>
        </div>
    </div>
    {{-- 3-4: a friend's phone on the gift card page --}}
    <div data-show="3-" class="sc-fade absolute inset-0 bg-[#fbf7f1] p-2.5 text-[10px]">
        <div class="mt-4 rounded-2xl bg-gradient-to-br from-[#4a3426] to-[#b5451b] p-3 text-white">
            <div class="flex items-center justify-between font-bold"><span>{{ __('Gift card') }}</span><x-landing.icon name="gift" class="size-4" /></div>
            <div class="mt-3 text-2xl font-black">300 <span class="text-xs">{{ __('EGP') }}</span></div>
            <div class="mt-1 font-mono text-[10px] tracking-wider text-white/80" dir="ltr">XJZB-KQNY-UQTC</div>
        </div>
        <div class="mt-2 rounded-lg border border-navy-200 bg-white px-2 py-1.5"><span class="text-navy-500">{{ __('Mobile number') }}</span> <b dir="ltr">0122 345 6789</b></div>
        <div data-on="3" class="mt-2 rounded-lg bg-navy-100 py-1.5 text-center text-[11px] font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Add to my balance') }}</div>
        <span data-show="3" class="sc-tap" style="top: 196px; inset-inline-start: 82px"></span>
        <div data-show="4" class="sc-pop mt-2 flex items-center gap-1.5 rounded-xl bg-emerald-600 p-2 font-bold text-white"><x-landing.icon name="check" class="size-3.5" />{{ __('300 EGP added to your balance') }}</div>
    </div>
</x-landing.sc-phone>
