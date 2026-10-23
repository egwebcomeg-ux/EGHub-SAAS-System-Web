{{-- egmenu.app/rosto from day one → book rosto.com → connected over https → the menu on rosto.com. --}}
<div class="absolute inset-5 overflow-hidden rounded-2xl border border-navy-200 bg-white shadow-[0_24px_40px_-26px_rgba(19, 29, 61, .6)]">
    <div class="flex h-10 items-center gap-2 border-b border-navy-100 bg-navy-50 px-3" dir="ltr">
        <i class="size-2.5 rounded-full bg-navy-200"></i><i class="size-2.5 rounded-full bg-navy-200"></i><i class="size-2.5 rounded-full bg-navy-200"></i>
        <div data-on="4" class="mx-auto flex h-7 w-[300px] items-center gap-1.5 rounded-lg border border-navy-200 bg-white px-3 text-[12px] font-bold [&.is-on]:border-emerald-400 [&.is-on]:ring-2 [&.is-on]:ring-emerald-100">
            <x-landing.icon name="shield" class="size-3.5 text-emerald-600" />
            <x-landing.swap :values="['1-3' => 'egmenu.app/rosto', '4-' => 'rosto.com']" />
        </div>
    </div>
    <div class="relative h-[calc(100%-2.5rem)] bg-[#fbf7f1]">
        <div class="bg-[#4a3426] px-5 py-4 text-white"><div class="text-lg font-black">{{ __('Rosto') }}</div><div class="text-[11px] text-white/70">{{ __('Specialty coffee & homemade desserts') }}</div></div>
        <div class="grid grid-cols-3 gap-2 p-4">
            @foreach ([[__('Latte'), 65], [__('Cappuccino'), 65], [__('Cheesecake'), 90]] as [$item, $price])
                <div class="rounded-xl bg-white p-2 ring-1 ring-navy-100"><div class="h-14 rounded-lg bg-[#ead9c6]"></div><b class="mt-1 block text-[11px]">{{ $item }}</b><span class="text-[10px] font-bold text-brand-700">{{ $price }} {{ __('EGP') }}</span></div>
            @endforeach
        </div>
        {{-- 2-3: the domain page in the dashboard --}}
        <div data-show="2-3" class="sc-fade absolute inset-0 grid place-items-center bg-navy-900/45">
            <div class="w-[330px] rounded-2xl bg-white p-4 text-[12px] shadow-xl">
                <b class="text-sm">{{ __('Your own domain') }}</b>
                <div class="mt-2 flex gap-1.5"><span class="flex-1 rounded-lg border border-navy-200 px-2 py-1.5 font-bold" dir="ltr">rosto.com</span><span data-show="2" class="sc-fade rounded-lg bg-brand-600 px-3 py-1.5 font-bold text-white">{{ __('Book') }}</span></div>
                <div class="mt-2 grid *:[grid-area:1/1]">
                    <div data-show="2" class="sc-fade font-bold text-emerald-700">✓ {{ __('Available') }}</div>
                    <div data-show="3" class="sc-fade space-y-1.5">
                        <div class="flex items-center justify-between rounded-lg bg-navy-50 px-2 py-1.5"><span>{{ __('Pointing to EgMenu') }}</span><b class="text-emerald-700">✓</b></div>
                        <div class="flex items-center justify-between rounded-lg bg-navy-50 px-2 py-1.5"><span>{{ __('Security certificate (https)') }}</span><span class="size-3.5 rounded-full border-2 border-navy-200 border-t-brand-600 sc-spin"></span></div>
                    </div>
                </div>
                <span data-show="2" class="sc-tap" style="top: 66px; inset-inline-end: 34px"></span>
            </div>
        </div>
        <div data-show="4" class="sc-pop absolute end-4 bottom-4 flex items-center gap-2 rounded-xl bg-navy-900 px-3 py-2 text-[11px] font-bold text-white shadow-xl"><x-landing.icon name="qr" class="size-4 text-brand-500" />{{ __('Your printed QR codes still work') }}</div>
    </div>
</div>
