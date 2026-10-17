{{-- A ticket arrives → start → the note stands out while the timer runs → ready. --}}
<div class="absolute inset-5 overflow-hidden rounded-2xl bg-[#061a2e] p-3 text-white shadow-[0_30px_50px_-30px_rgba(20,31,56,.8)]">
    <div class="flex items-center justify-between text-xs font-extrabold">
        <span class="flex items-center gap-2"><span data-on="1" class="sc-ring grid size-6 place-items-center rounded-md bg-white/10 [&.is-on]:bg-brand-600"><x-landing.icon name="bell" class="size-3.5" /></span>{{ __('Bar screen') }}</span>
        <span class="rounded-md bg-white/10 px-2 py-0.5 text-[10px]">{{ __('Full screen') }}</span>
    </div>
    <div class="mt-3 grid grid-cols-3 gap-3 text-[12px]">
        {{-- the new ticket --}}
        <div data-show="1-" class="overflow-hidden rounded-xl bg-white text-navy-900">
            <div data-on="2-" class="flex items-center justify-between bg-sky-600 px-2.5 py-1.5 font-black text-white [&.is-on]:bg-amber-500" >
                <span>#62</span><span class="text-[10px]">{{ __('Table 1') }}</span>
                <span><x-landing.swap :values="['1' => '0′', '2' => '1′', '3' => '3′', '4' => '4′']" /></span>
            </div>
            <div data-on="4" class="space-y-1 p-2.5 transition-opacity [&.is-on]:opacity-60">
                <div><b>2×</b> {{ __('Latte') }}</div>
                <div class="text-[10px] text-navy-500">{{ __('Large') }}</div>
                <div><b>1×</b> {{ __('Cheesecake') }}</div>
                <div data-on="3-" class="rounded-md bg-amber-100 px-2 py-1 text-[11px] font-bold text-amber-900 [&.is-on]:ring-2 [&.is-on]:ring-amber-400">⚠ {{ __('no sugar') }}</div>
            </div>
            <div class="grid grid-cols-2 gap-1.5 p-2 pt-0 text-[11px] font-bold">
                <span data-on="2" class="rounded-lg bg-sky-50 py-1.5 text-center text-sky-800 [&.is-on]:bg-sky-600 [&.is-on]:text-white">{{ __('Start') }}</span>
                <span data-on="4" class="rounded-lg bg-emerald-600 py-1.5 text-center text-white [&.is-on]:bg-emerald-800">{{ __('Ready') }} ✓</span>
            </div>
            <span data-show="2" class="sc-tap" style="bottom: 22px; inset-inline-start: 40px"></span>
            <span data-show="4" class="sc-tap" style="bottom: 22px; inset-inline-end: 40px"></span>
        </div>
        @foreach ([['#59', __('Table 4'), '6′', 'bg-amber-500', [['2×', __('Caramel frappe')]], __('extra cream')], ['#58', __('Takeaway'), '12′', 'bg-red-500', [['1×', __('Cappuccino')], ['1×', __('Brownies')]], null]] as [$no, $where, $mins, $tone, $lines, $note])
            <div class="overflow-hidden rounded-xl bg-white text-navy-900">
                <div class="flex items-center justify-between {{ $tone }} px-2.5 py-1.5 font-black text-white"><span>{{ $no }}</span><span class="text-[10px]">{{ $where }}</span><span>{{ $mins }}</span></div>
                <div class="space-y-1 p-2.5">
                    @foreach ($lines as [$q, $l])<div><b>{{ $q }}</b> {{ $l }}</div>@endforeach
                    @if ($note)<div class="rounded-md bg-amber-100 px-2 py-1 text-[11px] font-bold text-amber-900">⚠ {{ $note }}</div>@endif
                </div>
                <div class="p-2 pt-0"><span class="block rounded-lg bg-emerald-600 py-1.5 text-center text-[11px] font-bold text-white">{{ __('Ready') }} ✓</span></div>
            </div>
        @endforeach
    </div>
    <div data-show="4" class="sc-pop absolute start-1/2 bottom-4 flex -translate-x-1/2 items-center gap-2 rounded-full bg-emerald-500 px-4 py-2 text-[12px] font-bold text-white shadow-lg rtl:translate-x-1/2"><x-landing.icon name="check" class="size-4" />{{ __('Table 1 is ready') }}</div>
</div>
