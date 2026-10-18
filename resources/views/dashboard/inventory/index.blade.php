@extends('layouts.dashboard')
@section('title', __('Inventory'))

@section('content')
<x-dash.page-header :title="__('Inventory')" :help="__('Each order takes its recipe out of the branch stock, and a cancelled order puts it back. Receive goods, record waste and enter your counts here.')" />
@include('dashboard.inventory.tabs')

@php
    $low = $ingredients->where('is_low', true);
@endphp
<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
    <div class="stat-card"><div class="stat-label">{{ __('Ingredients') }}</div><div class="stat-value">{{ $ingredients->where('is_active', true)->count() }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Stock value') }}</div><div class="stat-value">{{ money($stockValue) }}</div></div>
    <div @class(['stat-card col-span-2 sm:col-span-1', 'ring-2 ring-amber-200' => $low->isNotEmpty()])><div class="stat-label">{{ __('Running low') }}</div><div @class(['stat-value', 'text-amber-700' => $low->isNotEmpty()])>{{ $low->count() }}</div></div>
</div>

<div class="grid gap-4 xl:grid-cols-3">
    <section class="card overflow-x-auto xl:col-span-2">
        <table class="table-base">
            <thead><tr><th>{{ __('Ingredient') }}</th><th>{{ __('In stock') }}</th><th>{{ __('Alert under') }}</th><th>{{ __('Unit cost') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($ingredients as $i)
                <tr @class(['opacity-50' => !$i->is_active])>
                    <td class="font-semibold">{{ $i->name }}</td>
                    <td>
                        <span @class(['font-bold', 'text-amber-700' => $i->is_low])>{{ rtrim(rtrim(number_format($i->on_hand, 3, '.', ''), '0'), '.') }}</span>
                        <span class="text-xs text-navy-500">{{ $i->unitLabel() }}</span>
                        @if ($i->is_low)<span class="badge bg-amber-100 text-amber-800">{{ __('Low') }}</span>@endif
                    </td>
                    <td class="text-navy-500">{{ $i->min_stock > 0 ? rtrim(rtrim(number_format($i->min_stock, 3, '.', ''), '0'), '.') : '—' }}</td>
                    <td class="text-navy-500" dir="ltr">{{ rtrim(rtrim(number_format($i->cost_per_unit, 4, '.', ''), '0'), '.') }}</td>
                    <td>
                        <details class="relative">
                            <summary class="btn-ghost min-h-9 cursor-pointer list-none px-3">{{ __('Edit') }}</summary>
                            <form method="post" action="{{ route('dash.inventory.update', $i) }}" class="card absolute end-0 z-10 mt-2 w-72 space-y-2">
                                @csrf
                                @include('dashboard.inventory.fields', ['i' => $i])
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($i->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                <button class="btn-primary w-full">{{ __('Save') }}</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-navy-500">{{ __('Add your first ingredient, e.g. coffee beans in grams or milk in ml.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <div class="space-y-4">
        @if ($ingredients->isNotEmpty())
            <form method="post" action="{{ route('dash.inventory.move') }}" class="card space-y-2">
                @csrf
                <h2 class="section-title">{{ __('Stock movement') }} <span class="text-sm font-normal text-navy-500">· {{ $branch->name }}</span></h2>
                <input type="hidden" name="branch" value="{{ $branch->id }}">
                <select class="field" name="type" required onchange="this.form.querySelector('[data-cost]').classList.toggle('hidden', this.value !== 'purchase'); this.form.querySelector('[data-qty-label]').textContent = this.value === 'count' ? @js(__('Quantity counted on the shelf')) : @js(__('Quantity'))">
                    <option value="purchase">{{ __('Receive goods (purchase)') }}</option>
                    <option value="waste">{{ __('Waste / spoiled') }}</option>
                    <option value="count">{{ __('Stock count') }}</option>
                </select>
                <select class="field" name="ingredient_id" required>
                    @foreach ($ingredients->where('is_active', true) as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unitLabel() }})</option>@endforeach
                </select>
                <label class="field-label" data-qty-label>{{ __('Quantity') }}</label>
                <input class="field" name="quantity" type="number" step="0.001" min="0" required>
                <div data-cost>
                    <label class="field-label">{{ __('Total paid (optional, updates the average cost)') }}</label>
                    <input class="field" name="total_cost" type="number" step="0.01" min="0">
                </div>
                <input class="field" name="note" maxlength="200" placeholder="{{ __('Note (supplier, reason…)') }}">
                <button class="btn-primary w-full">{{ __('Save movement') }}</button>
            </form>
        @endif
        <form method="post" action="{{ route('dash.inventory.store') }}" class="card space-y-2">
            @csrf
            <h2 class="section-title">{{ __('Add ingredient') }}</h2>
            @include('dashboard.inventory.fields', ['i' => null])
            <button class="btn-primary w-full">{{ __('Add ingredient') }}</button>
        </form>
    </div>
</div>
@endsection
