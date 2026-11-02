@extends('layouts.dashboard')
@section('title', __('Expenses and profit'))

@section('content')
<x-dash.page-header :title="__('Expenses and profit')" />
@include('dashboard.partials.area-tabs', ['area' => 'reports', 'current' => 'expenses'])

<form method="get" class="mb-4 flex flex-wrap items-end gap-2" data-expenses-month>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Month') }}
        <input class="field min-h-10" type="month" name="month" value="{{ $month }}" dir="ltr">
    </label>
    <button class="btn-navy min-h-10 px-5">{{ __('Show') }}</button>
</form>

<div class="grid gap-4 lg:grid-cols-3">
    <section class="card lg:col-span-2" data-profit>
        <h2 class="section-title mb-3">{{ __('What you kept this month') }}</h2>
        @php
            $lines = [
                [__('Sales after refunds'), $profit['sales'], false],
                [__('Tax collected'), $profit['tax'], true],
                [__('Cost of what was sold'), $profit['cost_of_sales'], true],
                [__('Waste'), $profit['waste'], true],
                [__('Expenses written here'), $profit['expenses'], true],
                [__('Expenses paid from the drawer'), $profit['drawer'], true],
            ];
        @endphp
        <dl class="divide-y divide-navy-100 text-sm">
            @foreach ($lines as [$label, $amount, $minus])
                <div class="flex justify-between gap-3 py-2"><dt>{{ $label }}</dt><dd class="font-semibold tabular-nums" dir="ltr">{{ $minus && $amount > 0 ? '-' : '' }}{{ money($amount) }}</dd></div>
            @endforeach
            <div class="flex justify-between gap-3 py-3 text-lg font-extrabold"><dt>{{ __('Net profit') }}</dt><dd @class(['tabular-nums', 'text-red-700' => $profit['net'] < 0]) dir="ltr" data-profit-net>{{ money($profit['net']) }}</dd></div>
        </dl>
        @unless ($profit['costed'])
            <p class="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900" data-profit-not-costed>{{ __('The cost of what you sold is not counted without the inventory pack, so write your purchases here as an expense to see the real profit.') }}</p>
        @else
            <p class="mt-2 text-xs text-navy-500">{{ __('The cost comes from your recipes. An item with no recipe counts as no cost, and do not write purchases as an expense or they count twice.') }}</p>
        @endunless
    </section>

    <form method="post" action="{{ route('dash.expenses.store') }}" class="card space-y-2" data-expense-form>
        @csrf
        <h2 class="section-title">{{ __('Add an expense') }}</h2>
        <label class="field-label" for="expense-category">{{ __('Category') }}</label>
        <select id="expense-category" class="field" name="category" required>
            @foreach (\App\Models\Expense::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(old('category') === $key)>{{ tr($label[0], $label[1]) }}</option>@endforeach
        </select>
        <label class="field-label" for="expense-amount">{{ __('Amount') }}</label>
        <input id="expense-amount" class="field" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}">
        @error('amount')<p class="text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
        <label class="field-label" for="expense-date">{{ __('Date') }}</label>
        <input id="expense-date" class="field" name="spent_on" type="date" required dir="ltr" value="{{ old('spent_on', local_at()->toDateString()) }}">
        @if ($branches->count() > 1)
            <label class="field-label" for="expense-branch">{{ __('Branch') }}</label>
            <select id="expense-branch" class="field" name="branch_id">
                <option value="">{{ __('All branches') }}</option>
                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
            </select>
        @endif
        <label class="field-label" for="expense-note">{{ __('Note') }}</label>
        <input id="expense-note" class="field" name="note" maxlength="200" value="{{ old('note') }}">
        <button class="btn-primary w-full">{{ __('Save') }}</button>
    </form>
</div>

<section class="card mt-4 overflow-x-auto" data-expenses>
    <h2 class="section-title mb-2">{{ __('Expenses this month') }}</h2>
    <table class="w-full min-w-[36rem] text-sm">
        <thead><tr class="border-b border-navy-100 text-xs text-navy-500">
            <th class="py-2 text-start">{{ __('Date') }}</th><th class="py-2 text-start">{{ __('Category') }}</th><th class="py-2 text-start">{{ __('Branch') }}</th><th class="py-2 text-start">{{ __('Note') }}</th><th class="py-2 text-end">{{ __('Amount') }}</th><th></th>
        </tr></thead>
        <tbody class="divide-y divide-navy-100">
            @forelse ($expenses as $e)
                <tr data-expense-row="{{ $e->id }}">
                    <td class="py-2 whitespace-nowrap" dir="ltr">{{ $e->spent_on->format('Y/m/d') }}</td>
                    <td class="py-2">{{ $e->categoryLabel() }}</td>
                    <td class="py-2">{{ $e->branch?->name ?? __('All branches') }}</td>
                    <td class="py-2" dir="auto">{{ $e->note }}</td>
                    <td class="py-2 text-end font-semibold">{{ money($e->amount) }}</td>
                    <td class="py-2 text-end"><form method="post" action="{{ route('dash.expenses.destroy', $e) }}" onsubmit="return confirm(@js(__('Delete this expense?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button></form></td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-navy-500">{{ __('No expenses this month yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="mt-3">{{ $expenses->links() }}</div>
</section>
@endsection
