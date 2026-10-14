{{-- Tap items → pay cash with change → the number shows on the customer display → the shift closes even. --}}
<x-landing.sc-window :title="__('POS cashier')" class="top-6 start-6 h-[300px] w-[420px]">
    <div class="flex h-full text-[11px]">
        <div class="grid flex-1 grid-cols-3 content-start gap-1.5 p-2">
            @foreach ([[__('Espresso'), 45, 0], [__('Latte'), 65, 0], [__('Mocha'), 75, 0], [__('Cheesecake'), 90, 1], [__('Butter croissant'), 45, 2], [__('Cookies'), 35, 3]] as [$item, $price, $order])
                <div @if ($order) data-on="1" style="transition-delay: {{ $order * 450 }}ms" @endif class="rounded-lg border border-navy-100 bg-white p-1.5 [&.is-on]:border-brand-500 [&.is-on]:bg-brand-50">
                    <b class="block truncate">{{ $item }}</b><span class="font-bold text-brand-700">{{ $price }}</span>
                </div>
            @endforeach
        </div>
        <div class="flex w-[150px] flex-col border-s border-navy-100 p-2">
            <div class="grid grid-cols-2 rounded-md bg-navy-50 p-0.5 text-center font-bold"><span class="rounded bg-navy-900 py-0.5 text-white">{{ __('Takeaway') }}</span><span class="py-0.5">{{ __('Dine-in') }}</span></div>
            <div class="mt-2 space-y-1">
                <div data-show="1-" style="transition-delay: 450ms" class="flex justify-between"><span>1× {{ __('Cheesecake') }}</span><b>90</b></div>
                <div data-show="1-" style="transition-delay: 900ms" class="flex justify-between"><span>1× {{ __('Butter croissant') }}</span><b>45</b></div>
                <div data-show="1-" style="transition-delay: 1350ms" class="flex justify-between"><span>2× {{ __('Cookies') }}</span><b>70</b></div>
            </div>
            <div class="mt-auto flex justify-between border-t border-navy-100 pt-1.5 font-extrabold"><span>{{ __('Total') }}</span><span>205</span></div>
            <div data-on="2" class="mt-1.5 rounded-md bg-brand-600 py-1.5 text-center font-bold text-white [&.is-on]:bg-brand-700">{{ __('Pay') }}</div>
        </div>
    </div>
    {{-- 2: payment --}}
    <div data-show="2" class="sc-fade absolute inset-0 grid place-items-center bg-navy-900/40">
        <div class="sc-pop w-[220px] rounded-xl bg-white p-3 text-[11px] shadow-xl">
            <b class="text-xs">{{ __('Cash') }}</b>
            <div class="mt-2 flex justify-between"><span>{{ __('Received') }}</span><b>300</b></div>
            <div class="mt-1 flex justify-between text-sm font-black text-emerald-700"><span>{{ __('Change') }}</span><span>95 {{ __('EGP') }}</span></div>
            <div class="mt-2 rounded-md bg-emerald-600 py-1.5 text-center font-bold text-white">{{ __('Done') }}</div>
        </div>
    </div>
</x-landing.sc-window>

{{-- customer display --}}
<div class="absolute end-6 bottom-8 w-[170px] rounded-xl bg-[#0d1322] p-1.5 shadow-xl">
    <div class="rounded bg-[#141f38] p-2 text-white">
        <div class="text-[9px] font-bold text-white/50">{{ __('Customer display') }}</div>
        <div class="mt-1 text-[10px] text-white/70">{{ __('Ready') }}</div>
        <div class="mt-0.5 flex gap-1 text-sm font-black"><span class="rounded bg-white/10 px-1.5">58</span><span class="rounded bg-white/10 px-1.5">60</span><span data-show="3-" class="sc-pop rounded bg-emerald-500 px-1.5">61</span></div>
    </div>
</div>
{{-- shift --}}
<div data-show="4" class="sc-pop absolute top-10 end-6 w-[170px] rounded-xl border border-navy-100 bg-white p-3 text-[11px] shadow-xl">
    <b class="text-xs">{{ __('Close shift') }}</b>
    <div class="mt-2 flex justify-between"><span class="text-navy-500">{{ __('Expected') }}</span><b>4,215</b></div>
    <div class="mt-1 flex justify-between"><span class="text-navy-500">{{ __('Counted') }}</span><b>4,215</b></div>
    <div class="mt-2 rounded-md bg-emerald-50 py-1 text-center font-bold text-emerald-700">{{ __('No difference') }} ✓</div>
</div>
