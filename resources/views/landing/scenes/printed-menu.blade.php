{{-- Change a price → pick a template → export → the A4 page carries the new price. --}}
<x-landing.sc-window :title="__('Menu')" class="top-10 start-8 h-[310px] w-[260px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="font-extrabold">{{ __('Latte') }}</div>
        <label class="block text-navy-500">{{ __('Price') }}</label>
        <div data-on="1" class="flex items-center justify-between rounded-lg border border-navy-200 px-2 py-1.5 font-bold [&.is-on]:border-brand-500 [&.is-on]:ring-2 [&.is-on]:ring-brand-200">
            <x-landing.swap :values="['1' => '65 → 70', '2-' => '70']" /><span class="text-navy-400">{{ __('EGP') }}</span>
        </div>
        <div data-show="2-" class="pt-1">
            <div class="mb-1 text-navy-500">{{ __('Template') }}</div>
            <div class="grid grid-cols-2 gap-1.5">
                <span data-on="2-" class="rounded-lg border border-navy-200 py-1.5 text-center font-bold [&.is-on]:border-navy-900 [&.is-on]:bg-navy-900 [&.is-on]:text-white">{{ __('Classic') }}</span>
                <span class="rounded-lg border border-navy-200 py-1.5 text-center font-bold">{{ __('Minimal') }}</span>
            </div>
            <div class="mt-1.5 flex gap-1.5"><span class="rounded-md bg-navy-50 px-2 py-0.5">A4</span><span class="rounded-md bg-navy-50 px-2 py-0.5">{{ __('Arabic + English') }}</span></div>
        </div>
        <div data-on="3" class="mt-1 flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 py-2 font-bold text-white [&.is-on]:bg-brand-700">
            <span data-show="3" class="sc-fade size-3 rounded-full border-2 border-white/40 border-t-white sc-spin"></span>{{ __('Print / save PDF') }}
        </div>
        <span data-show="3" class="sc-tap" style="bottom: 18px; inset-inline-start: 120px"></span>
    </div>
</x-landing.sc-window>

{{-- the A4 page --}}
<div class="absolute top-5 end-16 h-[372px] w-[264px] bg-white p-5 shadow-[0_24px_40px_-24px_rgba(19, 29, 61, .6)] ring-1 ring-navy-100">
    <div class="text-lg font-black">{{ __('Rosto') }}</div>
    <div class="text-[9px] text-navy-500">{{ __('Specialty coffee & homemade desserts') }}</div>
    <div data-on="2-" class="mt-2 h-0.5 bg-navy-200 [&.is-on]:bg-navy-900"></div>
    @foreach ([[__('Hot coffee'), [[__('Espresso'), 45], [__('Latte'), null], [__('Cappuccino'), 65], [__('Mocha'), 75]]], [__('Desserts'), [[__('Cheesecake'), 90], [__('Brownies'), 70]]]] as [$cat, $rows])
        <div data-on="2-" class="mt-3 border-b border-navy-200 pb-0.5 text-[12px] font-black text-navy-500 [&.is-on]:border-brand-500 [&.is-on]:text-brand-700">{{ $cat }}</div>
        @foreach ($rows as [$item, $price])
            <div class="mt-1.5 flex items-end text-[11px]">
                <span class="font-bold">{{ $item }}</span><span class="mx-1 mb-1 flex-1 border-b border-dotted border-navy-300"></span>
                @if ($price === null)
                    <b data-on="4" class="sc-flash rounded px-0.5"><x-landing.swap :values="['1-3' => '65', '4-' => '70']" /></b>
                @else
                    <b>{{ $price }}</b>
                @endif
            </div>
        @endforeach
    @endforeach
    <div class="absolute inset-x-5 bottom-4 flex items-center justify-between border-t border-navy-100 pt-2 text-[9px] text-navy-500"><span>{{ __('Prices in EGP') }}</span><x-landing.icon name="qr" class="size-7 text-navy-900" /></div>
    <div data-show="4-" class="sc-pop absolute -top-3 -end-3 rounded-full bg-emerald-600 px-3 py-1 text-[11px] font-bold text-white shadow-lg">A4 · PDF ✓</div>
</div>
