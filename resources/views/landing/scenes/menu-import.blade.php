{{-- Upload a photo or Excel → the lines are read → review → publish. --}}
{{-- the paper menu photo --}}
<div data-show="1-2" class="absolute top-10 start-10 h-[320px] w-[220px] rotate-[-3deg] bg-[#fdfaf3] p-4 shadow-[0_24px_40px_-24px_rgba(19, 29, 61, .6)] ring-1 ring-black/5">
    <div class="text-center text-sm font-black">{{ __('Rosto') }}</div>
    @foreach ([[__('Espresso'), 45], [__('Latte'), 65], [__('Cappuccino'), 65], [__('Mocha'), 75], [__('Cheesecake'), 90], [__('Brownies'), 70], [__('Cookies'), 35]] as $i => [$item, $price])
        <div data-on="2" style="transition-delay: {{ $i * 160 }}ms" class="mt-2 flex items-end rounded px-1 text-[11px] [&.is-on]:bg-brand-100"><span class="font-bold">{{ $item }}</span><span class="mx-1 mb-1 flex-1 border-b border-dotted border-navy-300"></span><b>{{ $price }}</b></div>
    @endforeach
    <span data-show="2" class="sc-fade"><span class="sc-scan"></span></span>
</div>
<div data-show="1" class="sc-pop absolute top-[340px] start-24 flex items-center gap-1.5 rounded-full bg-navy-900 px-3 py-1.5 text-[11px] font-bold text-white"><x-landing.icon name="upload" class="size-3.5" />menu.jpg</div>

{{-- review table --}}
<x-landing.sc-window :title="__('Menu import')" class="top-8 end-8 h-[330px] w-[340px]">
    <div class="p-3 text-[11px]">
        <div class="flex items-center justify-between"><b>{{ __('Review before publishing') }}</b>
            <span data-show="2" class="sc-fade flex items-center gap-1 text-navy-500"><span class="size-3 rounded-full border-2 border-navy-200 border-t-brand-600 sc-spin"></span>{{ __('Reading…') }}</span></div>
        <div class="mt-2 divide-y divide-navy-100 rounded-lg border border-navy-100">
            @foreach ([[__('Hot coffee'), __('Espresso'), 45], [__('Hot coffee'), __('Latte'), 65], [__('Hot coffee'), __('Cappuccino'), 65], [__('Hot coffee'), __('Mocha'), 75], [__('Desserts'), __('Cheesecake'), 90], [__('Desserts'), __('Brownies'), 70], [__('Desserts'), __('Cookies'), 35]] as $i => [$cat, $item, $price])
                <div data-show="3-" style="transition-delay: {{ $i * 110 }}ms" class="flex items-center gap-2 px-2 py-1.5">
                    <span class="grid size-4 place-items-center rounded bg-emerald-600 text-white"><x-landing.icon name="check" class="size-3" /></span>
                    <span class="w-20 text-navy-500">{{ $cat }}</span><b class="flex-1">{{ $item }}</b><b>{{ $price }}</b>
                </div>
            @endforeach
        </div>
        <div data-on="4" class="mt-2.5 rounded-lg bg-navy-200 py-1.5 text-center font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Publish') }}</div>
        <span data-show="4" class="sc-tap" style="bottom: 22px; inset-inline-start: 160px"></span>
    </div>
</x-landing.sc-window>
<div data-show="4" class="sc-pop absolute top-[200px] start-16 w-[200px] rounded-2xl bg-emerald-600 p-4 text-white shadow-xl">
    <x-landing.icon name="check" class="size-7" />
    <b class="mt-2 block text-sm">{{ __('7 items are live') }}</b>
    <span class="text-[11px] text-white/80" dir="ltr">egmenu.app/rosto</span>
</div>
