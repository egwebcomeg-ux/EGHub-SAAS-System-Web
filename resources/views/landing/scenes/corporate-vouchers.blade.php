{{-- Delta Co. buys 300 vouchers of 200 → they are printed with serial, code and QR → Sara pays her lunch with 0042 → the report. --}}
<x-landing.sc-window :title="__('Corporate contracts')" class="top-6 start-6 h-[350px] w-[330px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="rounded-xl border border-navy-100 p-2.5">
            <div class="flex items-center justify-between"><b class="text-xs">{{ __('Delta Co.') }}</b><span data-on="1-" class="rounded-full bg-navy-100 px-2 py-0.5 font-bold text-navy-500 [&.is-on]:bg-emerald-100 [&.is-on]:text-emerald-800"><x-landing.swap :values="['1' => __('Draft'), '2-' => __('Active')]" /></span></div>
            <div class="mt-1.5 grid grid-cols-3 gap-1.5 text-center">
                <div class="rounded-lg bg-navy-50 p-1.5"><b class="block text-sm">300</b><span class="text-navy-500">{{ __('Vouchers') }}</span></div>
                <div class="rounded-lg bg-navy-50 p-1.5"><b class="block text-sm">200</b><span class="text-navy-500">{{ __('EGP each') }}</span></div>
                <div class="rounded-lg bg-navy-50 p-1.5"><b class="block text-sm">30/6</b><span class="text-navy-500">{{ __('Valid until') }}</span></div>
            </div>
            <div data-on="1" class="mt-2 rounded-lg bg-navy-100 py-1.5 text-center font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white"><x-landing.swap :values="['1' => __('Create the contract'), '2-' => __('Print the vouchers')]" /></div>
            <span data-show="1" class="sc-tap" style="top: 96px; inset-inline-end: 40px"></span>
        </div>
        {{-- 2-: the printed vouchers --}}
        <div data-show="2-" class="grid grid-cols-3 gap-1.5">
            @foreach (['0041', '0042', '0043'] as $i => $serial)
                <div @if ($serial === '0042') data-on="3-" @endif class="rounded-lg border border-dashed border-navy-300 p-1.5 text-center [&.is-on]:border-brand-500 [&.is-on]:bg-brand-50">
                    <b class="block text-[10px]" dir="ltr">#{{ $serial }}</b>
                    <div class="mx-auto my-1 grid size-7 grid-cols-3 gap-px"><i class="bg-navy-900"></i><i class="bg-navy-200"></i><i class="bg-navy-900"></i><i class="bg-navy-200"></i><i class="bg-navy-900"></i><i class="bg-navy-200"></i><i class="bg-navy-900"></i><i class="bg-navy-200"></i><i class="bg-navy-900"></i></div>
                    <span class="font-mono text-[9px] tracking-wider" dir="ltr">{{ ['7K2M', 'Q9TX', 'B4HR'][$i] }}</span>
                    <b class="block text-brand-700">200</b>
                </div>
            @endforeach
        </div>
        {{-- 4: the report --}}
        <div data-show="4" class="sc-pop rounded-xl bg-navy-900 p-2.5 text-white">
            <div class="flex items-center gap-1.5 font-bold"><x-landing.icon name="chart" class="size-4 text-brand-500" />{{ __('Vouchers report') }} · {{ __('Delta Co.') }}</div>
            <div class="mt-1.5 grid grid-cols-3 gap-1 text-center">
                <div><b class="block text-sm">212</b><span class="text-white/60">{{ __('Redeemed') }}</span></div>
                <div><b class="block text-sm">88</b><span class="text-white/60">{{ __('Left') }}</span></div>
                <div><b class="block text-sm">0</b><span class="text-white/60">{{ __('Expired') }}</span></div>
            </div>
        </div>
    </div>
</x-landing.sc-window>

{{-- 3-: the redeem screen at the counter --}}
<x-landing.sc-window :title="__('Redeem vouchers')" data-show="3-" class="sc-fade top-10 end-6 h-[300px] w-[300px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="flex gap-1.5"><div class="flex-1 rounded-lg border border-navy-200 px-2 py-1.5 font-mono font-bold" dir="ltr">Q9TX-0042</div><span data-on="3" class="rounded-lg bg-brand-600 px-2.5 py-1.5 font-bold text-white">{{ __('Check') }}</span></div>
        <div class="rounded-xl bg-navy-50 p-2.5">
            <div class="flex justify-between"><b>#0042 · {{ __('Delta Co.') }}</b><b class="text-brand-700">200 {{ __('EGP') }}</b></div>
            <div class="mt-0.5 text-navy-500">{{ __('Valid until') }} 30/6 · {{ __('Linked mobile') }} <span dir="ltr">0101 234 5678</span></div>
        </div>
        <div class="rounded-xl border border-navy-100 p-2.5">
            <div class="flex justify-between"><span>{{ __('Order') }} #37 · {{ __('Sara') }}</span><b>180 {{ __('EGP') }}</b></div>
            <div class="mt-0.5 text-navy-500">{{ __('Chicken bowl, lemon mint') }}</div>
        </div>
        <div data-on="3" class="rounded-lg bg-navy-100 py-1.5 text-center font-bold text-navy-600 [&.is-on]:bg-emerald-600 [&.is-on]:text-white"><x-landing.swap :values="['3' => __('Take the voucher'), '4' => __('Paid with voucher 0042') . ' ✓']" /></div>
        <span data-show="3" class="sc-tap" style="top: 172px; inset-inline-start: 120px"></span>
        <div data-show="4" class="sc-pop flex items-center gap-2 rounded-xl bg-navy-900 px-3 py-2 font-bold text-white"><x-landing.icon name="file" class="size-4 text-brand-500" />{{ __('Receipt printed · 20 EGP left on the voucher') }}</div>
    </div>
</x-landing.sc-window>
