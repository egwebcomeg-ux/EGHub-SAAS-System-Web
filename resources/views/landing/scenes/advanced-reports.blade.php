{{-- Item performance (opened vs ordered) → busy-hours heat map → this week against last → the weekly email. --}}
@php
    $tabs = [__('Item performance'), __('Heat map'), __('Compare'), __('Weekly email')];
    $days = [__('Sat'), __('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri')];
    // a fixed, plausible week: quiet mornings, busy evenings, Thursday and Friday the busiest
    $heat = [];
    foreach ($days as $d => $_) {
        foreach (range(0, 11) as $h) {
            $base = [1, 1, 2, 2, 3, 3, 2, 3, 4, 5, 5, 3][$h];
            $heat[$d][$h] = min(5, $base + ($d >= 5 ? 1 : 0) - ($d === 2 && $h < 6 ? 1 : 0));
        }
    }
@endphp
<x-landing.sc-window :title="__('Advanced reports')" class="inset-5">
    <div class="flex gap-1 border-b border-navy-100 px-3 pt-2 text-[11px] font-bold">
        @foreach ($tabs as $i => $tab)
            <span data-on="{{ $i + 1 }}" class="rounded-t-lg border-b-2 border-transparent px-2.5 py-1.5 text-navy-500 [&.is-on]:border-brand-600 [&.is-on]:text-navy-900">{{ $tab }}</span>
        @endforeach
    </div>
    <div class="relative h-[calc(100%-38px)] text-[11px]">
        {{-- 1 --}}
        <div data-show="1" class="sc-fade absolute inset-0 p-3">
            <div class="grid grid-cols-[1.2fr_2fr_auto] gap-x-3 gap-y-2 text-navy-500">
                <b>{{ __('Item') }}</b><b>{{ __('Opened · ordered') }}</b><b>{{ __('Ordered') }}</b>
                @foreach ([[__('Latte'), 100, 78], [__('Cheesecake'), 92, 34], [__('Iced latte'), 70, 52], [__('Brownies'), 48, 30]] as [$item, $open, $ordered])
                    <span @class(['font-bold text-navy-900', 'text-brand-700' => $ordered < 40])>{{ $item }}</span>
                    <span class="relative h-4 self-center rounded bg-navy-100" style="width: {{ $open }}%"><span class="absolute inset-y-0 start-0 rounded bg-brand-600" style="width: {{ round($ordered / $open * 100) }}%"></span></span>
                    <b class="text-navy-900">{{ round($ordered / $open * 100) }}%</b>
                @endforeach
            </div>
            <div class="mt-3 flex items-center gap-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-amber-900"><x-landing.icon name="sparkles" class="size-4" />{{ __('Cheesecake is opened a lot but ordered less: try a better photo or price.') }}</div>
        </div>
        {{-- 2 --}}
        <div data-show="2" class="sc-fade absolute inset-0 p-3">
            <div class="grid grid-cols-[32px_repeat(12,minmax(0,1fr))] gap-[3px]">
                @foreach ($days as $d => $day)
                    <span class="text-[10px] leading-[18px] text-navy-500">{{ $day }}</span>
                    @foreach ($heat[$d] as $h => $v)
                        <span @if ($d === 6 && $h === 10) data-on="2" @endif class="h-[18px] rounded-[3px] [&.is-on]:ring-2 [&.is-on]:ring-navy-900" style="background: rgba(232, 100, 44, {{ 0.08 + $v * 0.18 }})"></span>
                    @endforeach
                @endforeach
                <span></span>
                @foreach (range(0, 11) as $h)
                    <span class="text-center text-[9px] text-navy-400" dir="ltr">{{ $h % 3 === 0 ? 10 + $h : '' }}</span>
                @endforeach
            </div>
            <div class="mt-2 text-navy-600"><b class="text-navy-900">{{ __('Friday, 8–9 pm') }}</b> · {{ __('the busiest hour of the week') }}</div>
        </div>
        {{-- 3 --}}
        <div data-show="3" class="sc-fade absolute inset-0 p-3">
            <div class="flex gap-3 text-[10px] font-bold"><span class="flex items-center gap-1"><i class="size-2.5 rounded-sm bg-brand-600"></i>{{ __('This week') }}</span><span class="flex items-center gap-1"><i class="size-2.5 rounded-sm bg-navy-300"></i>{{ __('Last week') }}</span></div>
            <div class="mt-2 flex h-[150px] items-end gap-3">
                @foreach ($days as $d => $day)
                    <div class="flex flex-1 flex-col items-center gap-1">
                        <div class="flex h-[130px] w-full items-end justify-center gap-0.5">
                            <span data-on="3" class="sc-grow w-2.5 rounded-t bg-navy-300" style="height: {{ [40, 45, 35, 42, 50, 70, 80][$d] }}%; transition-delay: {{ $d * 50 }}ms"></span>
                            <span data-on="3" class="sc-grow w-2.5 rounded-t bg-brand-600" style="height: {{ [46, 44, 41, 48, 55, 82, 92][$d] }}%; transition-delay: {{ $d * 50 + 120 }}ms"></span>
                        </div>
                        <span class="text-[10px] text-navy-500">{{ $day }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        {{-- 4 --}}
        <div data-show="4" class="sc-fade absolute inset-0 grid place-items-center p-3">
            <div class="w-[320px] rounded-2xl border border-navy-100 bg-white p-4 shadow-xl">
                <div class="flex items-center gap-2"><span class="grid size-8 place-items-center rounded-lg bg-navy-900 text-white"><x-landing.icon name="file" class="size-4" /></span>
                    <div><b class="block">{{ __('Rosto · your week in numbers') }}</b><span class="text-navy-500">{{ __('Monday, 9:00 am') }}</span></div></div>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    @foreach ([[__('Sales'), '27,450'], [__('Orders'), '186'], [__('Busiest'), __('Fri')]] as [$k, $v])
                        <div class="rounded-lg bg-navy-50 p-1.5"><div class="text-navy-500">{{ $k }}</div><b class="text-sm">{{ $v }}</b></div>
                    @endforeach
                </div>
                <div class="mt-2 rounded-lg bg-navy-50 px-2 py-1 text-navy-600" dir="ltr">items.csv · heatmap.csv</div>
            </div>
        </div>
    </div>
</x-landing.sc-window>
