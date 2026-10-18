{{-- Advanced reports (feature advanced_reports): plain HTML/CSS charts, every table exportable as CSV. --}}
@php
    $cur = __('EGP');
    $csv = fn (string $table) => route('dash.reports.export.table', array_merge(request()->query(), ['table' => $table]));
    $days = (int) $from->diffInDays($to) + 1;
    $delta = function (?float $d) {
        if ($d === null) {
            return ['—', 'text-navy-500', ''];
        }
        return [($d > 0 ? '▲ +' : ($d < 0 ? '▼ ' : '')) . $d . '%', $d > 0 ? 'text-emerald-700' : ($d < 0 ? 'text-brand-700' : 'text-navy-500'), ''];
    };
    $heat = $x['heatmap'];
@endphp

<div class="mt-8 mb-3 flex flex-wrap items-center justify-between gap-2" id="advanced">
    <h2 class="text-xl font-extrabold">{{ __('Advanced reports') }}</h2>
    @can('settings.manage')
        <a href="{{ route('dash.settings') }}#weekly-report" class="text-sm font-semibold text-brand-700 underline underline-offset-2">
            ✉ {{ __('Weekly report') }}: {{ $weeklyEmail ? __('On') : __('Off') }}
        </a>
    @endcan
</div>

{{-- (7) this period vs the one before --}}
<section class="card" data-comparison>
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="section-title">{{ __('Compared with the previous :n days', ['n' => $days]) }}</h3>
        <a href="{{ $csv('comparison') }}" class="text-xs font-semibold text-navy-500 hover:text-navy-800">⬇ CSV</a>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($x['comparison'] as $key => $c)
            @php
                [$label, $class] = $delta($c['delta']);
                $isMoney = in_array($key, ['revenue', 'average'], true);
            @endphp
            <div class="rounded-xl bg-navy-50 p-3">
                <div class="muted">{{ \App\Services\Reports\AdvancedReport::kpiLabel($key) }}</div>
                <div class="mt-1 text-xl font-extrabold text-navy-900">{{ $isMoney ? money($c['current']) . ' ' . $cur : (int) $c['current'] }}</div>
                <div class="mt-0.5 flex flex-wrap gap-x-2 text-xs">
                    <span class="font-bold {{ $class }}" dir="ltr">{{ $label }}</span>
                    <span class="text-navy-500">{{ __('before: :value', ['value' => $isMoney ? money($c['previous']) : (int) $c['previous']]) }}</span>
                </div>
            </div>
        @endforeach
    </div>
