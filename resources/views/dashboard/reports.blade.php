@extends('layouts.dashboard')
@section('title', __('Reports'))

@section('content')
@php
    $methods = ['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet'), 'credit' => __('Wallet balance'), 'corporate' => __('Corporate voucher'), '' => __('Not set')];
    $sources = ['qr' => __('QR menu'), 'pos' => __('POS'), 'callcenter' => __('Call center'), 'whatsapp' => __('WhatsApp'), 'kiosk' => __('Kiosk')];
    $types = ['dine_in' => __('Dine-in'), 'takeaway' => __('Takeaway'), 'delivery' => __('Delivery')];
    $presets = ['today' => __('Today'), 'yesterday' => __('Yesterday'), '7d' => __('Last 7 days')];
    if ($advanced) {
        $presets += ['30d' => __('Last 30 days'), 'month' => __('This month')];
    }
    $maxDay = max(1, max(array_column($report['days'], 'revenue') ?: [0]));
    $maxHour = max(1, max($report['hours']));
    $cur = __('EGP');
@endphp

<div class="dash-head">
    <h1 class="page-title">{{ __('Reports') }}</h1>
    @if ($advanced)
        <div class="dash-head-actions">
            <a href="#advanced" class="btn-ghost" data-reports-advanced-link>{{ __('Advanced') }} ↓</a>
            <a href="{{ route('dash.reports.export', request()->query()) }}" class="btn-ghost">⬇ {{ __('Export orders (CSV)') }}</a>
        </div>
    @endif
</div>
@include('dashboard.partials.area-tabs', ['area' => 'reports', 'current' => 'sales'])

{{-- filters: one row above the charts --}}
<form class="card mb-4 flex flex-wrap items-end gap-2">
    <div class="flex flex-wrap gap-1" role="radiogroup" aria-label="{{ __('Period') }}">
        @foreach ($presets as $key => $label)
            <a href="?{{ http_build_query(array_filter(['range' => $key, 'branch' => $branchId])) }}" @class(['flex min-h-10 items-center rounded-lg px-3 text-sm font-semibold', 'bg-navy-800 text-white' => $preset === $key, 'bg-navy-50 text-navy-700' => $preset !== $key])>{{ $label }}</a>
        @endforeach
    </div>
    @if ($advanced)
        <input type="hidden" name="range" value="custom">
        <input class="field min-h-9 w-auto" type="date" name="from" value="{{ $from->format('Y-m-d') }}">
        <input class="field min-h-9 w-auto" type="date" name="to" value="{{ $to->format('Y-m-d') }}">
        @if ($branches->count() > 1)
            <select class="field min-h-9 w-auto" name="branch">
                <option value="">{{ __('All branches') }}</option>
                @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>@endforeach
            </select>
        @endif
        <button class="btn-primary min-h-9">{{ __('Show') }}</button>
    @else
        <span class="text-xs text-navy-500">{{ \App\Services\Entitlements::current()->upgradeMessage('advanced_reports') }}</span>
    @endif
</form>

