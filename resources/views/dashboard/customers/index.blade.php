@extends('layouts.dashboard')
@section('title', __('Customers'))

@section('content')
<x-dash.page-header :title="__('Customers')">
    <x-slot:actions>
        @can('customers.import')<a href="{{ route('dash.customers.import') }}" class="btn-ghost" data-customers-import>⬆ {{ __('Import customers') }}</a>@endcan
        <a href="{{ route('dash.customers.export', array_filter(['q' => $q, 'segment' => $segment])) }}" class="btn-ghost">⬇ {{ __('Export to Excel (CSV)') }}</a>
    </x-slot:actions>
</x-dash.page-header>

@if ($summary = session('import_summary'))
    <section class="card mb-4 border-s-4 border-emerald-500" data-import-summary>
        <h2 class="section-title">{{ __('Import finished') }} <span class="text-sm font-normal text-navy-500">· <span dir="ltr">{{ $summary['file'] }}</span></span></h2>
        <dl class="mt-3 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-xl bg-emerald-50 p-3"><dt class="text-xs text-emerald-800">{{ __('New customers') }}</dt><dd class="text-xl font-bold text-emerald-900">{{ number_format($summary['created']) }}</dd></div>
            <div class="rounded-xl bg-brand-50 p-3"><dt class="text-xs text-brand-700">{{ __('Updated') }}</dt><dd class="text-xl font-bold text-brand-700">{{ number_format($summary['updated']) }}</dd></div>
            <div class="rounded-xl bg-navy-50 p-3"><dt class="text-xs text-navy-600">{{ __('Skipped rows') }}</dt><dd class="text-xl font-bold text-navy-800">{{ number_format($summary['skipped']) }}</dd></div>
        </dl>
    </section>
@endif

<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
    <div class="stat-card"><div class="stat-label">{{ __('All customers') }}</div><div class="stat-value">{{ $stats['total'] }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Came back more than once') }}</div><div class="stat-value">{{ $stats['returning'] }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('New this month') }}</div><div class="stat-value">{{ $stats['new_month'] }}</div></div>
</div>

@php
    $segmentStyles = [
        'new' => 'bg-sky-100 text-sky-800', 'regular' => 'bg-emerald-100 text-emerald-800', 'vip' => 'bg-amber-100 text-amber-800',
        'at_risk' => 'bg-orange-100 text-orange-800', 'lost' => 'bg-navy-100 text-navy-500',
    ];
    $segmentHints = [
        'new' => __('First visit in the last 30 days'), 'regular' => __('3 or more orders in 90 days'), 'vip' => __('Top 10% by spend, or the highest membership tier'),
        'at_risk' => __('Regulars who have not visited for 30 to 60 days'), 'lost' => __('Visited twice or more, nothing for over 60 days'),
    ];
@endphp
<div class="mb-4 flex flex-wrap gap-2" data-segments aria-label="{{ __('Segments') }}">
    <a href="{{ route('dash.customers', array_filter(['q' => $q, 'sort' => $sort])) }}" @class(['badge min-h-9 px-3', 'bg-navy-900 text-white' => $segment === '', 'bg-white text-navy-700 ring-1 ring-navy-100' => $segment !== ''])>{{ __('All customers') }} · {{ number_format($stats['total']) }}</a>
    @foreach (\App\Services\Customers\Segments::keys() as $key)
        <a href="{{ route('dash.customers', array_filter(['q' => $q, 'sort' => $sort, 'segment' => $key])) }}" title="{{ $segmentHints[$key] }}" data-segment="{{ $key }}"
           @class(['badge min-h-9 px-3', $segmentStyles[$key], 'ring-2 ring-navy-900' => $segment === $key])>{{ \App\Services\Customers\Segments::label($key) }} · {{ number_format($segmentCounts[$key]) }}</a>
    @endforeach
</div>
@if ($segment !== '')
    <p class="mb-3 text-sm text-navy-500">{{ $segmentHints[$segment] }}.
        @can('marketing.manage')
            @if (\App\Services\Entitlements::current()->allows('campaigns'))
                <a href="{{ route('dash.campaigns', ['segment' => $segment]) }}" class="font-semibold text-brand-700 underline">{{ __('Send them a campaign') }}</a>
            @endif
        @endcan
    </p>
@endif

<form class="mb-4 flex flex-wrap gap-2">
    @if ($segment !== '')<input type="hidden" name="segment" value="{{ $segment }}">@endif
    <input class="field max-w-xs" name="q" value="{{ $q }}" placeholder="{{ __('Search name or mobile') }}">
    <select class="field max-w-52" name="sort" onchange="this.form.submit()">
        <option value="last_visit_at" @selected($sort === 'last_visit_at')>{{ __('Last visit') }}</option>
        <option value="visits_count" @selected($sort === 'visits_count')>{{ __('Most visits') }}</option>
        <option value="total_spent" @selected($sort === 'total_spent')>{{ __('Top spenders') }}</option>
    </select>
    <button class="btn-ghost">{{ __('Search') }}</button>
</form>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Segment') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Visits') }}</th><th>{{ __('Total spent') }}</th><th>{{ __('Last visit') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($customers as $c)
            @php($badge = $segments->of($c))
            <tr>
                <td class="font-semibold">{{ $c->name ?: '—' }}</td>
                <td>@if ($badge)<span class="badge {{ $segmentStyles[$badge] }}" data-row-segment="{{ $badge }}">{{ \App\Services\Customers\Segments::label($badge) }}</span>@else<span class="text-navy-400">—</span>@endif</td>
                <td dir="ltr" class="text-start">{{ $c->phone }}</td>
                <td>{{ $c->visits_count }}</td>
                <td>{{ money($c->total_spent) }}</td>
                <td>{{ $c->last_visit_at ? local_time($c->last_visit_at, 'Y/m/d') : '—' }}</td>
                <td>
                    <div class="flex gap-3">
                        <a href="{{ route('dash.customers.show', $c) }}" class="text-brand-700">{{ __('View') }}</a>
                        <a href="https://wa.me/{{ whatsapp_number($c->phone) }}" target="_blank" rel="noopener" class="text-emerald-700">WhatsApp</a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-10 text-center text-navy-500">{{ $segment !== '' ? __('No guests in this segment yet.') : __('Guests appear here when they order with their mobile number or join the loyalty card.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $customers->links() }}</div>
</div>
@endsection
