{{-- Rosto listed among Cairo's cafes for free → with the add-on it shows first with a badge → a visitor opens its page and menu. --}}
@php
    // Rosto and Sofra are the demo brands; the other places stay as blank rows
    $others = [[null, null, '#9aa6bd'], [__('Sofra'), __('Restaurant · Sheikh Zayed'), '#7a1f2b'], [null, null, '#b9c2d3']];
@endphp
<x-landing.sc-window title="egmenu.app/restaurants/cairo" class="inset-5">
    <div class="p-3 text-[11px]">
        <div class="flex items-center justify-between"><b class="text-sm">{{ __('Cafes and restaurants in Cairo') }}</b><span class="text-navy-500">{{ __('Cafes') }} · {{ __('Restaurants') }}</span></div>
        {{-- rows are 52px apart by 6px: Rosto slides from the 4th row to the top while the others move down one row --}}
        <div class="relative mt-2 h-[226px]">
            <div data-on="2-" class="space-y-1.5 [&.is-on]:translate-y-[58px]" style="transition: translate .7s cubic-bezier(.2, .8, .2, 1)">
            @foreach ($others as [$name, $kind, $tone])
                <div class="flex h-[52px] items-center gap-2.5 rounded-xl border border-navy-100 p-2">
                    <span class="grid size-9 place-items-center rounded-lg font-black text-white" style="background: {{ $tone }}">{{ $name ? mb_substr($name, 0, 1) : '' }}</span>
                    @if ($name)
                        <div class="flex-1"><b class="text-xs">{{ $name }}</b><div class="text-navy-500">{{ $kind }}</div></div>
                    @else
                        <div class="flex-1 space-y-1.5"><span class="block h-2.5 w-24 rounded bg-navy-100"></span><span class="block h-2 w-36 rounded bg-navy-50"></span></div>
                    @endif
                </div>
            @endforeach
            </div>
            <div data-on="2-" class="absolute inset-x-0 top-0 flex h-[52px] translate-y-[174px] items-center gap-2.5 rounded-xl border-2 border-navy-100 bg-white p-2 [&.is-on]:translate-y-0 [&.is-on]:border-amber-400 [&.is-on]:bg-amber-50"
                 style="transition: translate .7s cubic-bezier(.2, .8, .2, 1), background-color .35s ease, border-color .35s ease">
                <span class="grid size-9 place-items-center rounded-lg bg-[#4a3426] font-black text-white">R</span>
                <div class="flex-1"><b class="text-xs">{{ __('Rosto') }}</b><div class="text-navy-500">{{ __('Cafe · Zamalek') }}</div></div>
                <span data-show="2-" class="sc-pop flex items-center gap-1 rounded-full bg-amber-400 px-2 py-0.5 font-bold text-navy-900"><x-landing.icon name="star" class="size-3" />{{ __('Featured') }}</span>
                <span data-show="3" class="sc-tap" style="top: 26px; inset-inline-start: 60px"></span>
            </div>
        </div>
    </div>
    {{-- 3: Rosto's page --}}
    <div data-show="3" class="sc-slide absolute inset-y-0 end-0 w-[250px] border-s border-navy-100 bg-white p-3 text-[11px] shadow-[-12px_0_30px_-20px_rgba(20,31,56,.5)]">
        <div class="h-16 rounded-xl bg-[#4a3426]"></div>
        <b class="mt-2 block text-sm">{{ __('Rosto') }}</b>
        <div class="text-navy-500">{{ __('Cafe · Zamalek') }} · {{ __('Open now') }}</div>
        <div class="mt-2 rounded-lg bg-navy-50 px-2 py-1 text-navy-600" dir="ltr">egmenu.app/rosto</div>
        <div class="mt-3 rounded-lg bg-brand-600 py-1.5 text-center font-bold text-white">{{ __('View the menu') }}</div>
    </div>
</x-landing.sc-window>