</section>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    {{-- (1) item performance: opened vs ordered --}}
    <section class="card overflow-x-auto xl:col-span-2" data-item-performance>
        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="section-title">{{ __('Opened a lot, ordered rarely') }}</h3>
            <a href="{{ $csv('items') }}" class="text-xs font-semibold text-navy-500 hover:text-navy-800">⬇ CSV · {{ __('all items') }}</a>
        </div>
        <p class="muted mb-3">{{ __('Items guests opened at least :n times in this period, by the share of opens that ended in an order. A better photo, description or price can help.', ['n' => \App\Services\Reports\AdvancedReport::MIN_VIEWS]) }}</p>
        <table class="table-base">
            <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Opened') }}</th><th>{{ __('Orders') }}</th><th class="w-1/3">{{ __('Opened → ordered %') }}</th></tr></thead>
            <tbody>
            @forelse ($x['items']['low'] as $r)
                <tr>
                    <td class="font-semibold">{{ $r['name'] }}</td>
                    <td>{{ $r['views'] }}</td>
                    <td>{{ $r['orders'] }}</td>
                    <td>
                        <div class="flex items-center gap-2">
                            <div class="h-2 flex-1 rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, (int) $r['conversion']) }}%"></div></div>
                            <span class="w-12 text-end text-sm" dir="ltr">{{ $r['conversion'] }}%</span>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-navy-500">{{ __('Not enough item opens yet in this period.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    {{-- (5) loyalty --}}
    @if ($x['loyalty'])
        <section class="card" data-loyalty-stats>
            <h3 class="section-title mb-2">{{ __('Loyalty card') }}</h3>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>{{ __('Cards (all time)') }}</dt><dd class="font-bold">{{ $x['loyalty']['cards'] }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('New cards') }}</dt><dd class="font-bold">{{ $x['loyalty']['new_cards'] }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Stamps given') }}</dt><dd class="font-bold">{{ $x['loyalty']['stamps'] }}</dd></div>
                @if ($x['loyalty']['points'])<div class="flex justify-between"><dt>{{ __('Points earned') }}</dt><dd class="font-bold">{{ $x['loyalty']['points'] }}</dd></div>@endif
                <div class="flex justify-between"><dt>{{ __('Rewards redeemed') }}</dt><dd class="font-bold">{{ $x['loyalty']['rewards_redeemed'] }}</dd></div>
            </dl>
        </section>
    @endif
</div>

{{-- (2) busy hours × weekday heat map: one hue, light → dark --}}
<section class="card mt-4 overflow-x-auto" data-heatmap>
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="section-title">{{ __('Orders by day and hour') }}</h3>
        <a href="{{ $csv('heatmap') }}" class="text-xs font-semibold text-navy-500 hover:text-navy-800">⬇ CSV</a>
    </div>
    <div class="min-w-[44rem]" dir="ltr">
        <div class="grid grid-cols-[5.5rem_repeat(24,minmax(0,1fr))] gap-[2px] text-[10px] text-navy-500">
            <span></span>
            @for ($h = 0; $h < 24; $h++)<span class="text-center">{{ $h % 3 === 0 ? $h : '' }}</span>@endfor
            @foreach ($heat['days'] as $d)
                <span class="truncate pe-2 text-end text-xs font-semibold text-navy-700">{{ \App\Services\Reports\AdvancedReport::dayName($d) }}</span>
                @foreach ($heat['cells'][$d] as $h => $n)
                    @php
                        $level = $heat['max'] > 0 && $n > 0 ? 0.15 + 0.85 * $n / $heat['max'] : 0;
                    @endphp
                    <div class="group relative aspect-square rounded-[3px] {{ $n ? '' : 'bg-navy-50' }}" @if ($n) style="background: color-mix(in srgb, var(--color-brand-600) {{ round($level * 100) }}%, white)" @endif tabindex="0">
                        <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-navy-900 px-2 py-1 text-xs text-white shadow group-hover:block group-focus:block" dir="auto">
                            {{ \App\Services\Reports\AdvancedReport::dayName($d) }} {{ local_time(today()->setHour($h), 'h A') }} · {{ __(':n orders', ['n' => $n]) }}
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>
    <div class="mt-3 flex items-center gap-2 text-xs text-navy-500">
        <span>{{ __('Fewer') }}</span>
        @foreach ([0.15, 0.4, 0.65, 1] as $l)<span class="size-3 rounded-[3px]" style="background: color-mix(in srgb, var(--color-brand-600) {{ $l * 100 }}%, white)"></span>@endforeach
        <span>{{ __('More') }}</span>
        @if ($heat['max'] > 0)<span class="ms-2">({{ __('busiest slot: :n orders', ['n' => $heat['max']]) }})</span>@endif
    </div>
</section>

<div class="mt-4 grid gap-4 xl:grid-cols-2">
    {{-- (3) branches side by side --}}
    @if ($x['branches'])
        <section class="card overflow-x-auto" data-branch-comparison>
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="section-title">{{ __('Branches compared') }}</h3>
                <a href="{{ $csv('branches') }}" class="text-xs font-semibold text-navy-500 hover:text-navy-800">⬇ CSV</a>
            </div>
            <table class="table-base">
                <thead><tr><th>{{ __('Branch') }}</th><th>{{ __('Sales') }}</th><th>{{ __('Orders') }}</th><th>{{ __('Average order') }}</th><th class="w-1/4">{{ __('Share %') }}</th></tr></thead>
                <tbody>
                @foreach ($x['branches'] as $b)
                    <tr>
                        <td class="font-semibold">{{ $b['name'] }}</td><td>{{ money($b['revenue']) }}</td><td>{{ $b['orders'] }}</td><td>{{ money($b['average']) }}</td>
                        <td><div class="flex items-center gap-2"><div class="h-2 flex-1 rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, (int) $b['share']) }}%"></div></div><span class="w-12 text-end text-sm" dir="ltr">{{ $b['share'] }}%</span></div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- (4) sources and order types --}}
    @foreach (['sources' => __('Orders by source'), 'types' => __('Orders by type')] as $key => $title)
        <section class="card" data-split="{{ $key }}">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="section-title">{{ $title }}</h3>
                <a href="{{ $csv($key) }}" class="text-xs font-semibold text-navy-500 hover:text-navy-800">⬇ CSV</a>
            </div>
            @forelse ($x[$key] as $r)
                <div class="mb-2.5">
                    <div class="mb-1 flex justify-between text-sm"><span class="font-semibold text-navy-800">{{ $r['label'] }}</span><span class="text-navy-500">{{ __(':n orders', ['n' => $r['orders']]) }} · {{ money($r['revenue']) }} · <span dir="ltr">{{ $r['share'] }}%</span></span></div>
                    <div class="h-2 rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, (int) $r['share']) }}%"></div></div>
                </div>
            @empty
                <p class="muted">{{ __('No data for this period.') }}</p>
            @endforelse
        </section>
    @endforeach

    {{-- (6) new vs returning guests per week --}}
    <section class="card overflow-x-auto" data-guest-weeks>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="section-title">{{ __('New and returning guests per week') }}</h3>
            <a href="{{ $csv('guests') }}" class="text-xs font-semibold text-navy-500 hover:text-navy-800">⬇ CSV</a>
        </div>
        @php
            $topWeek = max(1, max(array_map(fn ($w) => $w['new'] + $w['returning'], $x['weeks']) ?: [0]));
        @endphp
        <div class="mb-2 flex gap-4 text-xs text-navy-700">
            <span class="flex items-center gap-1"><span class="size-3 rounded-[3px] bg-brand-500"></span>{{ __('New guests') }}</span>
            <span class="flex items-center gap-1"><span class="size-3 rounded-[3px] bg-navy-700"></span>{{ __('Returning guests') }}</span>
        </div>
        <table class="table-base">
            <thead><tr><th>{{ __('Week of') }}</th><th>{{ __('New guests') }}</th><th>{{ __('Returning guests') }}</th><th class="w-2/5"></th></tr></thead>
            <tbody>
            @foreach ($x['weeks'] as $w)
                <tr>
                    <td dir="ltr" class="text-start">{{ \Carbon\Carbon::parse($w['week'])->format('m/d') }}</td><td>{{ $w['new'] }}</td><td>{{ $w['returning'] }}</td>
                    <td><div class="flex h-2 gap-[2px]" title="{{ $w['new'] }} / {{ $w['returning'] }}">
                        @if ($w['new'])<div class="h-full rounded-full bg-brand-500" style="width: {{ round($w['new'] * 100 / $topWeek) }}%"></div>@endif
                        @if ($w['returning'])<div class="h-full rounded-full bg-navy-700" style="width: {{ round($w['returning'] * 100 / $topWeek) }}%"></div>@endif
                    </div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
</div>
