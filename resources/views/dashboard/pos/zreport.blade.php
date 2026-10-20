@extends('layouts.dashboard')
@section('title', __('End of day (Z)'))

@section('content')
@php
    $cur = tr($settings['currency'], $settings['currency_en']);
    $label = fn (string $m) => \App\Billing\PaymentMethods::label($m);
    $query = ['date' => $day->format('Y-m-d'), 'branch' => $branch->id, 'terminal' => $terminal?->id];
    $methods = array_unique(array_merge(array_keys($z['by_method']), array_keys($z['refunds']['by_method'])));
@endphp
<x-dash.page-header :title="__('POS')" :help="__('The day of one branch (or one terminal): sales by payment method, refunds, cancels, cash in and out, and each shift\'s counted cash.')">
    <x-slot:actions>
        <a href="{{ route('dash.zreport', $query + ['format' => '80mm']) }}" target="_blank" class="btn-ghost" data-z-print="80mm">🧾 {{ __('Print 80mm') }}</a>
        <a href="{{ route('dash.zreport', $query + ['format' => 'a4']) }}" target="_blank" class="btn-ghost" data-z-print="a4">🖨 {{ __('Print A4') }}</a>
        <a href="{{ route('dash.zreport', $query + ['format' => 'csv']) }}" class="btn-ghost">CSV</a>
        <a href="{{ route('dash.zreport', $query + ['format' => 'xlsx']) }}" class="btn-ghost">Excel</a>
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.pos.tabs', ['current' => 'zreport'])

<form class="card mb-4 flex flex-wrap items-end gap-3" data-z-filters>
    <label class="block"><span class="field-label">{{ __('Day') }}</span>
        <input class="field" type="date" name="date" value="{{ $day->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}"></label>
    @if ($branches->count() > 1)
        <label class="block"><span class="field-label">{{ __('Branch') }}</span>
            <select class="field" name="branch">@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach</select></label>
    @endif
    @if ($terminals->isNotEmpty())
        <label class="block"><span class="field-label">{{ __('Terminal') }}</span>
            <select class="field" name="terminal"><option value="">{{ __('All terminals') }}</option>@foreach ($terminals as $t)<option value="{{ $t->id }}" @selected($terminal?->id === $t->id)>{{ $t->label() }}</option>@endforeach</select></label>
    @endif
    <button class="btn-primary">{{ __('Show') }}</button>
</form>