{{-- headline numbers: stat tiles, not charts --}}
<div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    @foreach ([
        [__('Sales'), money($report['revenue']) . ' ' . $cur, trim(($report['discounts'] > 0 ? __('after :amount discounts', ['amount' => money($report['discounts'])]) : '') . ($report['refunds']['count'] ? ' · ' . __('after :amount refunds', ['amount' => money($report['refunds']['total'])]) : ''), ' ·') ?: null],
        [__('Orders'), $report['orders'], $report['cancelled'] ? __(':n cancelled', ['n' => $report['cancelled']]) : null],
        [__('Average order'), money($report['average']) . ' ' . $cur, null],
        [__('Not paid yet'), money($report['unpaid']) . ' ' . $cur, null],
    ] as [$label, $value, $sub])
        <div class="stat-card">
            <div class="stat-label">{{ $label }}</div>
            <div class="stat-value">{{ $value }}</div>
            @if ($sub)<div class="mt-0.5 text-xs text-navy-500">{{ $sub }}</div>@endif
        </div>
    @endforeach
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    {{-- daily sales: one series, one hue, bars anchored to the baseline --}}
    <section class="card xl:col-span-2">
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="section-title">{{ __('Sales per day') }}</h2>
            <span class="text-xs text-navy-500">{{ __('Highest: :amount', ['amount' => money($maxDay)]) }} {{ $cur }}</span>
        </div>
        @if (count($report['days']) === 1)
            <p class="py-10 text-center text-navy-500">{{ __('Choose more than one day to see the trend.') }}</p>
        @else
            <div class="flex h-48 items-end gap-0.5 border-b border-navy-200" dir="ltr">
                @foreach ($report['days'] as $day => $d)
                    <div class="group relative flex h-full flex-1 items-end" tabindex="0">
                        <div class="mx-auto w-full max-w-10 rounded-t-[4px] bg-brand-500 group-hover:bg-brand-600 group-focus:bg-brand-600" style="height: {{ max($d['revenue'] > 0 ? 2 : 0, round($d['revenue'] * 100 / $maxDay)) }}%"></div>
                        <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-navy-900 px-2 py-1 text-xs text-white shadow group-hover:block group-focus:block" dir="rtl">
                            <b>{{ \Carbon\Carbon::parse($day)->format('m/d') }}</b> · {{ money($d['revenue']) }} {{ $cur }} · {{ __(':n orders', ['n' => $d['orders']]) }}
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-[11px] text-navy-500" dir="ltr">
                <span>{{ $from->format('m/d') }}</span><span>{{ $to->format('m/d') }}</span>
            </div>
            <details class="mt-2 text-sm">
                <summary class="cursor-pointer text-navy-500">{{ __('Show as table') }}</summary>
                <table class="table-base mt-2">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Orders') }}</th><th>{{ __('Sales') }}</th></tr></thead>
                    <tbody>@foreach ($report['days'] as $day => $d)<tr><td>{{ $day }}</td><td>{{ $d['orders'] }}</td><td>{{ money($d['revenue']) }}</td></tr>@endforeach</tbody>
                </table>
            </details>
        @endif
    </section>

    {{-- busy hours --}}
    <section class="card">
        <h2 class="section-title mb-3">{{ __('Busy hours') }}</h2>
        <div class="flex h-36 items-end gap-0.5 border-b border-navy-200" dir="ltr">
            @foreach ($report['hours'] as $h => $n)
                <div class="group relative flex h-full flex-1 items-end" tabindex="0">
                    <div class="w-full rounded-t-[3px] bg-brand-500 group-hover:bg-brand-600 group-focus:bg-brand-600" style="height: {{ $n ? max(3, round($n * 100 / $maxHour)) : 0 }}%"></div>
                    <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-lg bg-navy-900 px-2 py-1 text-xs text-white group-hover:block group-focus:block" dir="rtl">
                        {{ local_time(today()->setHour($h), 'h A') }} · {{ __(':n orders', ['n' => $n]) }}
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex justify-between text-[11px] text-navy-500" dir="ltr">
            @foreach ([0, 6, 12, 18, 23] as $h)<span>{{ $h }}</span>@endforeach
        </div>
        @php($peak = array_search(max($report['hours']), $report['hours'], true))
        @if (max($report['hours']) > 0)<p class="mt-2 text-sm text-navy-700">{{ __('Busiest hour: :hour', ['hour' => local_time(today()->setHour($peak), 'h A')]) }}</p>@endif
    </section>
</div>

