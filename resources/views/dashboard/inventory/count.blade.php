@extends('layouts.dashboard')
@section('title', __('Stock count') . ' #' . $count->id)

@section('content')
@php
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    $draft = $count->isDraft();
@endphp
<x-dash.page-header :title="__('Stock count') . ' #' . $count->id . ' · ' . $count->branch?->name"
    :help="$draft ? __('Type what is on the shelf. Leave an item empty if you did not count it. Save to continue later; a manager posts the count.') : __('This count is posted: the stock was corrected to it. It can no longer change.')">
    <x-slot:actions>
        <a href="{{ route('dash.inventory.counts') }}" class="btn-ghost">{{ __('Back') }}</a>
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.inventory.tabs')

<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
    <div class="stat-card"><div class="stat-label">{{ __('Status') }}</div><div class="stat-value text-lg">{{ $count->statusLabel() }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Counted') }}</div><div class="stat-value" dir="ltr">{{ $lines->whereNotNull('counted')->count() }} / {{ $lines->count() }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Missing (money)') }}</div><div @class(['stat-value', 'text-red-600' => $shortage < 0]) data-count-shortage>{{ money(abs($shortage)) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Extra (money)') }}</div><div class="stat-value text-emerald-700" data-count-surplus>{{ money($surplus) }}</div></div>
</div>

<form method="post" action="{{ route('dash.inventory.counts.save', $count) }}" class="card overflow-x-auto" data-count-sheet>
    @csrf
    <table class="table-base">
        <thead><tr><th>{{ __('Ingredient') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Expected') }}</th><th class="w-40">{{ __('Counted') }}</th><th>{{ __('Difference') }}</th><th>{{ __('Value') }}</th></tr></thead>
        <tbody>
        @foreach ($lines as $line)
            <tr data-line="{{ $line->id }}">
                <td class="font-semibold">{{ $line->ingredient?->name }} <span class="text-xs font-normal text-navy-500">{{ $line->ingredient?->unitLabel() }}</span></td>
                <td class="text-xs text-navy-500">{{ $line->ingredient?->kindLabel() }}</td>
                <td dir="ltr" class="text-end text-navy-600">{{ $qty($line->shown_expected) }}</td>
                <td>
                    @if ($draft)
                        <input class="field min-h-9" name="counted[{{ $line->id }}]" type="number" step="0.001" min="0" inputmode="decimal"
                               value="{{ old('counted.' . $line->id, $line->counted === null ? '' : $qty($line->counted)) }}">
                    @else
                        <span dir="ltr" class="font-bold">{{ $line->counted === null ? '—' : $qty($line->counted) }}</span>
                    @endif
                </td>
                <td dir="ltr" @class(['text-end font-bold', 'text-red-600' => $line->diff < 0, 'text-emerald-700' => $line->diff > 0])>
                    {{ $line->diff === null ? '—' : ($line->diff > 0 ? '+' : '') . $qty($line->diff) }}
                </td>
                <td dir="ltr" @class(['text-end', 'text-red-600' => $line->diff_value < 0, 'text-emerald-700' => $line->diff_value > 0])>{{ $line->diff_value === null ? '—' : money($line->diff_value) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if ($draft)
        <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn-primary">{{ __('Save draft') }}</button>
            @if ($isManager)
                <button class="btn-ghost" formaction="{{ route('dash.inventory.counts.post', $count) }}" data-count-post
                        onclick="return confirm(@js(__('Post this count? The stock of every counted item becomes what you counted.')))">{{ __('Save and post (manager)') }}</button>
            @else
                <p class="self-center text-xs text-navy-500">{{ __('A manager posts the count when it is done.') }}</p>
            @endif
        </div>
    @else
        <p class="mt-3 text-sm text-navy-500">{{ __('Posted by :name on :date', ['name' => $count->poster?->name ?? '—', 'date' => local_time($count->posted_at)]) }}</p>
    @endif
</form>

@if ($draft)
    <form method="post" action="{{ route('dash.inventory.counts.destroy', $count) }}" class="mt-3" onsubmit="return confirm(@js(__('Delete this draft count?')))">
        @csrf
        <button class="btn-ghost min-h-9 px-3 text-sm text-red-600">{{ __('Delete draft') }}</button>
    </form>
@endif
@endsection
