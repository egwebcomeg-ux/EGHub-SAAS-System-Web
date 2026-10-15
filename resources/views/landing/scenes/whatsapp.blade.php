{{-- A delivery order lands → "received" on WhatsApp → "on the way" when it leaves → each message took one credit. --}}
<x-landing.sc-phone class="top-6 start-10">
    <div class="flex items-center gap-2 bg-[#075e54] px-3 pt-6 pb-2.5 text-white">
        <span class="grid size-7 place-items-center rounded-full bg-[#4a3426] text-[11px] font-black">R</span>
        <div><div class="text-[12px] font-extrabold">{{ __('Rosto') }}</div><div class="text-[9px] text-white/70">WhatsApp</div></div>
    </div>
    <div class="h-full space-y-2 bg-[#ece5dd] p-2 text-[10.5px] leading-snug">
        <div class="mx-auto w-fit rounded-md bg-white/70 px-2 py-0.5 text-[9px] text-navy-500">{{ __('Today') }}</div>
        <div data-show="2-" class="sc-pop max-w-[88%] rounded-xl rounded-ss-sm bg-white p-2 shadow-sm">
            {{ __('Hi Karim 👋 Rosto received your order #63 and is on it. Follow it here:') }}
            <span class="block truncate text-[#1a73e8]" dir="ltr">egmenu.app/rosto/o/…</span>
            <span class="block text-end text-[9px] text-navy-400" dir="ltr">8:02</span>
        </div>
        <div data-show="3-" class="sc-pop max-w-[88%] rounded-xl rounded-ss-sm bg-white p-2 shadow-sm">
            {{ __('Karim, your order #63 from Rosto is on its way 🛵') }}
            <span class="block text-end text-[9px] text-navy-400" dir="ltr">8:24</span>
        </div>
    </div>
    <div data-show="2" class="absolute inset-x-2 top-2 z-10 flex items-center gap-2 rounded-xl bg-white/95 px-2.5 py-1.5 text-[10px] font-bold shadow-lg">
        <span class="grid size-5 place-items-center rounded-md bg-[#25d366] text-white"><x-landing.icon name="chat" class="size-3" /></span>{{ __('Rosto') }} · WhatsApp
    </div>
</x-landing.sc-phone>

<x-landing.sc-window :title="__('Live orders')" class="top-10 end-6 h-[300px] w-[350px]">
    <div class="grid grid-cols-2 gap-2 p-3 text-[10px]">
        @foreach ([[__('Preparing'), 'bg-amber-50 text-amber-800', '1-2'], [__('On the way'), 'bg-sky-50 text-sky-800', '3-']] as [$col, $tone, $when])
            <div class="h-[150px] rounded-xl bg-navy-50/80 p-1.5">
                <div class="rounded-md px-1 py-0.5 text-center font-extrabold {{ $tone }}">{{ $col }}</div>
                <div data-show="{{ $when }}" class="mt-1.5 rounded-lg border border-navy-200 bg-white p-1.5 shadow-sm">
                    <div class="flex justify-between font-extrabold"><span>#63</span><span class="text-brand-700">{{ __('Delivery') }}</span></div>
                    <div class="mt-0.5 text-navy-600">{{ __('Karim') }} · {{ __('Nasr City') }}</div>
                    <div class="mt-1 flex items-center gap-1 font-bold text-emerald-700"><x-landing.icon name="chat" class="size-3" /><x-landing.swap :values="['1' => '…', '2' => __('Received ✓'), '3-' => __('On the way ✓')]" /></div>
                </div>
            </div>
        @endforeach
    </div>
    <span data-show="3" class="sc-tap" style="top: 70px; inset-inline-end: 90px"></span>
    <div data-on="4" class="sc-flash mx-3 flex items-center justify-between rounded-xl border border-navy-100 p-2.5 text-[11px]">
        <span class="flex items-center gap-2"><span class="grid size-7 place-items-center rounded-lg bg-[#25d366] text-white"><x-landing.icon name="chat" class="size-4" /></span><b>{{ __('WhatsApp credits') }}</b></span>
        <b class="text-lg font-black tabular-nums"><x-landing.swap :values="['1' => '60', '2' => '59', '3-' => '58']" /></b>
    </div>
    <div data-show="4" class="mx-3 mt-1.5 text-[10px] text-navy-500">{{ __('2 messages sent for order #63 · 1 credit each') }}</div>
</x-landing.sc-window>