<div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @foreach ([
        [__('Payment methods'), $report['by_method'], $methods],
        [__('Where orders come from'), $report['by_source'], $sources],
        [__('Order type'), $report['by_type'], $types],
    ] as [$title, $rows, $labels])
        <section class="card">
            <h2 class="section-title mb-3">{{ $title }}</h2>
            @php($top = max(1, max(array_column($rows, 'total') ?: [0])))
            @forelse ($rows as $r)
                <div class="mb-2.5">
                    <div class="mb-1 flex justify-between text-sm"><span class="font-semibold text-navy-800">{{ $labels[$r['key']] ?? $r['key'] }}</span><span class="text-navy-500">{{ money($r['total']) }} · {{ $r['count'] }}</span></div>
                    <div class="h-2 rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, round($r['total'] * 100 / $top)) }}%"></div></div>
                </div>
            @empty
                <p class="muted">{{ __('No data for this period.') }}</p>
            @endforelse
            @if ($title === __('Payment methods') && $report['paper_vouchers']['count'] > 0)
                <p class="mt-3 rounded-xl bg-navy-50 p-2 text-sm text-navy-700" data-report-paper-vouchers>
                    {{ __('Corporate vouchers on paper bills: :amount (:n bills), not in the sales above.', ['amount' => money($report['paper_vouchers']['total']), 'n' => $report['paper_vouchers']['count']]) }}
                </p>
            @endif
        </section>
    @endforeach
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <section class="card overflow-x-auto xl:col-span-2">
        <h2 class="section-title mb-3">{{ __('Best sellers') }}</h2>
        <table class="table-base">
            <thead><tr><th>#</th><th>{{ __('Item') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Sales') }}</th></tr></thead>
            <tbody>
            @forelse ($report['top_items'] as $i => $it)
                <tr><td class="text-navy-500">{{ $i + 1 }}</td><td class="font-semibold">{{ $it['name'] }}</td><td>{{ $it['qty'] }}</td><td>{{ money($it['revenue']) }}</td></tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-navy-500">{{ __('No data for this period.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <div class="space-y-4">
        <section class="card">
            <h2 class="section-title mb-2">{{ __('Menu visits') }}</h2>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>{{ __('People who opened the menu') }}</dt><dd class="font-bold">{{ (int) $visits->views }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Table QR scans') }}</dt><dd class="font-bold">{{ (int) $visits->scans }}</dd></div>
            </dl>
            @if ($topTables->isNotEmpty())
                <h3 class="mt-3 mb-1 text-xs font-bold text-navy-500">{{ __('Most scanned tables (all time)') }}</h3>
                <ul class="space-y-1 text-sm">
                    @foreach ($topTables as $t)
                        <li class="flex justify-between"><span>{{ __('Table :name', ['name' => $t->name]) }}</span><span class="font-semibold">{{ $t->scans_count }}</span></li>
                    @endforeach
                </ul>
            @endif
        </section>
        <section class="card">
            <h2 class="section-title mb-2">{{ __('Guests') }}</h2>
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>{{ __('Orders with a mobile number') }}</dt><dd class="font-bold">{{ $report['customers']['with_phone'] }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('New guests') }}</dt><dd class="font-bold">{{ $report['customers']['new'] }}</dd></div>
                <div class="flex justify-between"><dt>{{ __('Returning guests') }}</dt><dd class="font-bold">{{ $report['customers']['returning'] }}</dd></div>
            </dl>
        </section>
        @if ($advanced && !$branchId && count($report['by_branch']) > 1)
            <section class="card">
                <h2 class="section-title mb-3">{{ __('Branches') }}</h2>
                @php($topB = max(1, max(array_column($report['by_branch'], 'total'))))
                @foreach ($report['by_branch'] as $r)
                    <div class="mb-2.5">
                        <div class="mb-1 flex justify-between text-sm"><span class="font-semibold">{{ $r['name'] }}</span><span class="text-navy-500">{{ money($r['total']) }}</span></div>
                        <div class="h-2 rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, round($r['total'] * 100 / $topB)) }}%"></div></div>
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</div>

