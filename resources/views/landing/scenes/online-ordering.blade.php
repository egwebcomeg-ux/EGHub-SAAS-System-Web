{{-- Link in the Instagram bio → delivery + time → zone fee → the order arrives, 0 commission. --}}
<x-landing.sc-phone class="top-6 start-12">
    {{-- 1: a social profile with the menu link --}}
    <div data-show="1" class="sc-fade absolute inset-0 z-10 bg-white px-3 pt-8">
        <div class="flex items-center gap-2">
            <span class="grid size-12 place-items-center rounded-full bg-[#4a3426] text-sm font-black text-white">R</span>
            <div><b class="block text-xs">rosto.cafe</b><span class="text-[10px] text-navy-500">{{ __('Specialty coffee & homemade desserts') }}</span></div>
        </div>
        <div class="mt-3 rounded-lg bg-navy-50 px-2 py-1.5 text-[11px] font-bold text-sky-700" dir="ltr">egmenu.app/rosto</div>
        <div class="mt-3 grid grid-cols-3 gap-1">@for ($i = 0; $i < 9; $i++)<span class="aspect-square rounded bg-[#ead9c6]"></span>@endfor</div>
        <span data-show="1" class="sc-tap" style="top: 98px; inset-inline-start: 70px"></span>
    </div>
    <div class="bg-[#4a3426] px-3 pt-6 pb-3 text-white"><div class="text-sm font-extrabold">{{ __('Rosto') }}</div><div class="text-[10px] text-white/70">{{ __('Checkout') }}</div></div>
    <div class="space-y-2 p-2 text-[11px]">
        <div class="grid grid-cols-2 gap-1 rounded-xl bg-white p-1 font-bold ring-1 ring-navy-100">
            <span data-on="2-" class="rounded-lg py-1 text-center [&.is-on]:bg-navy-900 [&.is-on]:text-white">{{ __('Delivery') }}</span>
            <span class="rounded-lg py-1 text-center text-navy-500">{{ __('Pickup') }}</span>
        </div>
        <div data-show="2-" class="flex items-center justify-between rounded-xl bg-white px-2 py-1.5 ring-1 ring-navy-100"><span class="text-navy-500">{{ __('Time') }}</span><b>{{ __('Today 8:30 pm') }}</b></div>
        <div data-show="2-" class="flex items-center justify-between rounded-xl bg-white px-2 py-1.5 ring-1 ring-navy-100"><span class="text-navy-500">{{ __('Area') }}</span><b>{{ __('Nasr City') }}</b></div>
        <div class="rounded-xl bg-white p-2 ring-1 ring-navy-100">
            <div class="flex justify-between"><span>2× {{ __('Cheesecake') }}</span><b>180</b></div>
            <div data-show="3-" data-on="3" class="sc-flash mt-1 flex justify-between rounded"><span>{{ __('Delivery fee') }}</span><b>25</b></div>
            <div class="mt-1 flex justify-between border-t border-navy-100 pt-1 font-extrabold"><span>{{ __('Total') }}</span><span><span class="inline-grid *:[grid-area:1/1]"><span data-show="1-2" class="sc-fade">180</span><span data-show="3-" class="sc-fade">205</span></span> {{ __('EGP') }}</span></div>
        </div>
        <div class="rounded-lg bg-brand-600 py-2 text-center font-bold text-white">{{ __('Send order') }}</div>
    </div>
    <span data-show="2" class="sc-tap" style="top: 72px; inset-inline-start: 40px"></span>
</x-landing.sc-phone>

<x-landing.sc-window :title="__('Live orders')" class="top-14 end-8 h-[290px] w-[330px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div data-show="4" class="rounded-xl border-2 border-brand-400 bg-white p-2.5 shadow-sm">
            <div class="flex items-center justify-between"><b class="text-sm">#63</b><span class="rounded-full bg-sky-50 px-2 font-bold text-sky-700">{{ __('Delivery') }}</span></div>
            <div class="mt-1 text-navy-600">2× {{ __('Cheesecake') }} · {{ __('Nasr City') }}</div>
            <div class="mt-1 text-navy-500">{{ __('Today 8:30 pm') }}</div>
            <div class="mt-2 flex items-center justify-between border-t border-dashed border-navy-100 pt-2"><b>205 {{ __('EGP') }}</b><span class="rounded-full bg-emerald-50 px-2 py-0.5 font-extrabold text-emerald-700">{{ __('Commission: 0 EGP') }}</span></div>
        </div>
        <div class="rounded-xl border border-navy-100 bg-white p-2.5 opacity-70">
            <div class="flex items-center justify-between"><b>#61</b><span class="rounded-full bg-navy-50 px-2 font-bold text-navy-600">{{ __('Pickup') }}</span></div>
            <div class="mt-1 text-navy-600">1× {{ __('Iced latte') }} · 1× {{ __('Cookies') }}</div>
        </div>
    </div>
</x-landing.sc-window>
