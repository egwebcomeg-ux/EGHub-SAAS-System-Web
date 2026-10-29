@extends('layouts.dashboard')
@section('title', __('Supplier statement') . ' · ' . $supplier->name)

@section('content')
@php $range = array_filter(['from' => request('from'), 'to' => request('to')]); @endphp
<x-dash.page-header :title="__('Supplier statement') . ' · ' . $supplier->name" :help="__('Every purchase posted from this supplier. A cancelled purchase is shown crossed out and not counted.')">
    <x-slot:actions>
        <a href="{{ route('dash.inventory.suppliers.statement', ['supplier' => $supplier, 'format' => 'csv'] + $range) }}" class="btn-primary">CSV</a>
        <a href="{{ route('dash.inventory.suppliers.statement', ['supplier' => $supplier, 'format' => 'xlsx'] + $range) }}" class="btn-ghost">Excel</a>
        <a href="{{ route('dash.inventory.suppliers') }}" class="btn-ghost">{{ __('Back') }}</a>
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.inventory.tabs')

<form class="mb-3 flex flex-wrap items-end gap-2">
    <div><label class="field-label">{{ __('From') }}</label><input class="field min-h-9" type="date" name="from" value="{{ $from?->format('Y-m-d') }}"></div>
    <div><label class="field-label">{{ __('To') }}</label><input class="field min-h-9" type="date" name="to" value="{{ $to?->format('Y-m-d') }}"></div>
    <button class="btn-ghost min-h-9">{{ __('Apply') }}</button>
</form>

<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
    <div class="stat-card"><div class="stat-label">{{ __('Purchased') }}</div><div class="stat-value" data-supplier-total>{{ money($total) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Invoices') }}</div><div class="stat-value">{{ $purchases->where('status', 'posted')->count() }}</div></div>
    @if ($supplier->phone)<div class="stat-card col-span-2 sm:col-span-1"><div class="stat-label">{{ __('Phone') }}</div><div class="stat-value text-lg" dir="ltr">{{ $supplier->phone }}</div></div>@endif
</div>

<section class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Invoice number') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Total') }}</th><th>{{ __('Running total') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($purchases as $p)
            <tr @class(['line-through opacity-60' => ! $p->isPosted()])>
                <td class="whitespace-nowrap">{{ $p->purchased_on->format('Y/m/d') }}</td>
                <td>{{ $p->reference ?? '—' }}@if ($p->cancelled_at)<div class="text-xs text-red-600 no-underline">{{ __('Cancelled by :name: :reason', ['name' => $p->canceller?->name ?? '—', 'reason' => $p->cancel_reason]) }}</div>@endif</td>
                <td class="text-navy-500">{{ $p->branch ? tr($p->branch->name, $p->branch->name_en) : '—' }}</td>
                <td>{{ money($p->total) }}</td>
                <td class="font-bold">{{ money($p->running) }}</td>
                <td><a href="{{ route('dash.inventory.purchases.show', $p) }}" class="btn-ghost min-h-9 px-3">{{ __('View') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-navy-500">{{ __('Nothing yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
@endsection
