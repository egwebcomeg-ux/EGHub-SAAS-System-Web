{{-- Order #64 goes to Hassan from the board → he taps picked up → Karim watches the scooter on the map → delivered, the cash settles. --}}
<x-landing.sc-window :title="__('Order board')" class="top-6 start-6 h-[350px] w-[330px]">
    <div class="space-y-2 p-3 text-[11px]">
        <div class="rounded-xl border border-navy-100 p-2.5">
            <div class="flex items-center justify-between">
                <b class="text-xs">#64 · {{ __('Karim') }}</b>
                <span data-on="2-" class="rounded-full bg-navy-100 px-2 py-0.5 font-bold text-navy-600 [&.is-on]:bg-brand-100 [&.is-on]:text-brand-800"><x-landing.swap :values="['1' => __('Ready'), '2-3' => __('On the way'), '4' => __('Delivered')]" /></span>
            </div>
            <div class="mt-1 flex items-start gap-1 text-navy-600"><x-landing.icon name="flag" class="mt-0.5 size-3 shrink-0 text-brand-600" />{{ __('Nasr City — 15 Abbas El Akkad St.') }}</div>
            <div class="mt-1 flex justify-between"><span>2× {{ __('Cheesecake') }} · {{ __('Fee') }} 25</span><b>205 {{ __('EGP') }}</b></div>
            <div class="mt-2 flex items-center gap-1.5">
                <span class="text-navy-500">{{ __('Driver') }}</span>
                <div data-on="1-" class="flex-1 rounded-lg border border-navy-200 px-2 py-1 font-bold [&.is-on]:border-brand-500 [&.is-on]:bg-brand-50"><x-landing.swap :values="['1' => __('Choose a driver…'), '2-' => __('Hassan')]" /></div>
            </div>
            <span data-show="1" class="sc-tap" style="top: 88px; inset-inline-start: 150px"></span>
        </div>
        {{-- the drivers strip: who holds what --}}
        <div class="rounded-xl bg-navy-50 p-2.5">
            <div class="mb-1.5 flex items-center gap-1.5 font-bold"><x-landing.icon name="scooter" class="size-4 text-brand-600" />{{ __('Drivers') }}</div>
            <div class="flex items-center justify-between rounded-lg bg-white px-2 py-1.5">
                <span><b>{{ __('Hassan') }}</b> <span class="text-navy-500">· <x-landing.swap :values="['1' => __('Free'), '2-3' => __('1 order out'), '4' => __('Free')]" /></span></span>
                <span data-on="4" class="font-bold tabular-nums text-navy-600 [&.is-on]:text-emerald-700"><x-landing.swap :values="['1' => '0', '2-3' => '205', '4' => __('Settled') . ' ✓']" /> <span data-show="1-3" class="text-navy-500">{{ __('EGP cash') }}</span></span>
            </div>
            <div class="mt-1 flex items-center justify-between rounded-lg bg-white px-2 py-1.5"><span><b>{{ __('Mostafa') }}</b> <span class="text-navy-500">· {{ __('2 orders out') }}</span></span><span class="font-bold tabular-nums">340 <span class="text-navy-500">{{ __('EGP cash') }}</span></span></div>
        </div>
        <div data-show="4" class="sc-pop flex items-center gap-2 rounded-xl bg-navy-900 px-3 py-2 font-bold text-white"><x-landing.icon name="cash" class="size-4 text-brand-500" />{{ __('205 EGP from Hassan settled into the shift') }}</div>
    </div>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-6">
    {{-- 1-2: Hassan's driver screen --}}
    <div data-show="1-2" class="sc-fade absolute inset-0 bg-navy-50 p-2 text-[10px]">
        <div class="mt-3 flex items-center gap-1.5 px-1 font-bold"><x-landing.icon name="scooter" class="size-4 text-brand-600" />{{ __('My deliveries') }}</div>
        <div data-show="2-" class="sc-fade mt-2 rounded-2xl border border-navy-100 bg-white p-2.5">
            <div class="flex items-center justify-between"><b class="text-xs">#64</b><span class="rounded-full bg-brand-100 px-2 py-0.5 font-bold text-brand-800">{{ __('Delivery') }}</span></div>
            <div class="mt-1 text-navy-600">{{ __('Karim') }} · <span dir="ltr">0109 988 7766</span></div>
            <div class="mt-0.5 flex items-start gap-1 text-navy-600"><x-landing.icon name="flag" class="mt-0.5 size-3 shrink-0 text-brand-600" />{{ __('Nasr City — 15 Abbas El Akkad St.') }}</div>
            <div class="mt-1.5 flex justify-between rounded-lg bg-navy-50 px-2 py-1"><span>{{ __('Collect') }}</span><b>205 {{ __('EGP') }}</b></div>
            <div data-on="2" class="mt-2 rounded-lg bg-navy-100 py-1.5 text-center text-[11px] font-bold text-navy-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Picked up') }}</div>
        </div>
        <p data-show="1" class="mt-10 text-center text-navy-500">{{ __('No deliveries yet') }}</p>
        <span data-show="2" class="sc-tap" style="top: 178px; inset-inline-start: 82px"></span>
    </div>
    {{-- 3-4: Karim's tracking page with the live map --}}
    <div data-show="3-" class="sc-fade absolute inset-0 bg-[#fbf7f1] p-2 text-[10px]">
        <div class="mt-3 px-1"><b class="text-xs">{{ __('Order') }} #64</b><div class="text-navy-500"><x-landing.swap :values="['3' => __('On the way with Hassan'), '4' => __('Delivered') . ' ✓']" /></div></div>
        <div class="relative mt-2 h-[200px] overflow-hidden rounded-2xl bg-[#e9efe6]">
            {{-- streets --}}
            <i class="absolute inset-y-0 start-[38%] w-2.5 bg-white"></i><i class="absolute inset-x-0 top-[46%] h-2.5 bg-white"></i><i class="absolute inset-x-0 top-[78%] h-1.5 bg-white"></i><i class="absolute inset-y-0 start-[72%] w-1.5 bg-white"></i>
            {{-- the restaurant and the home --}}
            <span class="absolute top-[14%] start-[18%] grid size-6 place-items-center rounded-full bg-navy-900 text-white"><x-landing.icon name="store" class="size-3.5" /></span>
            <span class="absolute top-[70%] start-[76%] grid size-6 place-items-center rounded-full bg-brand-600 text-white"><x-landing.icon name="flag" class="size-3.5" /></span>
            {{-- the scooter: halfway in step 3, at the door in step 4 --}}
            <span data-show="3" class="sc-fade absolute top-[40%] start-[36%] grid size-7 place-items-center rounded-full bg-white text-brand-700 shadow-lg"><span class="sc-ring is-on grid size-7 place-items-center rounded-full"><x-landing.icon name="scooter" class="size-4" /></span></span>
            <span data-show="4" class="sc-fade absolute top-[62%] start-[68%] grid size-7 place-items-center rounded-full bg-emerald-600 text-white shadow-lg"><x-landing.icon name="check" class="size-4" /></span>
        </div>
        <div class="mt-2 flex items-center justify-between rounded-xl border border-navy-100 bg-white px-2 py-1.5"><span>{{ __('Hassan') }} · <span class="text-navy-500">{{ __('Driver') }}</span></span><b><x-landing.swap :values="['3' => __(':n min', ['n' => 8]), '4' => __('Arrived')]" /></b></div>
    </div>
</x-landing.sc-phone>
