@extends('layouts.dashboard')
@section('title', __('Company statement') . ' · ' . $contract->name)

@section('content')
<x-dash.page-header :title="__('Company statement') . ' · ' . $contract->name" :help="__('Every use of the company\'s vouchers, to settle with the company. A use whose order was cancelled is shown crossed out and not counted.')">
    <x-slot:actions>
        <a href="{{ route('dash.corporate.statement', ['contract' => $contract, 'format' => 'csv'] + array_filter(['from' => request('from'), 'to' => request('to')])) }}" class="btn-primary" data-statement-csv>CSV</a>
        <a href="{{ route('dash.corporate.statement', ['contract' => $contract, 'format' => 'xlsx'] + array_filter(['from' => request('from'), 'to' => request('to')])) }}" class="btn-ghost">Excel</a>
        <a href="{{ route('dash.corporate.show', $contract) }}" class="btn-ghost">{{ __('Back to the contract') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<form class="card mb-4 flex flex-wrap items-end gap-2">
    <label class="block"><span class="field-label">{{ __('From') }}</span><input class="field" type="date" name="from" value="{{ $from?->format('Y-m-d') }}"></label>
    <label class="block"><span class="field-label">{{ __('To') }}</span><input class="field" type="date" name="to" value="{{ $to?->format('Y-m-d') }}"></label>
    <button class="btn-ghost">{{ __('Apply') }}</button>
</form>

<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <div class="stat-card"><div class="stat-label">{{ __('Uses') }}</div><div class="stat-value">{{ $rows->whereNull('refunded_at')->count() }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Used value') }}</div><div class="stat-value text-brand-700" data-statement-total>{{ money($total) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Lost (one use)') }}</div><div class="stat-value text-navy-500">{{ money($forfeited) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Paid by the company') }}</div><div class="stat-value">{{ money($contract->paid_amount) }} <span class="text-sm">/ {{ money($contract->totalValue()) }}</span></div></div>
</div>

<section class="card">
    <table class="table-base">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Order') }}</th><th>{{ __('Serial') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Cashier') }}</th><th>{{ __('Note') }}</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr @class(['line-through opacity-60' => $r->refunded_at]) data-statement-row>
                <td class="whitespace-nowrap">{{ local_time($r->created_at, 'Y/m/d h:i A') }}</td>
                <td>{{ $r->branch ? tr($r->branch->name, $r->branch->name_en) : '—' }}</td>
                <td>{{ $r->order ? '#' . $r->order->number : '—' }}</td>
                <td dir="ltr" class="font-bold">{{ $r->voucher?->serial }}</td>
                <td class="font-extrabold">{{ money($r->amount) }}@if ($r->forfeited > 0) <span class="text-xs font-normal text-navy-500">+ {{ __(':amount lost', ['amount' => money($r->forfeited)]) }}</span>@endif</td>
                <td>{{ $r->cashier?->name ?? '—' }}</td>
                <td class="text-sm text-navy-600">
                    @if ($r->refunded_at){{ __('Order cancelled: value returned') }}@endif
                    @if ($r->exception_reason)⚠ {{ __('Exception after expiry by :name: :reason', ['name' => $r->approver?->name ?? '—', 'reason' => $r->exception_reason]) }}@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-8 text-center text-navy-500">{{ __('No uses yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
@endsection
