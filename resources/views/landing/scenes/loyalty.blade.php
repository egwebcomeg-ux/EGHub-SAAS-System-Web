{{-- Join with a mobile number → the cashier stamps → 20% off unlocks at 4 → the 8th stamp is a free coffee. --}}
<x-landing.sc-phone class="top-6 start-12">
    <div class="h-full bg-navy-50 p-2">
        <div class="overflow-hidden rounded-2xl bg-[#b5451b] text-white">
            <div class="px-3 pt-4 pb-3">
                <div class="flex items-center justify-between text-[11px] font-bold"><span>{{ __('Rosto') }}</span><span class="grid size-6 place-items-center rounded-full bg-white/20">R</span></div>
                <div class="mt-2 text-base font-black">{{ __('Coffee card') }}</div>
                <div class="text-[10px] text-white/75">{{ __('Mona Ahmed') }}</div>
                <div class="mt-1 text-lg font-black"><x-landing.swap :values="['1' => '3', '2-3' => '4', '4-' => '8']" /> <span class="text-xs text-white/70">/ 8</span></div>
            </div>
            <div class="grid grid-cols-4 gap-1.5 bg-[#7a1f2b] p-2.5">
                @for ($i = 1; $i <= 8; $i++)
                    @php
                        $when = $i <= 3 ? null : ($i === 4 ? '2-' : '4-');
                    @endphp
                    <span @if ($when) data-on="{{ $when }}" style="transition-delay: {{ $i > 4 ? ($i - 5) * 150 : 0 }}ms" @endif
                          @class(['grid aspect-square place-items-center rounded-full', 'bg-white text-[#b5451b]' => $i <= 3, 'bg-white/15 text-white/50 [&.is-on]:bg-white [&.is-on]:text-[#b5451b]' => $i > 3])>
                        <x-landing.icon :name="in_array($i, [4, 8], true) ? 'gift' : 'cup'" class="size-4" />
                    </span>
                @endfor
            </div>
        </div>
        <div data-show="3" class="sc-pop mt-2 flex items-center gap-2 rounded-xl bg-emerald-50 p-2 text-[11px] font-bold text-emerald-800 ring-1 ring-emerald-200"><x-landing.icon name="gift" class="size-4" />{{ __('20% off your order is ready') }}</div>
        <div data-show="4-" class="sc-pop mt-2 flex items-center gap-2 rounded-xl bg-brand-600 p-2 text-[11px] font-bold text-white"><x-landing.icon name="cup" class="size-4" />{{ __('A free coffee is ready') }}</div>
    </div>
</x-landing.sc-phone>

<x-landing.sc-window :title="__('Loyalty card · cashier')" class="top-12 end-8 h-[300px] w-[320px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="flex gap-1.5">
            <div class="flex-1 rounded-lg border border-navy-200 px-2 py-1.5 font-bold" dir="ltr">0101 234 5678</div>
            <div class="rounded-lg bg-brand-600 px-3 py-1.5 font-bold text-white">{{ __('Search') }}</div>
        </div>
        <div data-show="1-" class="rounded-xl border border-navy-100 p-2">
            <div class="flex justify-between"><b class="text-xs">{{ __('Mona Ahmed') }}</b><b class="text-brand-700"><x-landing.swap :values="['1' => '3', '2-3' => '4', '4-' => '8']" />/8</b></div>
            <div class="mt-2 flex gap-1.5">
                <span class="rounded-lg border border-navy-200 px-3 py-1.5 font-bold">1</span>
                <span data-on="2" class="flex-1 rounded-lg bg-navy-100 py-1.5 text-center font-bold text-navy-700 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Add a stamp') }}</span>
            </div>
            <span data-show="2" class="sc-tap" style="top: 92px; inset-inline-end: 80px"></span>
        </div>
        <div data-show="3-" class="rounded-xl bg-emerald-50 p-2 ring-1 ring-emerald-200">
            <b class="text-emerald-800">{{ __('Rewards ready') }}</b>
            <div class="mt-1 flex items-center justify-between"><span><x-landing.swap :values="['3' => __('20% off your order'), '4-' => __('A free coffee')]" /></span>
                <span data-on="4" class="rounded-md bg-white px-2 py-0.5 font-bold text-emerald-700 ring-1 ring-emerald-300 [&.is-on]:bg-emerald-600 [&.is-on]:text-white">{{ __('Redeem') }}</span></div>
        </div>
        <div data-show="2-" class="rounded-lg bg-navy-50 px-2 py-1 text-navy-500">{{ __('Stamp added by Ahmed · 10:42') }}</div>
    </div>
</x-landing.sc-window>
