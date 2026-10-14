{{-- Set a happy hour → at 3:00 prices drop → a popup on open → a pairing suggestion. --}}
<x-landing.sc-window :title="__('Offers and popups')" class="top-10 start-8 h-[300px] w-[280px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="rounded-xl border border-navy-100 p-2.5">
            <div class="flex items-center justify-between"><b>{{ __('Happy hour on cold drinks') }}</b>
                <span data-on="2-" class="rounded-full bg-navy-100 px-2 py-0.5 font-bold text-navy-500 [&.is-on]:bg-emerald-100 [&.is-on]:text-emerald-700"><x-landing.swap :values="['1' => __('Scheduled'), '2-' => __('Running now')]" /></span></div>
            <div class="mt-2 grid grid-cols-3 gap-1 text-center">
                <span class="rounded-md bg-navy-50 py-1 font-extrabold text-brand-700">−20%</span>
                <span class="rounded-md bg-navy-50 py-1">3:00</span>
                <span class="rounded-md bg-navy-50 py-1">6:00</span>
            </div>
            <div class="mt-1.5 text-navy-500">{{ __('Every day') }}</div>
        </div>
        <div data-show="3-" class="rounded-xl border border-navy-100 p-2.5"><b>{{ __('Popup') }}:</b> {{ __('New: lotus cheesecake') }} <span class="text-navy-500">· 128 {{ __('views') }}</span></div>
        <div data-show="4-" class="rounded-xl border border-navy-100 p-2.5"><b>{{ __('Goes well with') }}:</b> {{ __('Latte') }} + {{ __('Butter croissant') }}</div>
        <div class="flex items-center gap-1.5 pt-1 text-sm font-black text-navy-900"><x-landing.icon name="clock" class="size-4 text-brand-600" /><x-landing.swap :values="['1' => '2:59', '2-' => '3:00']" /> {{ __('pm') }}</div>
    </div>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-20">
    <div class="bg-[#4a3426] px-3 pt-6 pb-3 text-white"><div class="text-sm font-extrabold">{{ __('Rosto') }}</div><div class="text-[10px] text-white/70">{{ __('Cold drinks') }}</div></div>
    <div data-show="2-" class="mx-2 mt-2 flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-800 ring-1 ring-emerald-200"><span class="rounded bg-emerald-600 px-1 text-white">−20%</span>{{ __('Happy hour on cold drinks') }}</div>
    <div class="space-y-1.5 p-2">
        @foreach ([[__('Iced latte'), 75, 60], [__('Caramel frappe'), 85, 68], [__('Lemon mint'), 50, 40]] as [$item, $old, $new])
            <div class="flex items-center gap-2 rounded-xl bg-white p-1.5 ring-1 ring-navy-100">
                <span class="size-8 shrink-0 rounded-full bg-[#d7e6ee]"></span>
                <span class="flex-1"><b class="block text-[11px]">{{ $item }}</b>
                    <span class="text-[10px] font-bold"><span data-on="2-" class="text-brand-700 [&.is-on]:text-navy-400 [&.is-on]:line-through">{{ $old }}</span> <span data-show="2-" class="sc-fade text-brand-700">{{ $new }}</span> {{ __('EGP') }}</span></span>
            </div>
        @endforeach
    </div>
    {{-- 3: popup --}}
    <div data-show="3" class="sc-fade absolute inset-0 z-10 grid place-items-center bg-black/50 p-3">
        <div class="sc-pop w-full overflow-hidden rounded-2xl bg-white text-center">
            <div class="h-20 bg-[#ead9c6]"></div>
            <div class="p-2.5"><b class="text-xs">{{ __('New: lotus cheesecake') }}</b><div class="mt-2 rounded-lg bg-brand-600 py-1.5 text-[11px] font-bold text-white">{{ __('Show me') }}</div></div>
        </div>
    </div>
    {{-- 4: pairing in the item sheet --}}
    <div data-show="4" class="sc-slide absolute inset-x-0 bottom-0 z-10 rounded-t-2xl bg-white p-3 shadow-[0_-10px_30px_rgba(0,0,0,.2)] text-[11px]">
        <b class="text-xs">{{ __('Latte') }}</b>
        <div class="mt-2 text-navy-500">{{ __('Goes well with') }}</div>
        <div class="mt-1 flex items-center justify-between rounded-xl bg-brand-50 p-2 ring-1 ring-brand-200"><span><b>{{ __('Butter croissant') }}</b> · 45</span><span class="grid size-5 place-items-center rounded-full bg-brand-600 font-bold text-white">+</span></div>
        <div class="mt-2 rounded-lg bg-brand-600 py-1.5 text-center font-bold text-white">{{ __('Add') }} · 110 {{ __('EGP') }}</div>
        <span data-show="4" class="sc-tap" style="top: 64px; inset-inline-end: 22px"></span>
    </div>
</x-landing.sc-phone>
