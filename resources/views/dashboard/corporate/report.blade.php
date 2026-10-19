@extends('layouts.dashboard')
@section('title', __('Vouchers report'))

@section('content')
@php
    $k = $r['kpis'];
    $cur = __('EGP');
    $presets = ['today' => __('Today'), 'week' => __('This week'), 'month' => __('This month'), 'custom' => __('Custom range')];
    $query = array_filter(request()->only(['range', 'from', 'to', 'contract', 'branch', 'cashier', 'mode']));
    $paidStyles = ['paid' => 'bg-emerald-100 text-emerald-800', 'partial' => 'bg-amber-100 text-amber-800', 'unpaid' => 'bg-red-100 text-red-700'];
    $maxDay = max(1, max($r['days'] ?: [0]));
@endphp
<x-dash.page-header :title="__('Vouchers report')" :help="__('What the companies\' vouchers brought in and what you still owe their staff. Cancelled orders and cancelled paper bills are left out.')">
    <x-slot:actions>
        <a href="{{ route('dash.corporate.report.export', $query + ['what' => 'summary', 'format' => 'xlsx']) }}" class="btn-primary" data-report-export>Excel</a>
        <a href="{{ route('dash.corporate.report.export', $query + ['what' => 'summary', 'format' => 'csv']) }}" class="btn-ghost">CSV</a>
        <a href="{{ route('dash.corporate.report.export', $query + ['what' => 'uses', 'format' => 'xlsx']) }}" class="btn-ghost" data-report-uses>{{ __('Every use') }} · Excel</a>
        <a href="{{ route('dash.corporate.report.export', $query + ['what' => 'uses', 'format' => 'csv']) }}" class="btn-ghost">{{ __('Every use') }} · CSV</a>
        @can('contracts.manage')<a href="{{ route('dash.corporate') }}" class="btn-ghost">{{ __('Corporate contracts') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>

<form class="card mb-4 space-y-3" data-report-filters>
    <div class="flex flex-wrap gap-2">
        @foreach ($presets as $key => $label)
            <label class="flex min-h-11 cursor-pointer items-center rounded-xl border border-navy-200 px-4 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800">
                <input type="radio" name="range" value="{{ $key }}" class="sr-only" @checked($preset === $key) onchange="this.form.requestSubmit()">{{ $label }}
            </label>
        @endforeach
    </div>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
        <label class="block"><span class="field-label">{{ __('From') }}</span><input class="field" type="date" name="from" value="{{ $report->from->format('Y-m-d') }}" onchange="this.form.range.value='custom'"></label>
        <label class="block"><span class="field-label">{{ __('To') }}</span><input class="field" type="date" name="to" value="{{ $report->to->format('Y-m-d') }}" onchange="this.form.range.value='custom'"></label>
        <label class="block"><span class="field-label">{{ __('Company') }}</span>
            <select class="field" name="contract"><option value="">{{ __('All') }}</option>
                @foreach ($contracts as $c)<option value="{{ $c->id }}" @selected($report->contractId === $c->id)>{{ $c->name }}</option>@endforeach
            </select></label>
        @if ($branches->count() > 1)
            <label class="block"><span class="field-label">{{ __('Branch') }}</span>
                <select class="field" name="branch"><option value="">{{ __('All branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($report->branchId === $b->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach
                </select></label>
        @endif
        <label class="block"><span class="field-label">{{ __('Cashier') }}</span>
            <select class="field" name="cashier"><option value="">{{ __('All') }}</option>
                @foreach ($cashiers as $u)<option value="{{ $u->id }}" @selected($report->cashierId === $u->id)>{{ $u->name }}</option>@endforeach
            </select></label>
        <label class="block"><span class="field-label">{{ __('Mode') }}</span>
            <select class="field" name="mode"><option value="">{{ __('All') }}</option>
                @foreach (\App\Models\CorporateContract::MODES as $key => $_)<option value="{{ $key }}" @selected($report->mode === $key)>{{ \App\Models\CorporateContract::pick(\App\Models\CorporateContract::MODES, $key) }}</option>@endforeach
            </select></label>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <button class="btn-primary">{{ __('Show') }}</button>
        <span class="text-sm text-navy-500">{{ local_time($report->from, 'Y/m/d') }} – {{ local_time($report->to, 'Y/m/d') }}</span>
    </div>
</form>

<div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-report-kpis>
    @foreach ([
        ['issued', __('Value issued'), money($k['issued']), __(':n vouchers', ['n' => $k['issued_count']]), ''],
        ['redeemed', __('Value redeemed'), money($k['redeemed']), __(':n uses', ['n' => $k['uses']]) . ($k['paper'] > 0 ? ' · ' . __(':amount on paper bills', ['amount' => money($k['paper'])]) : ''), 'text-brand-700'],
        ['outstanding', __('Still outstanding'), money($k['outstanding']), __('owed to the staff, as of today'), 'text-navy-900'],
        ['lost', __('Lost (one use)'), money($k['lost']), null, 'text-navy-500'],
        ['expired', __('Expired unused'), money($k['expired']), __(':n vouchers', ['n' => $k['expired_count']]), 'text-navy-500'],
        ['uses', __('Uses'), $k['uses'], null, ''],
        ['vouchers', __('Vouchers used / not yet'), $k['used_vouchers'] . ' / ' . $k['unused_vouchers'], null, ''],
        ['exceptions', __('Exceptions approved'), $k['exceptions'], money($k['exceptions_value']), $k['exceptions'] ? 'text-amber-700' : ''],
    ] as [$key, $label, $value, $sub, $tone])
        <div class="stat-card" data-kpi="{{ $key }}">
            <div class="stat-label">{{ $label }}</div>
            <div class="stat-value {{ $tone }}">{{ $value }}</div>
            @if ($sub)<div class="mt-0.5 text-xs text-navy-500">{{ $sub }}</div>@endif
        </div>
    @endforeach
</div>

{{-- value redeemed per day: one series, plain SVG bars on a baseline --}}
<section class="card mt-4">
    <div class="mb-3 flex items-baseline justify-between gap-2">
        <h2 class="section-title">{{ __('Redeemed per day') }}</h2>
        <span class="text-xs text-navy-500">{{ __('Highest: :amount', ['amount' => money($maxDay)]) }} {{ $cur }}</span>
    </div>
    @if (array_sum($r['days']) <= 0)
        <p class="py-8 text-center text-navy-500" data-chart-empty>{{ __('No vouchers were redeemed in this period.') }}</p>
    @else
        @php($n = count($r['days']))
        <svg viewBox="0 0 {{ $n * 10 }} 100" preserveAspectRatio="none" class="h-44 w-full border-b border-navy-200" dir="ltr" role="img" aria-label="{{ __('Redeemed per day') }}" data-chart>
            @foreach ($r['days'] as $day => $value)
                @php($h = $value > 0 ? max(1.5, round($value * 96 / $maxDay, 2)) : 0)
                <rect x="{{ $loop->index * 10 + 1.5 }}" y="{{ 100 - $h }}" width="7" height="{{ $h }}" rx="1" class="fill-brand-500"><title>{{ $day }} · {{ money($value) }} {{ $cur }}</title></rect>
            @endforeach
        </svg>
        <div class="mt-1 flex justify-between text-[11px] text-navy-500" dir="ltr"><span>{{ $report->from->format('m/d') }}</span><span>{{ $report->to->format('m/d') }}</span></div>
        <details class="mt-2 text-sm"><summary class="cursor-pointer text-navy-500">{{ __('Show as table') }}</summary>
            <table class="table-base mt-2"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Value redeemed') }}</th></tr></thead>
                <tbody>@foreach ($r['days'] as $day => $value)@if ($value > 0)<tr><td>{{ $day }}</td><td>{{ money($value) }}</td></tr>@endif @endforeach</tbody></table>
        </details>
    @endif
</section>

<section class="card mt-4 overflow-x-auto">
    <h2 class="section-title mb-3">{{ __('Per contract') }}</h2>
    <table class="table-base" data-report-contracts>
        <thead><tr><th>{{ __('Company') }}</th><th>{{ __('Vouchers') }}</th><th>{{ __('Issued value') }}</th><th>{{ __('Redeemed') }}</th><th>{{ __('Remaining') }}</th><th>{{ __('Lost (one use)') }}</th><th>{{ __('Expired') }}</th><th>{{ __('Usage') }}</th><th>{{ __('Payment') }}</th></tr></thead>
        <tbody>
        @forelse ($r['contracts'] as $c)
            <tr data-report-contract="{{ $c['contract']->id }}">
                <td class="font-bold">@can('contracts.manage')<a class="text-brand-700 underline-offset-2 hover:underline" href="{{ route('dash.corporate.show', $c['contract']) }}">{{ $c['contract']->name }}</a> · <a class="text-xs text-navy-500 hover:underline" href="{{ route('dash.corporate.statement', $c['contract']) }}">{{ __('Statement') }}</a>@else{{ $c['contract']->name }}@endcan</td>
                <td>{{ $c['vouchers'] }}</td>
                <td>{{ money($c['issued']) }}</td>
                <td class="font-bold">{{ money($c['redeemed']) }}</td>
                <td>{{ money($c['remaining']) }}</td>
                <td>{{ money($c['lost']) }}</td>
                <td>{{ money($c['expired']) }}</td>
                <td class="min-w-28"><div class="flex items-center gap-2"><div class="h-2 flex-1 rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500" style="width: {{ $c['usage'] }}%"></div></div><span class="text-xs">{{ $c['usage'] }}%</span></div></td>
                <td><span class="badge {{ $paidStyles[$c['contract']->paid_status] ?? '' }}">{{ $c['contract']->paidLabel() }}</span></td>
            </tr>
        @empty
            <tr><td colspan="9" class="py-8 text-center text-navy-500">{{ __('No contracts yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="mt-2 text-xs text-navy-500">{{ __('Redeemed and lost are for the period; remaining, expired and usage are up to today.') }}</p>
</section>

<div class="mt-4 grid gap-4 md:grid-cols-2">
    @foreach ([[__('By branch'), $r['branches'], 'branches'], [__('By cashier'), $r['cashiers'], 'cashiers']] as [$title, $rows, $key])
        <section class="card" data-report-{{ $key }}>
            <h2 class="section-title mb-3">{{ $title }}</h2>
            @php($top = max(1, max(array_column($rows, 'amount') ?: [0])))
            @forelse ($rows as $row)
                <div class="mb-2.5">
                    <div class="mb-1 flex justify-between gap-2 text-sm"><span class="font-semibold text-navy-800">{{ $row['name'] }}</span><span class="text-navy-500">{{ money($row['amount']) }} · {{ __(':n uses', ['n' => $row['uses']]) }}</span></div>
                    <div class="h-2 rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500" style="width: {{ max(2, round($row['amount'] * 100 / $top)) }}%"></div></div>
                </div>
            @empty
                <p class="muted">{{ __('No data for this period.') }}</p>
            @endforelse
        </section>
    @endforeach
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-2">
    <section class="card overflow-x-auto" data-report-expiring>
        <h2 class="section-title mb-3">⏳ {{ __('Ending soon (next 30 days)') }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Company') }}</th><th>{{ __('Vouchers') }}</th><th>{{ __('Value left') }}</th><th>{{ __('First ends') }}</th></tr></thead>
            <tbody>
            @forelse ($r['expiring'] as $e)
                <tr><td class="font-bold">{{ $e['contract']->name }}</td><td>{{ $e['count'] }}</td><td>{{ money($e['value']) }}</td><td>{{ local_time($e['first'], 'Y/m/d') }}</td></tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-navy-500">{{ __('Nothing ends in the next 30 days.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    <section class="card overflow-x-auto" data-report-exceptions>
        <h2 class="section-title mb-3">⚠ {{ __('Exceptions log') }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Voucher') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Approved by') }}</th><th>{{ __('Reason') }}</th></tr></thead>
            <tbody>
            @forelse ($r['exceptions'] as $x)
                <tr><td class="whitespace-nowrap">{{ local_time($x['at'], 'm/d h:i A') }}</td><td dir="ltr" class="font-bold">{{ $x['serial'] }}</td><td>{{ money($x['amount']) }}</td>
                    <td>{{ $x['approver'] ?? '—' }}<div class="text-xs text-navy-500">{{ __('Cashier') }}: {{ $x['cashier'] ?? '—' }}</div></td><td class="text-sm">{{ $x['reason'] }}</td></tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-navy-500">{{ __('No exceptions in this period.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
