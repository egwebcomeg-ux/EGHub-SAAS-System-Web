{{-- +1 on the pager station shows order 42's QR → Karim scans and waits → "ready" rings his phone → he picks it up and rates. --}}
<x-landing.sc-window :title="__('Pager station')" class="top-8 start-5 h-[300px] w-[360px]">
    <div class="grid h-full grid-cols-[150px_1fr] gap-2 p-2.5 text-[10px]">
        <div class="rounded-xl bg-navy-50 p-2">
            <div class="font-bold text-navy-500">{{ __('New ticket') }}</div>
            <div class="mt-1 rounded-lg bg-white py-1 text-center text-xl font-black tabular-nums ring-1 ring-navy-200" dir="ltr"><x-landing.swap :values="['1' => '#', '2-' => '42']" /></div>
            <div class="mt-1.5 grid grid-cols-3 gap-1" dir="ltr">
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $k)
                    <span class="rounded-md bg-white py-1 text-center font-bold ring-1 ring-navy-100">{{ $k }}</span>
                @endforeach
            </div>
            <div data-on="1" class="mt-1.5 rounded-lg bg-navy-200 py-1.5 text-center font-extrabold text-navy-700 [&.is-on]:bg-brand-600 [&.is-on]:text-white">+1 · {{ __('Next number #:n', ['n' => 42]) }}</div>
            <span data-show="1" class="sc-tap" style="top: 196px; inset-inline-start: 70px"></span>
        </div>
        <div class="relative rounded-xl border border-navy-100 p-2">
            <div class="font-bold text-navy-500">{{ __('Queue') }}</div>
            <div data-show="2-" class="sc-pop mt-1.5 rounded-lg border border-navy-100 p-1.5">
                <div class="flex items-center justify-between"><b class="text-base font-black" dir="ltr">#42</b>
                    <span class="rounded-full px-1.5 py-0.5 font-bold [&.is-on]:bg-amber-100 [&.is-on]:text-amber-800" data-on="3-"><x-landing.swap :values="['2' => __('Waiting'), '3-' => __('Called')]" /></span></div>
                <div data-show="2-" style="transition-delay: 700ms" class="mt-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-bold text-emerald-800">● {{ __('The guest has the page open') }}</div>
                <div data-on="3" class="mt-1 rounded-md bg-navy-100 py-1 text-center font-extrabold [&.is-on]:bg-brand-600 [&.is-on]:text-white">📣 {{ __('Call the guest') }}</div>
                <span data-show="3" class="sc-tap" style="top: 78px; inset-inline-start: 70px"></span>
            </div>
            <div class="mt-1.5 rounded-lg border border-navy-100 p-1.5 opacity-60"><b dir="ltr">#41</b> · {{ __('Picked up') }}</div>
        </div>
        {{-- the QR the guest scans --}}
        <div data-show="1-2" class="sc-fade absolute inset-0 z-10 grid place-items-center bg-white/95" style="transition-delay: 500ms">
            <div class="text-center">
                <div class="text-2xl font-black" dir="ltr">#42</div>
                <div class="mx-auto mt-1 grid size-24 grid-cols-6 gap-0.5 rounded-lg bg-white p-1.5 ring-1 ring-navy-200" aria-hidden="true">
                    @foreach ([1,1,1,0,1,1, 1,0,1,1,0,1, 1,1,1,0,1,0, 0,1,0,1,1,1, 1,0,1,0,0,1, 1,1,0,1,1,1] as $cell)
                        <i class="{{ $cell ? 'bg-navy-900' : 'bg-white' }} rounded-[2px]"></i>
                    @endforeach
                </div>
                <div class="mt-1.5 text-[10px] font-bold">📣 {{ __('Scan so we can call you when your order is ready') }}</div>
            </div>
        </div>
    </div>
</x-landing.sc-window>

<x-landing.sc-phone class="top-6 end-10">
    <div class="flex h-full flex-col items-center px-3 pt-8 text-center">
        <div class="text-[11px] font-extrabold">{{ __('Rosto') }}</div>
        <div class="mt-3 text-[10px] font-bold text-navy-500">{{ __('Order number') }}</div>
        <div class="text-4xl font-black tabular-nums" dir="ltr"><x-landing.swap :values="['1' => '—', '2-' => '#42']" /></div>
        <div data-show="2-" class="mt-2 text-[11px] font-extrabold">{{ __('We have your order') }}</div>
        <div data-show="2" class="mt-1 text-[10px] text-navy-500">{{ __('Keep this page open: we will ring you as soon as it is ready.') }}</div>
        <div data-show="2" class="mt-3 w-full rounded-xl bg-brand-600 py-2 text-[11px] font-extrabold text-white">🔔 {{ __('Test the sound') }}</div>
        <div data-show="4" class="sc-pop mt-3 w-full rounded-xl border border-navy-100 bg-white p-2">
            <div class="text-[10px] font-bold">{{ __('How was it?') }}</div>
            <div class="mt-1 text-xl text-amber-400" dir="ltr">★★★★★</div>
            <div class="mt-1 rounded-lg bg-navy-50 py-1 text-[9px] font-bold">⭐ {{ __('Rate us on Google too') }}</div>
        </div>
    </div>
    {{-- the call --}}
    <div data-show="3" class="sc-fade absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-brand-600 px-3 text-center text-white">
        <span class="text-4xl">🔔</span>
        <div class="text-base font-black">{{ __('Your order is ready!') }}</div>
        <div class="text-4xl font-black" dir="ltr">#42</div>
        <div class="mt-1 w-full rounded-xl bg-white py-2 text-[11px] font-extrabold text-navy-900">✓ {{ __('I picked it up') }}</div>
    </div>
</x-landing.sc-phone>
