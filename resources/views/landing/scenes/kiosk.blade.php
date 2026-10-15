{{-- Start on the tablet → pick a latte and a cheesecake → name and order number 74 → it is on the board, pay at the cashier. --}}
<div class="absolute top-8 start-5 h-[300px] w-[410px] rounded-[22px] bg-[#10182b] p-[9px] shadow-[0_24px_40px_-24px_rgba(20,31,56,.7)]">
    <div class="relative h-full overflow-hidden rounded-[14px] bg-[#fbf7f1] text-navy-900">
        {{-- 1: welcome --}}
        <div data-show="1" class="sc-fade absolute inset-0 z-10 grid place-items-center bg-[#4a3426] text-white">
            <div class="text-center">
                <div class="mx-auto grid size-12 place-items-center rounded-2xl bg-white/15 text-xl font-black">R</div>
                <div class="mt-2 text-lg font-black">{{ __('Rosto') }}</div>
                <div class="text-[11px] text-white/70">{{ __('Order here and pay at the cashier') }}</div>
                <div class="mt-4 inline-block rounded-xl bg-brand-600 px-5 py-2 text-xs font-extrabold">{{ __('Start your order') }}</div>
                <div class="mt-3 flex justify-center gap-2 text-[10px] font-bold">
                    <span class="rounded-full bg-white/15 px-2.5 py-1">{{ __('Eat here') }}</span>
                    <span class="rounded-full bg-white/15 px-2.5 py-1">{{ __('Takeaway') }}</span>
                </div>
            </div>
            <span data-show="1" class="sc-tap" style="top: 178px; left: 196px"></span>
        </div>

        {{-- 2: the menu, big, with the order on the side --}}
        <div class="grid h-full grid-cols-[1fr_118px]">
            <div class="p-2.5">
                <div class="flex gap-1 text-[10px] font-bold">
                    <span class="rounded-full bg-brand-600 px-2 py-0.5 text-white">{{ __('Hot coffee') }}</span>
                    <span class="rounded-full bg-white px-2 py-0.5 text-navy-600 ring-1 ring-navy-100">{{ __('Desserts') }}</span>
                    <span class="rounded-full bg-white px-2 py-0.5 text-navy-600 ring-1 ring-navy-100">{{ __('Cold drinks') }}</span>
                </div>
                <div class="mt-2 grid grid-cols-3 gap-1.5">
                    @foreach ([[__('Latte'), 65, '#d9b48f'], [__('Cappuccino'), 65, '#c89f79'], [__('Espresso'), 45, '#6b4a33'], [__('Cheesecake'), 90, '#f0d9b5'], [__('Brownies'), 70, '#5a3a2a'], [__('Iced latte'), 75, '#e6d3bd']] as $i => [$item, $price, $tone])
                        <div @if (in_array($i, [0, 3], true)) data-on="2-" @endif class="overflow-hidden rounded-xl bg-white ring-1 ring-navy-100 [&.is-on]:ring-2 [&.is-on]:ring-brand-500">
                            <div class="h-[46px]" style="background: {{ $tone }}"></div>
                            <div class="p-1.5"><b class="block truncate text-[10px]">{{ $item }}</b><span class="text-[10px] font-bold text-brand-700">{{ $price }} {{ __('EGP') }}</span></div>
                        </div>
                    @endforeach
                </div>
                <span data-show="2" class="sc-tap" style="top: 64px; inset-inline-start: 50px"></span>
            </div>
            <div class="flex flex-col border-s border-navy-100 bg-white p-2 text-[10px]">
                <b class="text-[11px]">{{ __('My order') }}</b>
                <div data-show="2-" class="mt-1.5 flex justify-between"><span>{{ __('Latte') }} · {{ __('Large') }}</span><b>80</b></div>
                <div data-show="2-" style="transition-delay: 300ms" class="mt-1 flex justify-between"><span>{{ __('Cheesecake') }}</span><b>90</b></div>
                <div class="mt-auto border-t border-navy-100 pt-1.5">
                    <div class="flex justify-between font-extrabold"><span>{{ __('Total') }}</span><span><x-landing.swap :values="['1' => '0', '2-' => '170']" /></span></div>
                    <div data-on="2-" class="mt-1.5 rounded-lg bg-navy-100 py-1.5 text-center font-bold text-navy-500 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Place my order') }}</div>
                </div>
            </div>
        </div>

        {{-- 3-4: name, then the order number --}}
        <div data-show="3-" class="sc-fade absolute inset-0 z-10 grid place-items-center bg-white/95">
            <div class="w-[230px] text-center">
                <div data-show="3" class="sc-fade">
                    <div class="text-xs font-extrabold">{{ __('Almost done') }}</div>
                    <div class="mt-2 rounded-lg border border-navy-200 px-2 py-1.5 text-start text-[11px] text-navy-500">{{ __('Your name') }}: <b class="text-navy-900">{{ __('Mona') }}</b></div>
                </div>
                <div data-show="3-" style="transition-delay: 900ms" class="sc-pop mt-3 rounded-2xl bg-emerald-600 px-4 py-3 text-white">
                    <div class="text-[11px] font-bold text-white/80">{{ __('Your order number') }}</div>
                    <div class="text-4xl font-black tabular-nums">74</div>
                    <div class="text-[10px] text-white/85">{{ __('Pay at the cashier') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<x-landing.sc-window :title="__('Live orders')" class="top-12 end-5 h-[270px] w-[190px]">
    <div class="space-y-1.5 p-2 text-[10px]">
        <div class="rounded-md bg-brand-50 px-1 py-0.5 text-center font-extrabold text-brand-700">{{ __('New') }}</div>
        <div data-show="4" class="sc-pop rounded-lg border border-brand-300 bg-white p-1.5 shadow-sm">
            <div class="flex justify-between font-extrabold"><span>#74</span><span class="text-brand-700">{{ __('Kiosk') }}</span></div>
            <div class="mt-0.5 text-navy-600">{{ __('Mona') }} · {{ __('Latte') }}, {{ __('Cheesecake') }}</div>
            <div class="mt-1 rounded bg-amber-50 px-1 font-bold text-amber-800">{{ __('Pay at the cashier') }} · 170</div>
        </div>
        <div class="rounded-lg border border-navy-100 bg-white p-1.5">
            <div class="flex justify-between font-extrabold"><span>#73</span><span class="text-navy-500">{{ __('Table 2') }}</span></div>
            <div class="mt-0.5 text-navy-600">2× {{ __('Iced latte') }}</div>
        </div>
        <div class="rounded-lg border border-navy-100 bg-white p-1.5">
            <div class="flex justify-between font-extrabold"><span>#72</span><span class="text-navy-500">{{ __('Takeaway') }}</span></div>
            <div class="mt-0.5 text-navy-600">1× {{ __('Espresso') }}</div>
        </div>
    </div>
    <span data-on="4" class="sc-ring absolute top-1.5 end-2 grid size-5 place-items-center rounded-md bg-navy-100 text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white"><x-landing.icon name="bell" class="size-3" /></span>
</x-landing.sc-window>