@if ($delivery !== [])
    {{-- delivery drivers in the range: who delivered what, how fast, and what they took at the door --}}
    <section class="card mt-4 overflow-x-auto" id="delivery">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="section-title">{{ __('Delivery') }}</h2>
            <a href="{{ route('dash.drivers') }}" class="text-sm font-semibold text-brand-700">{{ __('Drivers') }} →</a>
        </div>
        <table class="w-full text-sm">
            <thead class="text-xs text-navy-500">
                <tr>
                    <th class="py-1 text-start font-semibold">{{ __('Driver') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Delivered') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Failed') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Minutes to the door') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Cash at the door') }}</th>
                    <th class="py-1 text-end font-semibold">{{ __('Card / InstaPay / wallet') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($delivery as $r)
                    <tr class="border-t border-navy-50">
                        <td class="py-1.5 font-semibold">{{ $r['name'] }}</td>
                        <td class="py-1.5 text-end" dir="ltr">{{ $r['delivered'] }}</td>
                        <td class="py-1.5 text-end {{ $r['failed'] ? 'text-red-700' : '' }}" dir="ltr">{{ $r['failed'] }}</td>
                        <td class="py-1.5 text-end" dir="ltr">{{ $r['avg_minutes'] ?? '—' }}</td>
                        <td class="py-1.5 text-end" dir="ltr">{{ money($r['cash']) }}</td>
                        <td class="py-1.5 text-end" dir="ltr">{{ money($r['other']) }}</td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-navy-100 font-bold">
                    <td class="py-1.5">{{ __('Total') }}</td>
                    <td class="py-1.5 text-end" dir="ltr">{{ array_sum(array_column($delivery, 'delivered')) }}</td>
                    <td class="py-1.5 text-end" dir="ltr">{{ array_sum(array_column($delivery, 'failed')) }}</td>
                    <td class="py-1.5 text-end" dir="ltr">—</td>
                    <td class="py-1.5 text-end" dir="ltr">{{ money(array_sum(array_column($delivery, 'cash'))) }} {{ $cur }}</td>
                    <td class="py-1.5 text-end" dir="ltr">{{ money(array_sum(array_column($delivery, 'other'))) }} {{ $cur }}</td>
                </tr>
            </tbody>
        </table>
    </section>
@endif

@if ($pager)
    {{-- the guest pager: how long guests waited for the call, then to pick up, and how many had the page open --}}
    @php($pt = $pager['total'])
    <section class="card mt-4 overflow-x-auto" id="pager" data-pager-report>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="section-title">📣 {{ __('Guest pager') }}</h2>
            @can('pager.use')<a href="{{ route('dash.pager') }}" class="text-sm font-semibold text-brand-700">{{ __('Pager station') }} →</a>@endcan
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ([
                [__('Paged orders'), $pt['paged']],
                [__('Average wait for the call'), $pt['wait'] === null ? '—' : __(':n min', ['n' => $pt['wait']])],
                [__('From the call to the pickup'), $pt['pickup'] === null ? '—' : __(':n min', ['n' => $pt['pickup']])],
                [__('Calls'), $pt['calls']],
                [__('Guests with the page open'), $pt['connected'] === null ? '—' : $pt['connected'] . '%'],
            ] as [$label, $value])
                <div class="rounded-2xl bg-navy-50 p-3">
                    <div class="text-xs font-semibold text-navy-500">{{ $label }}</div>
                    <div class="mt-1 text-xl font-extrabold" dir="ltr">{{ $value }}</div>
                </div>
            @endforeach
        </div>
        @if (count($pager['days']) > 1)
            <table class="mt-4 w-full text-sm">
                <thead class="text-xs text-navy-500">
                    <tr>
                        <th class="py-1 text-start font-semibold">{{ __('Day') }}</th>
                        <th class="py-1 text-end font-semibold">{{ __('Paged orders') }}</th>
                        <th class="py-1 text-end font-semibold">{{ __('Average wait for the call') }}</th>
                        <th class="py-1 text-end font-semibold">{{ __('From the call to the pickup') }}</th>
                        <th class="py-1 text-end font-semibold">{{ __('Calls') }}</th>
                        <th class="py-1 text-end font-semibold">{{ __('Guests with the page open') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pager['days'] as $d)
                        <tr class="border-t border-navy-50">
                            <td class="py-1.5 font-semibold" dir="ltr">{{ $d['day'] }}</td>
                            <td class="py-1.5 text-end" dir="ltr">{{ $d['paged'] }}</td>
                            <td class="py-1.5 text-end" dir="ltr">{{ $d['wait'] ?? '—' }}</td>
                            <td class="py-1.5 text-end" dir="ltr">{{ $d['pickup'] ?? '—' }}</td>
                            <td class="py-1.5 text-end" dir="ltr">{{ $d['calls'] }}</td>
                            <td class="py-1.5 text-end" dir="ltr">{{ $d['connected'] === null ? '—' : $d['connected'] . '%' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p class="mt-3 text-xs text-navy-500">{{ __('Minutes. The wait runs from the order (or the station ticket) to ready or the first call; the pickup is when the guest tapped “I picked it up”.') }}</p>
    </section>
@endif

@if ($extra)
    @include('dashboard.reports-advanced', ['x' => $extra])
@else
    <section class="card mt-4 flex flex-wrap items-center justify-between gap-3 bg-brand-50" data-advanced-locked>
        <div>
            <h2 class="section-title">{{ __('Advanced reports') }}</h2>
            <p class="text-sm text-navy-700">{{ __('Which items guests open but do not order, busy hours by weekday, branches side by side, period comparison, CSV exports and a weekly email.') }}</p>
        </div>
        @can('billing.manage')<a href="{{ route('dash.billing') }}" class="btn-primary">{{ __('Unlock advanced reports') }}</a>@endcan
    </section>
@endif
@endsection
