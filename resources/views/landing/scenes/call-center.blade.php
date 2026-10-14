{{-- The phone rings → the number finds Karim → items → zone and fee → to the kitchen and out. --}}
<x-landing.sc-window :title="__('Call center')" class="top-6 start-6 end-6 h-[350px]">
    <div class="flex h-full text-[11px]">
        <div class="w-[230px] space-y-2 border-e border-navy-100 p-3">
            <div class="flex gap-1.5"><div class="flex-1 rounded-lg border border-navy-200 px-2 py-1.5 font-bold" dir="ltr">0109 988 7766</div><span class="rounded-lg bg-brand-600 px-2.5 py-1.5 font-bold text-white">{{ __('Search') }}</span></div>
            <div data-show="1-" style="transition-delay: 700ms" class="rounded-xl bg-navy-50 p-2.5">
                <b class="text-xs">{{ __('Karim') }}</b> <span class="text-navy-500">· {{ __(':n orders', ['n' => 7]) }}</span>
                <div class="mt-1 flex items-start gap-1 text-navy-600"><x-landing.icon name="flag" class="mt-0.5 size-3 shrink-0 text-brand-600" />{{ __('Nasr City — 15 Abbas El Akkad St.') }}</div>
            </div>
            <div data-show="3-" class="rounded-xl border border-brand-300 bg-brand-50 p-2.5">
                <div class="flex justify-between"><b>{{ __('Nasr City') }}</b><span>{{ __('Fee') }} <b>25</b></span></div>
                <div class="mt-0.5 text-navy-500">{{ __('Minimum order :amount EGP', ['amount' => 100]) }}</div>
            </div>
            <div class="flex justify-between border-t border-navy-100 pt-2 font-extrabold"><span>{{ __('Total') }}</span><span><x-landing.swap :values="['1' => '0', '2' => '180', '3-' => '205']" /> {{ __('EGP') }}</span></div>
            <div data-on="4-" class="rounded-lg bg-brand-600 py-1.5 text-center font-bold text-white [&.is-on]:bg-emerald-600"><x-landing.swap :values="['1-3' => __('Place order'), '4-' => __('Sent to the kitchen') . ' ✓']" /></div>
        </div>
        <div class="flex-1 p-3">
            <div class="grid grid-cols-3 gap-1.5">
                @foreach ([[__('Latte'), 65], [__('Mocha'), 75], [__('Cheesecake'), 90], [__('Brownies'), 70], [__('Butter croissant'), 45], [__('Cookies'), 35]] as $i => [$item, $price])
                    <div @if ($i === 2) data-on="2" @endif class="rounded-lg border border-navy-100 p-1.5 [&.is-on]:border-brand-500 [&.is-on]:bg-brand-50"><b class="block truncate">{{ $item }}</b><span class="font-bold text-brand-700">{{ $price }}</span></div>
                @endforeach
            </div>
            <div data-show="2-" class="mt-3 rounded-lg bg-navy-50 p-2"><div class="flex justify-between"><span>2× {{ __('Cheesecake') }}</span><b>180</b></div></div>
            <span data-show="2" class="sc-tap" style="top: 76px; inset-inline-end: 50px"></span>
            <div data-show="4" class="sc-pop mt-3 flex items-center gap-2 rounded-xl bg-navy-900 p-2.5 text-white"><x-landing.icon name="scooter" class="size-5 text-brand-500" /><span><b>#64 · {{ __('On the way') }}</b><span class="block text-white/60">{{ __('Karim') }} · {{ __('Nasr City') }}</span></span></div>
        </div>
    </div>
</x-landing.sc-window>
{{-- incoming call --}}
<div data-show="1" class="sc-pop absolute top-3 end-10 z-10 flex items-center gap-2 rounded-2xl bg-emerald-600 px-3 py-2 text-[12px] font-bold text-white shadow-xl">
    <span class="sc-ring is-on grid size-7 place-items-center rounded-full bg-white/20"><x-landing.icon name="phone" class="size-4" /></span><span dir="ltr">0109 988 7766</span>
</div>
