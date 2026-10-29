@extends('layouts.dashboard')
@section('title', __('Corporate contracts'))

@section('content')
@php
    $statusStyles = ['active' => 'bg-emerald-100 text-emerald-800', 'paused' => 'bg-amber-100 text-amber-800', 'void' => 'bg-navy-100 text-navy-500',
        'used' => 'bg-sky-100 text-sky-800', 'expired' => 'bg-navy-100 text-navy-500'];
    $paidStyles = ['paid' => 'bg-emerald-100 text-emerald-800', 'partial' => 'bg-amber-100 text-amber-800', 'unpaid' => 'bg-red-100 text-red-700'];
@endphp
<x-dash.page-header :title="__('Corporate contracts')" :help="__('A company buys prepaid vouchers for its staff: you print them, the cashier takes them at the POS, and the statement shows every use for settlement.')">
    <x-slot:actions>
        <a href="{{ route('dash.corporate.create') }}" class="btn-primary" data-new-contract>＋ {{ __('New contract') }}</a>
        <a href="{{ route('dash.corporate.bookings') }}" class="btn-ghost" data-visits-link>🗓 {{ __('Company visits') }}@if ($totals['visits'] > 0) <span class="badge bg-red-100 text-red-700">{{ $totals['visits'] }}</span>@endif</a>
        <a href="{{ route('dash.corporate.report') }}" class="btn-ghost" data-vouchers-report-link>📊 {{ __('Vouchers report') }}</a>
        @can('vouchers.redeem')<a href="{{ route('dash.redeem') }}" class="btn-ghost" data-redeem-link>{{ __('Redeem vouchers') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>

<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
    <div class="stat-card"><div class="stat-label">{{ __('Running contracts') }}</div><div class="stat-value">{{ number_format($totals['contracts']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Value left on vouchers') }}</div><div class="stat-value text-brand-700">{{ money($totals['remaining']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Used this month') }}</div><div class="stat-value">{{ money($totals['month']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Not fully paid') }}</div><div @class(['stat-value', 'text-red-700' => $totals['unpaid'] > 0])>{{ $totals['unpaid'] }}</div></div>
</div>

<form class="card mb-4 flex flex-wrap items-end gap-2" data-voucher-search>
    <label class="block min-w-0 flex-1"><span class="field-label">{{ __('Find a voucher') }}</span>
        <input class="field" name="q" value="{{ $q }}" maxlength="40" placeholder="{{ __('Code, serial (EVA-0012) or the guest\'s mobile') }}" dir="auto" autocomplete="off"></label>
    <button class="btn-ghost">{{ __('Search') }}</button>
    @if ($q !== '')<a href="{{ route('dash.corporate') }}" class="btn-ghost">{{ __('Clear') }}</a>@endif
</form>

@if ($found !== null)
    <section class="card mb-4 overflow-x-auto" data-search-results>
        <h2 class="section-title mb-2">{{ __('Search results') }}</h2>
        @if ($found->isEmpty())
            <p class="empty-state">{{ __('No voucher matches.') }}</p>
        @else
            <table class="table-base">
                <thead><tr><th>{{ __('Serial') }}</th><th>{{ __('Company') }}</th><th>{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('Linked mobile') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($found as $v)
                    <tr data-found="{{ $v->serial }}">
                        <td class="font-bold" dir="ltr">{{ $v->serial }}</td>
                        <td>{{ $v->contract->name }}</td>
                        <td class="font-extrabold">{{ money($v->balance) }} <span class="text-xs text-navy-500">/ {{ money($v->value) }}</span></td>
                        <td><span class="badge {{ $statusStyles[$v->statusKey()] ?? '' }}">{{ $v->statusLabel() }}</span></td>
                        <td>{{ local_time($v->expires_on, 'Y/m/d') }}</td>
                        <td dir="ltr">{{ $v->customer?->phone ?? ($v->pending_phone ? '⏳ '.$v->pending_phone : '—') }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <a href="{{ route('dash.corporate.show', ['contract' => $v->corporate_contract_id, 'filter' => 'all']) }}#v-{{ $v->id }}" class="btn-ghost min-h-11 px-3">{{ __('Open') }}</a>
                                @if ($v->status === 'active')
                                    <form method="post" action="{{ route('dash.corporate.voucher.extend', $v) }}" class="flex gap-1">
                                        @csrf
                                        <input type="date" name="expires_on" class="field min-h-11 w-40" value="{{ now()->addMonth()->format('Y-m-d') }}" min="{{ now()->format('Y-m-d') }}" aria-label="{{ __('New expiry date') }}">
                                        <button class="btn-ghost min-h-11 px-3">{{ __('Extend') }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endif

<section class="card overflow-x-auto" data-contracts>
    @if ($contracts->isEmpty())
        <div class="empty-state py-10 text-center">
            <div class="text-4xl" aria-hidden="true">🏢</div>
            <p class="mt-2 font-semibold">{{ __('No contracts yet.') }}</p>
            <p class="text-sm text-navy-500">{{ __('Example: a company buys 300 vouchers of 200 for its staff. Create the contract, print the vouchers, and the cashier takes them like money.') }}</p>
            <a href="{{ route('dash.corporate.create') }}" class="btn-primary mt-4">＋ {{ __('New contract') }}</a>
        </div>
    @else
        <table class="table-base">
            <thead><tr>
                <th>{{ __('Company') }}</th><th>{{ __('Vouchers') }}</th><th>{{ __('Valid until') }}</th>
                <th>{{ __('Printed / used / left') }}</th><th>{{ __('Used value') }}</th><th>{{ __('Value left') }}</th><th>{{ __('Payment') }}</th><th>{{ __('Status') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($contracts as $c)
                @php($s = $stats[$c->id])
                <tr data-contract="{{ $c->id }}">
                    <td>
                        <a href="{{ route('dash.corporate.show', $c) }}" class="font-bold text-brand-700 hover:underline">{{ $c->name }}</a>
                        <div class="text-xs text-navy-500">{{ $c->modeLabel() }}@if ($c->reference) · {{ $c->reference }}@endif</div>
                    </td>
                    <td class="whitespace-nowrap">{{ $c->quantity }} × {{ money($c->voucher_value) }}</td>
                    <td class="whitespace-nowrap">{{ local_time($c->expires_on, 'Y/m/d') }}</td>
                    <td class="whitespace-nowrap tabular-nums" data-counts>{{ $s['printed'] }} / {{ $s['used'] }} / {{ $s['remaining'] }}</td>
                    <td class="font-semibold">{{ money($s['consumed']) }}</td>
                    <td class="font-extrabold text-brand-700">{{ money($s['remaining_value']) }}</td>
                    <td><span class="badge {{ $paidStyles[$c->paid_status] ?? '' }}">{{ $c->paidLabel() }}</span></td>
                    <td><span class="badge {{ $statusStyles[$c->status] ?? '' }}">{{ $c->statusLabel() }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-4">{{ $contracts->links() }}</div>
    @endif
</section>
@endsection
