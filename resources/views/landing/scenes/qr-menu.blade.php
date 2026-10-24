{{-- Scan the table code → Rosto's menu opens → jump to desserts → the owner switches the design. --}}
{{-- table tent with the code (steps 1-3), then the design picker in the dashboard (step 4) --}}
<div data-show="1-3" class="sc-fade absolute top-16 start-16 w-[200px] rounded-2xl border border-navy-100 bg-white p-4 text-center shadow-[0_20px_40px_-26px_rgba(19, 29, 61, .6)]">
    <div class="text-sm font-black">{{ __('Rosto') }}</div>
    <div class="text-[10px] font-bold text-navy-500">{{ __('Scan for the menu') }}</div>
    <div class="relative mx-auto mt-3 size-32 overflow-hidden rounded-xl border-2 border-navy-900 p-2">
        <x-landing.icon name="qr" class="size-full text-navy-900" />
        <span data-show="1" class="sc-fade"><span class="sc-scan"></span></span>
    </div>
    <div class="mt-3 rounded-full bg-navy-900 py-1 text-[11px] font-bold text-white">{{ __('Table 4') }}</div>
</div>
<x-landing.sc-window :title="__('Settings') . ' · ' . __('Menu design')" data-show="4" class="top-12 start-8 h-[290px] w-[300px]">
    <div class="grid grid-cols-2 gap-2 p-3">
        @foreach ([['Cozy cafe', 'bg-[#4a3426]', 'bg-[#fbf7f1]', false], ['Dark', 'bg-[#131D3D]', 'bg-[#1d2740]', true], ['Bistro', 'bg-[#2b2b2b]', 'bg-[#f4efe6]', false], ['Classic', 'bg-navy-800', 'bg-white', false]] as [$theme, $top, $body, $picked])
            <div @class(['rounded-xl border p-1.5', 'border-brand-500 ring-2 ring-brand-500' => $picked, 'border-navy-100' => !$picked])>
                <div class="h-16 overflow-hidden rounded-lg {{ $body }}"><div class="h-5 {{ $top }}"></div><div class="mx-1.5 mt-1.5 h-2 rounded bg-black/10"></div><div class="mx-1.5 mt-1 h-2 w-2/3 rounded bg-black/10"></div></div>
                <div class="mt-1 text-center text-[10px] font-bold">{{ __($theme) }}</div>
            </div>
        @endforeach
    </div>
    <span data-show="4" class="sc-tap" style="top: 70px; inset-inline-end: 70px"></span>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-24">
    <div data-show="1" class="sc-fade absolute inset-0 z-10 grid place-items-center bg-[#1b1b1b] text-white">
        <div class="relative size-28 rounded-2xl border-2 border-white/80"><span class="sc-scan"></span></div>
        <span class="absolute bottom-10 text-[11px] font-bold text-white/80" dir="ltr">eghub.app/rosto</span>
    </div>
    <div data-on="4" class="group h-full bg-[#fbf7f1] transition-colors duration-700 [&.is-on]:bg-[#151d31]">
        <div class="bg-[#4a3426] px-3 pt-6 pb-3 text-white transition-colors duration-700 group-[.is-on]:bg-[#0b1120]">
            <div class="text-sm font-extrabold">{{ __('Rosto') }}</div>
            <div class="text-[10px] text-white/70">{{ __('Specialty coffee & homemade desserts') }}</div>
        </div>
        <div class="flex gap-1 px-2 py-2 text-[10px] font-bold">
            <span data-on="1-2" class="rounded-full bg-white px-2 py-0.5 text-navy-600 ring-1 ring-navy-100 [&.is-on]:bg-brand-600 [&.is-on]:text-white [&.is-on]:ring-brand-600">{{ __('Hot coffee') }}</span>
            <span class="rounded-full bg-white px-2 py-0.5 text-navy-600 ring-1 ring-navy-100">{{ __('Cold drinks') }}</span>
            <span data-on="3-" class="rounded-full bg-white px-2 py-0.5 text-navy-600 ring-1 ring-navy-100 [&.is-on]:bg-brand-600 [&.is-on]:text-white [&.is-on]:ring-brand-600">{{ __('Desserts') }}</span>
        </div>
        <div class="h-[250px] overflow-hidden px-2">
            <div data-on="3-" class="space-y-1.5 transition-transform duration-700 [&.is-on]:-translate-y-[176px]">
                @foreach ([[__('Hot coffee'), null], [__('Espresso'), 45], [__('Latte'), 65], [__('Cappuccino'), 65], [__('Desserts'), null], [__('Cheesecake'), 90], [__('Brownies'), 70], [__('Cookies'), 35]] as [$name, $price])
                    @if ($price === null)
                        <div class="pt-1 text-[12px] font-black group-[.is-on]:text-white">{{ $name }}</div>
                    @else
                        <div class="flex items-center gap-2 rounded-xl bg-white p-1.5 ring-1 ring-navy-100 transition-colors duration-700 group-[.is-on]:bg-[#212b45] group-[.is-on]:ring-white/10">
                            <span class="size-8 shrink-0 rounded-full bg-[#ead9c6] group-[.is-on]:bg-[#33405e]"></span>
                            <span><b class="block text-[11px] group-[.is-on]:text-white">{{ $name }}</b><span class="text-[10px] font-bold text-brand-700 group-[.is-on]:text-brand-500">{{ $price }} {{ __('EGP') }}</span></span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
    <span data-show="3" class="sc-tap" style="top: 78px; inset-inline-start: 120px"></span>
</x-landing.sc-phone>
