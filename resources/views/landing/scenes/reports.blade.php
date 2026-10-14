{{-- Sales add up → busy hours → best sellers → export. --}}
<x-landing.sc-window :title="__('Reports')" class="inset-5">
    <div class="p-3 text-[11px]">
        <div class="grid grid-cols-3 gap-2">
            @foreach ([[__('Sales'), ['1' => '3,940', '2-' => '4,280'], __('EGP')], [__('Orders'), ['1' => '27', '2-' => '29'], ''], [__('Average order'), ['1' => '146', '2-' => '148'], __('EGP')]] as [$label, $vals, $unit])
                <div class="rounded-xl border border-navy-100 p-2.5"><div class="text-navy-500">{{ $label }}</div><b class="text-lg font-black"><x-landing.swap :values="$vals" /></b> <span class="text-navy-500">{{ $unit }}</span></div>
            @endforeach
        </div>
        <div class="mt-2 grid grid-cols-[3fr_2fr] gap-2">
            <div class="rounded-xl border border-navy-100 p-2.5">
                <b>{{ __('Busy hours') }}</b>
                <div class="mt-2 flex h-[110px] items-end gap-1">
                    @foreach ([20, 35, 55, 40, 30, 45, 60, 85, 100, 70, 50, 30] as $i => $h)
                        <span data-on="2-" class="sc-grow flex-1 rounded-t {{ $h === 100 ? 'bg-brand-600' : 'bg-navy-200' }}" style="height: {{ $h }}%; transition-delay: {{ $i * 60 }}ms"></span>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-navy-400" dir="ltr"><span>8</span><span>14</span><span>20</span></div>
            </div>
            <div class="rounded-xl border border-navy-100 p-2.5">
                <b>{{ __('Best sellers') }}</b>
                @foreach ([[__('Latte'), 100], [__('Cheesecake'), 72], [__('Iced latte'), 58], [__('Cookies'), 40]] as $i => [$item, $w])
                    <div data-show="3-" style="transition-delay: {{ $i * 150 }}ms" class="mt-2">
                        <div class="flex justify-between"><span>{{ $item }}</span><b>{{ (int) round($w * .38) }}</b></div>
                        <div class="mt-0.5 h-1.5 rounded-full bg-navy-100"><div class="h-full rounded-full bg-brand-600" style="width: {{ $w }}%"></div></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <span data-on="4" class="absolute top-2 end-3 rounded-lg border border-navy-200 px-2.5 py-1 text-[11px] font-bold [&.is-on]:border-brand-600 [&.is-on]:bg-brand-600 [&.is-on]:text-white">{{ __('Export') }}</span>
    <span data-show="4" class="sc-tap" style="top: 14px; inset-inline-end: 34px"></span>
    <div data-show="4" class="sc-pop absolute end-4 bottom-4 flex items-center gap-2 rounded-xl bg-navy-900 px-3 py-2 text-[11px] font-bold text-white shadow-xl"><x-landing.icon name="file" class="size-4 text-brand-500" /><span dir="ltr">sales-report.xlsx</span> ✓</div>
</x-landing.sc-window>
