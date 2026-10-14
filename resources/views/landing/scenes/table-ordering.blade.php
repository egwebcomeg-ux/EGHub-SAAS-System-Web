{{-- Guest scans table 4 → picks items → sends → the order rings on the board → preparing. --}}
<x-landing.sc-phone class="top-6 start-8">
    {{-- 1: camera on the table code --}}
    <div data-show="1" class="sc-fade absolute inset-0 z-10 grid place-items-center bg-[#1b1b1b]">
        <div class="relative grid size-28 place-items-center rounded-xl bg-white p-2">
            <x-landing.icon name="qr" class="size-full text-navy-900" />
            <span class="sc-scan"></span>
        </div>
        <span class="absolute bottom-8 rounded-full bg-white/15 px-3 py-1 text-[11px] font-bold text-white">{{ __('Table 4') }}</span>
    </div>
    <div class="bg-[#4a3426] px-3 pt-6 pb-3 text-white">
        <div class="text-sm font-extrabold">{{ __('Rosto') }}</div>
        <div class="text-[10px] text-white/70">{{ __('Table 4') }}</div>
    </div>
    <div class="flex gap-1 px-2 py-2 text-[10px] font-bold">
        <span class="rounded-full bg-brand-600 px-2 py-0.5 text-white">{{ __('Hot coffee') }}</span>
        <span class="rounded-full bg-white px-2 py-0.5 text-navy-600 ring-1 ring-navy-100">{{ __('Desserts') }}</span>
    </div>
    <div class="space-y-1.5 px-2">
        @foreach ([[__('Latte'), 65], [__('Cappuccino'), 65], [__('Cheesecake'), 90], [__('Brownies'), 70]] as $i => [$item, $price])
            <div @if (in_array($i, [0, 2], true)) data-on="2" @endif class="relative flex items-center gap-2 rounded-xl bg-white p-1.5 ring-1 ring-navy-100 [&.is-on]:ring-2 [&.is-on]:ring-brand-500">
                <span class="size-8 shrink-0 rounded-full bg-[#ead9c6]"></span>
                <span class="min-w-0 flex-1"><b class="block truncate text-[11px]">{{ $item }}</b><span class="text-[10px] font-bold text-brand-700">{{ $price }} {{ __('EGP') }}</span></span>
                <span class="grid size-5 place-items-center rounded-full bg-brand-600 text-[11px] font-bold text-white">+</span>
            </div>
        @endforeach
    </div>
    <span data-show="2" class="sc-tap" style="top: 128px; inset-inline-start: 60px"></span>
    {{-- cart bar --}}
    <div data-show="2" class="absolute inset-x-2 bottom-2 flex items-center justify-between rounded-xl bg-brand-600 px-3 py-2 text-[11px] font-bold text-white">
        <span><span class="me-1 rounded-full bg-white/25 px-1.5">2</span>{{ __('View order') }}</span><span>155 {{ __('EGP') }}</span>
    </div>
    {{-- 3: cart sheet --}}
    <div data-show="3" class="sc-slide absolute inset-x-0 bottom-0 z-10 rounded-t-2xl bg-white p-3 shadow-[0_-10px_30px_rgba(0,0,0,.2)]">
        <div class="text-xs font-extrabold">{{ __('Your order') }}</div>
        <div class="mt-2 flex justify-between text-[11px]"><span>1× {{ __('Latte') }} · {{ __('Large') }}</span><b>80</b></div>
        <div class="mt-1 flex justify-between text-[11px]"><span>1× {{ __('Cheesecake') }}</span><b>90</b></div>
        <div class="mt-1 rounded-md bg-amber-50 px-2 py-1 text-[10px] text-amber-800">{{ __('no sugar') }}</div>
        <div class="mt-2 flex justify-between border-t border-navy-100 pt-2 text-[11px] font-extrabold"><span>{{ __('Total') }}</span><span>170 {{ __('EGP') }}</span></div>
        <div class="mt-2 rounded-lg bg-brand-600 py-2 text-center text-[11px] font-bold text-white">{{ __('Send order') }}</div>
        <span data-show="3" class="sc-tap" style="bottom: 22px; inset-inline-start: 80px"></span>
    </div>
    {{-- 4+: confirmation --}}
    <div data-show="4-" class="absolute inset-x-2 top-16 z-10 flex items-center gap-2 rounded-xl bg-emerald-600 px-3 py-2 text-[11px] font-bold text-white shadow-lg">
        <x-landing.icon name="check" class="size-4" />{{ __('Order sent. Preparing now!') }}
    </div>
</x-landing.sc-phone>

<x-landing.sc-window :title="__('Live orders')" class="top-10 end-6 h-[330px] w-[380px]">
    <div class="flex items-center justify-between px-3 pt-2">
        <span class="flex items-center gap-1.5 text-xs font-extrabold"><span data-on="4" class="sc-ring grid size-6 place-items-center rounded-lg bg-navy-100 text-navy-700 [&.is-on]:bg-brand-600 [&.is-on]:text-white"><x-landing.icon name="bell" class="size-3.5" /></span>{{ __('Orders') }}</span>
        <span class="text-[10px] font-bold text-emerald-700">● {{ __('Live') }}</span>
    </div>
    <div class="grid grid-cols-3 gap-2 p-3 text-[10px]">
        @foreach ([[__('New'), 'bg-brand-50 text-brand-700'], [__('Preparing'), 'bg-amber-50 text-amber-800'], [__('Ready'), 'bg-emerald-50 text-emerald-800']] as $c => [$col, $tone])
            <div class="h-[250px] rounded-xl bg-navy-50/80 p-1.5">
                <div class="rounded-md px-1 py-0.5 text-center font-extrabold {{ $tone }}">{{ $col }}</div>
                <div class="relative mt-1.5 space-y-1.5">
                    @if ($c === 0)
                        <div data-show="4" class="rounded-lg border border-brand-300 bg-white p-1.5 shadow-sm">
                            <div class="flex justify-between font-extrabold"><span>#62</span><span class="text-brand-700">{{ __('Table 4') }}</span></div>
                            <div class="mt-0.5 text-navy-600">1× {{ __('Latte') }} · 1× {{ __('Cheesecake') }}</div>
                            <div class="mt-1 rounded bg-amber-50 px-1 text-amber-800">{{ __('no sugar') }}</div>
                        </div>
                    @elseif ($c === 1)
                        <div data-show="5" class="rounded-lg border border-amber-300 bg-white p-1.5 shadow-sm">
                            <div class="flex justify-between font-extrabold"><span>#62</span><span class="text-brand-700">{{ __('Table 4') }}</span></div>
                            <div class="mt-0.5 text-navy-600">1× {{ __('Latte') }} · 1× {{ __('Cheesecake') }}</div>
                        </div>
                        <div class="rounded-lg border border-navy-100 bg-white p-1.5">
                            <div class="flex justify-between font-extrabold"><span>#59</span><span class="text-navy-500">{{ __('Table 2') }}</span></div>
                            <div class="mt-0.5 text-navy-600">2× {{ __('Iced latte') }}</div>
                        </div>
                    @else
                        <div class="rounded-lg border border-navy-100 bg-white p-1.5">
                            <div class="flex justify-between font-extrabold"><span>#58</span><span class="text-navy-500">{{ __('Takeaway') }}</span></div>
                            <div class="mt-0.5 text-navy-600">2× {{ __('Espresso') }}</div>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-landing.sc-window>
