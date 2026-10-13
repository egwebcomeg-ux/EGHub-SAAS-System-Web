@extends('layouts.dashboard')
@section('title', __('Customers'))

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">{{ __('Customers') }}</h1>
    <a href="{{ route('dash.customers.export', ['q' => $q]) }}" class="btn-ghost">⬇ {{ __('Export to Excel (CSV)') }}</a>
</div>

<div class="mb-4 grid gap-4 sm:grid-cols-3">
    <div class="card"><div class="muted">{{ __('All customers') }}</div><div class="text-2xl font-extrabold">{{ $stats['total'] }}</div></div>
    <div class="card"><div class="muted">{{ __('Came back more than once') }}</div><div class="text-2xl font-extrabold">{{ $stats['returning'] }}</div></div>
    <div class="card"><div class="muted">{{ __('New this month') }}</div><div class="text-2xl font-extrabold">{{ $stats['new_month'] }}</div></div>
</div>

<form class="mb-4 flex flex-wrap gap-2">
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
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Visits') }}</th><th>{{ __('Total spent') }}</th><th>{{ __('Last visit') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($customers as $c)
            <tr>
                <td class="font-semibold">{{ $c->name ?: '—' }}</td>
                <td dir="ltr" class="text-start">{{ $c->phone }}</td>
                <td>{{ $c->visits_count }}</td>
                <td>{{ money($c->total_spent) }}</td>
                <td>{{ $c->last_visit_at ? local_time($c->last_visit_at, 'Y/m/d') : '—' }}</td>
                <td class="flex gap-3">
                    <a href="{{ route('dash.customers.show', $c) }}" class="text-brand-700">{{ __('View') }}</a>
                    <a href="https://wa.me/{{ whatsapp_number($c->phone) }}" target="_blank" rel="noopener" class="text-emerald-700">WhatsApp</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-10 text-center text-navy-500">{{ __('Guests appear here when they order with their mobile number or join the loyalty card.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $customers->links() }}</div>
</div>
@endsection
