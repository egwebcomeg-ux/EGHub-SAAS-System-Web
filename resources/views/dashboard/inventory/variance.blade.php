@extends('layouts.dashboard')
@section('title', __('Stock variance'))

@section('content')
@php
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.') ?: '0';
    $query = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')] + array_filter(['kind' => $kind]);
@endphp
<x-dash.page-header :title="__('Stock variance')" :help="__('What each ingredient should have used (the recipes of the orders sold) against what really left the shelf. The unexplained difference shows up once you post a count; the biggest money loss is on top.')">
    <x-slot:actions>
        <a href="{{ route('dash.inventory.variance', $query + ['format' => 'csv']) }}" class="btn-primary" data-variance-csv>CSV</a>
        <a href="{{ route('dash.inventory.variance', $query + ['format' => 'xlsx']) }}" class="btn-ghost">Excel</a>
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.inventory.tabs')

<form class="mb-3 flex flex-wrap items-end gap-2">
    <div><label class="field-label">{{ __('From') }}</label><input class="field min-h-9" type="date" name="from" value="{{ $from->format('Y-m-d') }}"></div>
    <div><label class="field-label">{{ __('To') }}</label><input class="field min-h-9" type="date" name="to" value="{{ $to->format('Y-m-d') }}"></div>
    <div>
        <label class="field-label">{{ __('Kind') }}</label>
        <select name="kind" class="field min-h-9 py-0">
            <option value="">{{ __('Ingredients and packaging') }}</option>
            @foreach (\App\Models\Ingredient::KINDS as $key => $label)
                <option value="{{ $key }}" @selected($kind === $key)>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn-ghost min-h-9">{{ __('Apply') }}</button>
</form>

<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
    <div class="stat-card"><div class="stat-label">{{ __('Purchases') }}</div><div class="stat-value">{{ money($totals['purchases']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Sales usage (recipes)') }}</div><div class="stat-value">{{ money($totals['sales']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Waste') }}</div><div class="stat-value text-amber-700">{{ money($totals['waste']) }}</div></div>
    <div @class(['stat-card', 'ring-2 ring-red-200' => $totals['unexplained'] > 0])>
        <div class="stat-label">{{ $totals['unexplained'] >= 0 ? __('Unexplained loss') : __('Unexplained extra') }}</div>
        <div @class(['stat-value', 'text-red-600' => $totals['unexplained'] > 0, 'text-emerald-700' => $totals['unexplained'] < 0]) data-variance-total>{{ money(abs($totals['unexplained'])) }}</div>
    </div>
</div>

<div class="mb-4 grid gap-4 lg:grid-cols-2">
    <section class="card overflow-x-auto">
        <h2 class="section-title mb-2">{{ __('By kind') }}</h2>
        <table class="table-base" data-kind-totals>
            <thead><tr><th>{{ __('Kind') }}</th><th>{{ __('Purchases') }}</th><th>{{ __('Sales usage (recipes)') }}</th><th>{{ __('Waste') }}</th><th>{{ __('Unexplained (money)') }}</th></tr></thead>
            <tbody>
            @foreach ($kindTotals as $key => $t)
                <tr data-kind="{{ $key }}">
                    <td class="font-semibold">{{ app()->getLocale() === 'en' ? \App\Models\Ingredient::KINDS[$key][1] : \App\Models\Ingredient::KINDS[$key][0] }}</td>
                    <td>{{ money($t['purchases']) }}</td><td>{{ money($t['sales']) }}</td><td>{{ money($t['waste']) }}</td>
                    <td @class(['font-bold', 'text-red-600' => $t['unexplained'] > 0, 'text-emerald-700' => $t['unexplained'] < 0])>{{ money($t['unexplained']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
    <section class="card">
        <h2 class="section-title mb-2">{{ __('Packaging per order') }}</h2>
        <div class="flex flex-wrap gap-6 text-sm">
            @foreach (\App\Models\PackagingRule::ORDER_TYPES as $type => $label)
                <span>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}: <b>{{ money($orderPackaging[$type] ?? 0) }}</b></span>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-navy-500">{{ __('Included in the sales usage above. Set it on Recipes & cost.') }}</p>
    </section>
</div>

<section class="card overflow-x-auto">
    <table class="table-base text-sm">
        <thead>
            <tr>
                <th>{{ __('Ingredient') }}</th><th>{{ __('Opening') }}</th><th>{{ __('Purchases') }}</th><th>{{ __('Sales usage (recipes)') }}</th><th>{{ __('Waste') }}</th>
                <th>{{ __('Count corrections') }}</th><th>{{ __('Closing') }}</th><th>{{ __('Actual usage') }}</th><th>{{ __('Unexplained difference') }}</th><th>{{ __('Unexplained (money)') }}</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr data-variance-row="{{ $r['ingredient']->id }}">
                <td class="font-semibold">{{ $r['ingredient']->name }} <span class="text-xs font-normal text-navy-500">{{ $r['ingredient']->unitLabel() }}@if ($r['ingredient']->kind === 'packaging') · {{ $r['ingredient']->kindLabel() }}@endif</span></td>
                <td dir="ltr" class="text-end">{{ $qty($r['opening']) }}</td>
                <td dir="ltr" class="text-end">{{ $qty($r['purchases']) }}</td>
                <td dir="ltr" class="text-end">{{ $qty($r['sales']) }}</td>
                <td dir="ltr" class="text-end">{{ $qty($r['waste']) }}</td>
                <td dir="ltr" class="text-end">{{ $qty($r['counts']) }}</td>
                <td dir="ltr" class="text-end">{{ $qty($r['closing']) }}</td>
                <td dir="ltr" class="text-end">{{ $qty($r['actual']) }}</td>
                <td dir="ltr" @class(['text-end font-bold', 'text-red-600' => $r['unexplained'] > 0, 'text-emerald-700' => $r['unexplained'] < 0])>{{ $qty($r['unexplained']) }}</td>
                <td @class(['font-bold', 'text-red-600' => $r['unexplained_cost'] > 0, 'text-emerald-700' => $r['unexplained_cost'] < 0])>{{ money($r['unexplained_cost']) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-6 text-center text-navy-500">{{ __('No stock movements in this period.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
@endsection
