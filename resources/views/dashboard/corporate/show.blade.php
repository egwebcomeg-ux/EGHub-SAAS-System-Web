@extends('layouts.dashboard')
@section('title', $contract->name)

@section('content')
@php
    $statusStyles = ['active' => 'bg-emerald-100 text-emerald-800', 'paused' => 'bg-amber-100 text-amber-800', 'void' => 'bg-navy-100 text-navy-500',
        'used' => 'bg-sky-100 text-sky-800', 'expired' => 'bg-navy-100 text-navy-500'];
    $paidStyles = ['paid' => 'bg-emerald-100 text-emerald-800', 'partial' => 'bg-amber-100 text-amber-800', 'unpaid' => 'bg-red-100 text-red-700'];
    $live = $contract->status !== 'void';
@endphp
<x-dash.page-header :title="$contract->name" :help="$contract->quantity . ' × ' . money($contract->voucher_value) . ' · ' . $contract->modeLabel()">
    <div class="mt-2 flex flex-wrap gap-2">
        <span class="badge {{ $statusStyles[$contract->status] ?? '' }}" data-contract-status="{{ $contract->status }}">{{ $contract->statusLabel() }}</span>
        <span class="badge {{ $paidStyles[$contract->paid_status] ?? '' }}">{{ $contract->paidLabel() }} · {{ money($contract->paid_amount) }} / {{ money($contract->totalValue()) }}</span>
    </div>
    <x-slot:actions>
        @if ($live)
            <a href="{{ route('dash.corporate.print', ['contract' => $contract, 'unprinted' => 1]) }}" class="btn-primary" target="_blank" data-print-new>🖨 {{ __('Print new vouchers') }}</a>
            <a href="{{ route('dash.corporate.edit', $contract) }}" class="btn-ghost">{{ __('Edit') }}</a>
        @endif
        <a href="{{ route('dash.corporate.statement', $contract) }}" class="btn-ghost" data-statement-link>{{ __('Company statement') }}</a>
        <a href="{{ route('dash.corporate') }}" class="btn-ghost">{{ __('All contracts') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-6" data-contract-stats>
    <div class="stat-card"><div class="stat-label">{{ __('Printed') }}</div><div class="stat-value">{{ $stats['printed'] }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Used up') }}</div><div class="stat-value">{{ $stats['used'] }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Still usable') }}</div><div class="stat-value">{{ $stats['remaining'] }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Used value') }}</div><div class="stat-value">{{ money($stats['consumed']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Lost (one use)') }}</div><div class="stat-value text-navy-500">{{ money($stats['forfeited']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Value left') }}</div><div class="stat-value text-brand-700" data-remaining-value>{{ money($stats['remaining_value']) }}</div></div>
</div>

<div class="mb-4 grid gap-4 lg:grid-cols-3">
    <section class="card space-y-2 text-sm lg:col-span-2">
        <h2 class="section-title">{{ __('Terms') }}</h2>
        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="text-navy-500">{{ __('Valid until') }}</dt><dd class="font-bold">{{ local_time($contract->expires_on, 'Y/m/d') }}</dd></div>
            <div><dt class="text-navy-500">{{ __('Branches') }}</dt><dd class="font-bold">{{ $contract->branch_ids ? collect($contract->branch_ids)->map(fn ($id) => $branchNames[$id] ?? '')->filter()->implode('، ') : __('Every branch') }}</dd></div>
            <div><dt class="text-navy-500">{{ __('Days and hours') }}</dt><dd class="font-bold">{{ $contract->windowLabel() ?: __('Any time') }}</dd></div>
            <div><dt class="text-navy-500">{{ __('Minimum order') }}</dt><dd class="font-bold">{{ $contract->min_order ? money($contract->min_order) : '—' }}</dd></div>
            <div><dt class="text-navy-500">{{ __('Contact') }}</dt><dd class="font-bold">{{ $contract->contact_name ?: '—' }} <span dir="ltr">{{ $contract->contact_phone }}</span></dd></div>
            <div><dt class="text-navy-500">{{ __('Contract / invoice number') }}</dt><dd class="font-bold" dir="auto">{{ $contract->reference ?: '—' }}</dd></div>
        </dl>
        @if ($contract->notes)<p class="rounded-xl bg-navy-50 p-3 text-navy-700">{{ $contract->notes }}</p>@endif
    </section>

    <section class="card space-y-3">
        <h2 class="section-title">{{ __('Actions') }}</h2>
        @if ($live)
            <form method="post" action="{{ route('dash.corporate.extend', $contract) }}" class="flex flex-wrap items-end gap-2" data-extend-contract>
                @csrf
                <label class="block flex-1"><span class="field-label">{{ __('Extend every voucher until') }}</span>
                    <input type="date" name="expires_on" class="field" min="{{ now()->format('Y-m-d') }}" value="{{ $contract->expires_on->copy()->addMonth()->format('Y-m-d') }}" required></label>
                <button class="btn-ghost">{{ __('Extend') }}</button>
            </form>
            <div class="flex flex-wrap gap-2 border-t border-navy-100 pt-3">
                <form method="post" action="{{ route('dash.corporate.status', $contract) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ $contract->status === 'paused' ? 'active' : 'paused' }}">
                    <button class="btn-ghost">{{ $contract->status === 'paused' ? __('Resume') : __('Pause') }}</button>
                </form>
                <form method="post" action="{{ route('dash.corporate.status', $contract) }}" onsubmit="return confirm(@js(__('Void the whole contract? Its vouchers stop working for good.')))">
                    @csrf
                    <input type="hidden" name="status" value="void">
                    <button class="btn-ghost text-red-700">{{ __('Void contract') }}</button>
                </form>
            </div>
        @endif
        <div class="flex flex-wrap gap-2 border-t border-navy-100 pt-3">
            <a href="{{ route('dash.corporate.print', $contract) }}" target="_blank" class="btn-ghost">{{ __('Print all (A4)') }}</a>
            <a href="{{ route('dash.corporate.print', ['contract' => $contract, 'layout' => 'receipt', 'unprinted' => 1]) }}" target="_blank" class="btn-ghost">{{ __('80mm receipts') }}</a>
            <a href="{{ route('dash.corporate.export', ['contract' => $contract, 'format' => 'csv']) }}" class="btn-ghost">CSV</a>
            <a href="{{ route('dash.corporate.export', ['contract' => $contract, 'format' => 'xlsx']) }}" class="btn-ghost">Excel</a>
        </div>
        <p class="text-xs text-navy-500">{{ __('A voucher printed before comes out marked “copy”. Every print is in the audit log.') }}</p>
    </section>
</div>

@include('dashboard.partials.tabs', ['label' => __('Vouchers'), 'current' => $filter, 'tabs' => collect([
    'all' => __('All'), 'active' => __('Active'), 'expired' => __('Expired'), 'used' => __('Used up'), 'unprinted' => __('Not printed'), 'linked' => __('Linked to a mobile'), 'void' => __('Voided'),
])->map(fn ($label, $key) => [$key, $label, route('dash.corporate.show', ['contract' => $contract, 'filter' => $key])])->values()->all()])

<section class="card" data-vouchers>
    <table class="table-base">
        <thead><tr><th>{{ __('Serial') }}</th><th>{{ __('Code') }}</th><th>{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('Linked mobile') }}</th><th>{{ __('Prints') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($vouchers as $v)
            <tr id="v-{{ $v->id }}" data-voucher-row="{{ $v->serial }}">
                <td class="font-bold" dir="ltr">{{ $v->serial }}</td>
                <td class="font-mono" dir="ltr">{{ $v->formattedCode() }}</td>
                <td class="font-extrabold">{{ money($v->balance) }}@if ($v->balance != $v->value) <span class="text-xs font-normal text-navy-500">/ {{ money($v->value) }}</span>@endif</td>
                <td><span class="badge {{ $statusStyles[$v->statusKey()] ?? '' }}">{{ $v->statusLabel() }}</span></td>
                <td class="whitespace-nowrap">{{ local_time($v->expires_on, 'Y/m/d') }}</td>
                <td dir="ltr">{{ $v->customer?->phone ?? ($v->pending_phone ? '⏳ '.$v->pending_phone : '—') }}</td>
                <td class="tabular-nums">{{ $v->print_count }}</td>
                <td>
                    <div class="flex flex-wrap items-center gap-1">
                        @if ($v->status !== 'void')
                            <a href="{{ route('dash.corporate.voucher.receipt', $v) }}" target="_blank" class="btn-ghost min-h-11 px-3" title="{{ __('80mm receipt') }}">🧾</a>
                        @endif
                        @if ($live && $v->status === 'active')
                            <form method="post" action="{{ route('dash.corporate.voucher.extend', $v) }}" class="flex gap-1">
                                @csrf
                                <input type="date" name="expires_on" class="field min-h-11 w-40" min="{{ now()->format('Y-m-d') }}" value="{{ $v->expires_on->copy()->max(now())->addMonth()->format('Y-m-d') }}" aria-label="{{ __('New expiry date') }}">
                                <button class="btn-ghost min-h-11 px-3">{{ __('Extend') }}</button>
                            </form>
                            <form method="post" action="{{ route('dash.corporate.voucher.void', $v) }}" onsubmit="return confirm(@js(__('Void this voucher? It stops working for good.')))">
                                @csrf
                                <button class="btn-ghost min-h-11 px-3 text-red-700">{{ __('Void voucher') }}</button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-8 text-center text-navy-500">{{ __('No vouchers here.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $vouchers->links() }}</div>
</section>
@endsection
