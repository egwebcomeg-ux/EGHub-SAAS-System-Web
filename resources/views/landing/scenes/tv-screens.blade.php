{{-- Pick the sections → the TV shows them → change a price → the TV follows by itself. --}}
<x-landing.sc-window :title="__('TV screens')" class="top-14 start-6 h-[260px] w-[210px]">
    <div class="space-y-1.5 p-3 text-[11px]">
        <b class="block">{{ __('Above the cashier') }}</b>
        @foreach ([[__('Hot coffee'), true], [__('Cold drinks'), true], [__('Desserts'), false]] as $i => [$cat, $on])
            <div class="flex items-center gap-2 rounded-lg bg-navy-50 px-2 py-1.5">
                <span @if ($on) data-on="1-" style="transition-delay: {{ $i * 250 }}ms" @endif class="grid size-4 place-items-center rounded border border-navy-300 bg-white text-white [&.is-on]:border-brand-600 [&.is-on]:bg-brand-600"><x-landing.icon name="check" class="size-3" /></span>{{ $cat }}
            </div>
        @endforeach
        <div data-show="3-" class="mt-2 rounded-lg border border-brand-400 px-2 py-1.5 ring-2 ring-brand-100">
            <div class="text-navy-500">{{ __('Latte') }}</div><b>70 {{ __('EGP') }}</b>
        </div>
        <span data-show="3" class="sc-tap" style="bottom: 30px; inset-inline-start: 70px"></span>
    </div>
</x-landing.sc-window>

{{-- the TV --}}
<div class="absolute top-10 end-6 w-[390px]">
    <div class="rounded-xl bg-[#0d1322] p-2 pb-3 shadow-[0_30px_50px_-30px_rgba(20,31,56,.8)]">
        <div class="relative h-[208px] overflow-hidden rounded bg-[#141f38] text-white">
            <div data-show="1" class="sc-fade absolute inset-0 grid place-items-center text-[11px] text-white/50" dir="ltr">rosto.egmenu.app/tv/…</div>
            <div data-show="2-" class="sc-fade grid h-full grid-cols-2 gap-4 p-4">
                @foreach ([[__('Hot coffee'), [[__('Espresso'), '45'], [__('Latte'), null], [__('Cappuccino'), '65'], [__('Mocha'), '75']]], [__('Cold drinks'), [[__('Iced latte'), '75'], [__('Caramel frappe'), '85'], [__('Lemon mint'), '50']]]] as [$cat, $rows])
                    <div>
                        <div class="text-[15px] font-black text-brand-500">{{ $cat }}</div>
                        @foreach ($rows as [$item, $price])
                            <div class="mt-1.5 flex items-baseline justify-between gap-2 text-[12px]">
                                <b>{{ $item }}</b>
                                @if ($price === null)
                                    <b data-on="4" class="sc-flash rounded px-1 text-brand-500"><x-landing.swap :values="['1-3' => '65', '4-' => '70']" /></b>
                                @else
                                    <b class="text-brand-500">{{ $price }}</b>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="mx-auto h-6 w-24 bg-gradient-to-b from-[#0d1322] to-[#2a3654]"></div>
    <div class="mx-auto h-1.5 w-40 rounded-full bg-[#2a3654]"></div>
    <div data-show="4" class="sc-pop absolute -top-3 end-4 rounded-full bg-emerald-600 px-3 py-1 text-[11px] font-bold text-white shadow-lg">{{ __('Updated by itself') }}</div>
</div>
