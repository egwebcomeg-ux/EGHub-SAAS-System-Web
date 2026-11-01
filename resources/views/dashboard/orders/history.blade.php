@extends('layouts.dashboard')
@section('title', __('Order history'))

@section('content')
<x-dash.page-header :title="__('Order history')">
    <x-slot:actions>
        <a href="{{ route('dash.orders') }}" class="btn-ghost">{{ __('Orders') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<form method="get" action="{{ route('dash.orders.history') }}" class="card mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-order-history-filters>
    <label class="grid gap-1 text-xs font-semibold text-navy-600 lg:col-span-2">{{ __('Order number, name or mobile') }}
        <input class="field" type="search" name="q" value="{{ $filters['q'] }}">
    </label>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Status') }}
        <select class="field" name="status">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Order::STATUSES as $key => $label)
                <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ tr($label[0], $label[1]) }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('From') }}<input class="field" type="date" name="from" value="{{ $filters['from'] }}" dir="ltr"></label>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('To') }}<input class="field" type="date" name="to" value="{{ $filters['to'] }}" dir="ltr"></label>
    <div class="sm:col-span-2 lg:col-span-5"><button class="btn-navy min-h-10 px-5">{{ __('Search') }}</button></div>
</form>

<div class="card overflow-x-auto">
    <table class="w-full min-w-[42rem] text-sm">
        <thead>
            <tr class="border-b border-navy-100 text-start text-xs text-navy-500">
                <th class="py-2 text-start">{{ __('Order') }}</th>
                <th class="py-2 text-start">{{ __('Date') }}</th>
                <th class="py-2 text-start">{{ __('Customer') }}</th>
                <th class="py-2 text-start">{{ __('Branch') }}</th>
                <th class="py-2 text-start">{{ __('Status') }}</th>
                <th class="py-2 text-end">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-navy-100">
            @forelse ($orders as $order)
                <tr data-order-row="{{ $order->id }}">
                    <td class="py-2 font-bold"><a href="{{ route('dash.orders.receipt', $order) }}" target="_blank" rel="noopener" class="text-brand-700">#{{ $order->number }}</a></td>
                    <td class="py-2 whitespace-nowrap" dir="ltr">{{ local_time($order->created_at, 'Y/m/d h:i A') }}</td>
                    <td class="py-2"><bdi>{{ $order->customer_name ?: '—' }}</bdi>@if ($order->customer_phone)<div class="text-xs text-navy-500" dir="ltr">{{ $order->customer_phone }}</div>@endif</td>
                    <td class="py-2">{{ $order->branch?->name }}</td>
                    <td class="py-2">{{ $order->statusLabel() }}</td>
                    <td class="py-2 text-end font-semibold">{{ money($order->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-10 text-center text-navy-500">{{ __('No orders match this search.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="mt-3">{{ $orders->links() }}</div>
</div>
@endsection