<div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" data-z-kpis>
    @foreach ([
        [__('Net sales'), money($z['net']) . ' ' . $cur, $z['refunds']['total'] > 0 ? __('after :amount refunds', ['amount' => money($z['refunds']['total'])]) : null],
        [__('Paid orders'), $z['orders'], $z['cancelled']['count'] ? __(':n cancelled', ['n' => $z['cancelled']['count']]) : null],
        [__('Discounts'), money($z['discounts']) . ' ' . $cur, null],
        [__('Offline sales synced'), $z['offline']['count'], $z['offline']['review'] ? __(':n need review', ['n' => $z['offline']['review']]) : null],
    ] as [$title, $value, $sub])
        <div class="stat-card">
            <div class="stat-label">{{ $title }}</div>
            <div class="stat-value">{{ $value }}</div>
            @if ($sub)<div class="mt-0.5 text-xs text-navy-500">{{ $sub }}</div>@endif
        </div>
    @endforeach
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <section class="card overflow-x-auto" data-z-methods>
        <h2 class="section-title mb-3">{{ __('By payment method') }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Payment method') }}</th><th class="text-end">{{ __('Sales') }}</th><th class="text-end">{{ __('Refunds') }}</th><th class="text-end">{{ __('Net') }}</th></tr></thead>
            <tbody>
            @forelse ($methods as $m)
                <tr data-z-method="{{ $m }}"><td>{{ $label($m) }}</td><td class="text-end">{{ money($z['by_method'][$m] ?? 0) }}</td>
                    <td class="text-end text-red-600">@if (($z['refunds']['by_method'][$m] ?? 0) > 0)−{{ money($z['refunds']['by_method'][$m]) }}@endif</td>
                    <td class="text-end font-bold">{{ money($z['net_by_method'][$m] ?? 0) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">{{ __('No sales this day.') }}</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr class="font-extrabold"><td>{{ __('Total') }}</td><td class="text-end">{{ money($z['gross']) }}</td><td class="text-end text-red-600">@if ($z['refunds']['total'] > 0)−{{ money($z['refunds']['total']) }}@endif</td><td class="text-end">{{ money($z['net']) }}</td></tr></tfoot>
        </table>
    </section>

    <section class="card overflow-x-auto">
        <h2 class="section-title mb-3">{{ __('The day in figures') }}</h2>
        <table class="table-base"><tbody>
            <tr><td>{{ __('Service') }}</td><td class="text-end">{{ money($z['service']) }}</td></tr>
            <tr><td>{{ __('Tax') }}</td><td class="text-end">{{ money($z['tax']) }}</td></tr>
            <tr><td>{{ __('Refunds') }} ({{ $z['refunds']['count'] }})</td><td class="text-end text-red-600">@if ($z['refunds']['total'] > 0)−@endif{{ money($z['refunds']['total']) }}</td></tr>
            <tr data-z-cancelled><td>{{ __('Cancelled orders') }} ({{ $z['cancelled']['count'] }})</td><td class="text-end">{{ money($z['cancelled']['total']) }}</td></tr>
            <tr><td>{{ __('Not paid yet') }} ({{ $z['unpaid']['count'] }})</td><td class="text-end">{{ money($z['unpaid']['total']) }}</td></tr>
            <tr><td>{{ __('Cash in') }}</td><td class="text-end text-emerald-700">+{{ money($z['cash']['in']) }}</td></tr>
            <tr><td>{{ __('Cash out') }}@if ($z['cash']['expenses'] > 0) <span class="muted">· {{ __('of it expenses :amount', ['amount' => money($z['cash']['expenses'])]) }}</span>@endif</td><td class="text-end">−{{ money($z['cash']['out']) }}</td></tr>
            <tr data-z-offline><td>{{ __('Offline sales synced') }} ({{ $z['offline']['count'] }})</td><td class="text-end">{{ money($z['offline']['total']) }}</td></tr>
        </tbody></table>
    </section>
</div>

<section class="card mt-4 overflow-x-auto" data-z-shifts>
    <h2 class="section-title mb-3">{{ __('Shifts: expected and counted cash') }}</h2>
    @if ($z['shifts'] === [])
        <p class="muted">{{ __('No shifts this day.') }}</p>
    @else
        <table class="table-base">
            <thead><tr><th>{{ __('Cashier') }}</th><th>{{ __('Terminal') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th class="text-end">{{ __('Opening cash') }}</th><th class="text-end">{{ __('Expected cash') }}</th><th class="text-end">{{ __('Counted') }}</th><th class="text-end">{{ __('Difference') }}</th></tr></thead>
            <tbody>
            @foreach ($z['shifts'] as $s)
                <tr>
                    <td>{{ $s['user'] }}</td><td>{{ $s['terminal'] ?? '—' }}</td>
                    <td>{{ local_time($s['opened_at'], 'h:i A') }}</td><td>{{ $s['closed_at'] ? local_time($s['closed_at'], 'h:i A') : __('Still open') }}</td>
                    <td class="text-end">{{ money($s['opening']) }}</td><td class="text-end">{{ money($s['expected']) }}</td>
                    <td class="text-end">{{ $s['counted'] !== null ? money($s['counted']) : '—' }}</td>
                    <td @class(['text-end font-bold', 'text-red-600' => ($s['difference'] ?? 0) < 0, 'text-emerald-700' => ($s['difference'] ?? 0) >= 0])>{{ $s['difference'] !== null ? money($s['difference']) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</section>

@if ($z['terminals'] !== [])
    <section class="card mt-4 overflow-x-auto">
        <h2 class="section-title mb-3">{{ __('By terminal') }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Terminal') }}</th><th class="text-end">{{ __('Orders') }}</th><th class="text-end">{{ __('Sales') }}</th></tr></thead>
            <tbody>@foreach ($z['terminals'] as $t)<tr><td>{{ $t['name'] }}</td><td class="text-end">{{ $t['orders'] }}</td><td class="text-end">{{ money($t['total']) }}</td></tr>@endforeach</tbody>
        </table>
    </section>
@endif
@endsection
