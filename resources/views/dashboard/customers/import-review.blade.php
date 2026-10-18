@extends('layouts.dashboard')
@section('title', __('Review import'))

@section('content')
@php
    $counts = $analysis['counts'];
    $preview = array_slice($analysis['guests'], 0, 50);
    $skipped = array_slice($analysis['skipped'], 0, 100);
    $fieldLabels = ['name' => __('Name'), 'phone_2' => __('Second phone'), 'birthday' => __('Birthday'), 'notes' => __('Notes'),
        'marketing_opt_in' => __('Accepts offers'), 'addresses' => __('Address')];
@endphp
<x-dash.page-header :title="__('Review import')">
    <p class="dash-help"><span dir="ltr">{{ $file }}</span></p>
    <x-slot:actions>
        <form method="post" action="{{ route('dash.customers.import.cancel') }}">@csrf<button class="btn-ghost">{{ __('Cancel') }}</button></form>
    </x-slot:actions>
</x-dash.page-header>

<div class="mb-4 grid grid-cols-3 gap-3 sm:gap-4" data-import-counts>
    <div class="stat-card"><div class="stat-label">{{ __('New customers') }}</div><div class="stat-value text-emerald-700" data-count-new>{{ number_format($counts['new']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Already on your list') }}</div><div class="stat-value text-brand-700" data-count-existing>{{ number_format($counts['existing']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Skipped rows') }}</div><div class="stat-value text-navy-500" data-count-skipped>{{ number_format($counts['skipped']) }}</div></div>
</div>

<form method="post" action="{{ route('dash.customers.import.apply', $token) }}" class="space-y-4" data-import-apply>
    @csrf
    @if ($counts['existing'] > 0)
        <div class="card space-y-2" role="radiogroup" aria-labelledby="import-mode-title">
            <h2 id="import-mode-title" class="section-title mb-2">{{ __('Customers already on your list') }}</h2>
            @foreach ([
                'keep' => [__('Leave their details as they are'), 0],
                'fill' => [__('Fill empty fields only'), $counts['fill']],
                'overwrite' => [__('Update all fields from the file'), $counts['overwrite']],
            ] as $mode => [$label, $changes])
                <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl px-3 ring-1 ring-navy-100 has-checked:bg-brand-50 has-checked:ring-brand-500">
                    <input type="radio" name="mode" value="{{ $mode }}" @checked($mode === 'keep') class="size-4 shrink-0 accent-brand-600">
                    <span class="flex-1 text-sm font-semibold text-navy-800">{{ $label }}</span>
                    @if ($mode !== 'keep')<span class="text-xs text-navy-500">{{ __(':n will change', ['n' => number_format($changes)]) }}</span>@endif
                </label>
            @endforeach
            <p class="muted">{{ __('Visits, spend, loyalty and wallet balances are never changed by an import.') }}</p>
        </div>
    @else
        <input type="hidden" name="mode" value="keep">
    @endif
    <x-flash />
    @error('mode')<div class="field-error">{{ $message }}</div>@enderror

    <section class="card overflow-x-auto p-0">
        <h2 class="section-title px-4 pt-4">{{ __('Preview') }} <span class="text-sm font-normal text-navy-500">{{ count($analysis['guests']) > count($preview) ? __('first :n rows', ['n' => count($preview)]) : '' }}</span></h2>
        <table class="table-base" data-import-preview>
            <thead><tr><th>{{ __('Line') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Name') }}</th><th class="hidden sm:table-cell">{{ __('Birthday') }}</th><th class="hidden md:table-cell">{{ __('Address') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @foreach ($preview as $g)
                <tr>
                    <td class="text-navy-400">{{ $g['line'] }}</td>
                    <td dir="ltr" class="whitespace-nowrap text-start">{{ $g['phone'] }}</td>
                    <td>{{ $g['name'] ?? ($g['current_name'] ?: '—') }}</td>
                    <td class="hidden sm:table-cell">{{ $g['birthday'] ?? '—' }}</td>
                    <td class="hidden max-w-56 truncate md:table-cell">{{ $g['address']['text'] ?? '—' }}</td>
                    <td>
                        @if ($g['customer_id'] === null)
                            <span class="badge bg-emerald-100 text-emerald-800">{{ __('New') }}</span>
                        @else
                            <span class="badge bg-brand-50 text-brand-700" title="{{ collect($g['changes']['overwrite'])->map(fn ($f) => $fieldLabels[$f] ?? $f)->implode('، ') }}">{{ __('On your list') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    @if ($skipped !== [])
        <section class="card overflow-x-auto p-0" data-import-skipped>
            <h2 class="section-title px-4 pt-4">{{ __('Skipped rows') }} <span class="text-sm font-normal text-navy-500">{{ __('they will not be imported') }}</span></h2>
            <table class="table-base">
                <thead><tr><th>{{ __('Line') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Value') }}</th></tr></thead>
                <tbody>
                @foreach ($skipped as $s)
                    <tr><td class="text-navy-400">{{ $s['line'] }}</td><td>{{ $s['reason'] }}</td><td dir="auto">{{ $s['value'] ?: '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($analysis['ignored'] !== [])
        <p class="muted">{{ __('Columns not used') }}: <span dir="auto">{{ implode('، ', $analysis['ignored']) }}</span></p>
    @endif

    <div class="sticky bottom-0 z-10 -mx-4 bg-white/95 px-4 py-3 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] sm:static sm:mx-0 sm:bg-transparent sm:p-0 sm:shadow-none">
        <button class="btn-primary w-full sm:w-auto" data-import-confirm>{{ __('Import :n customers', ['n' => number_format(count($analysis['guests']))]) }}</button>
    </div>
</form>
@endsection
