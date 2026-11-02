@extends('layouts.base')
@section('title', $title . ' · EgHub')
@section('body_class', 'bg-white text-navy-900')

@push('head')
<style>
    @media print { .no-print { display: none !important; } .report-block { break-inside: avoid; } tr { break-inside: avoid; } }
    @page { margin: 12mm; }
</style>
@endpush

@section('body')
<div class="no-print sticky top-0 z-10 flex items-center justify-between gap-2 border-b border-navy-100 bg-white px-4 py-3">
    <button type="button" class="btn-ghost" onclick="window.close()">{{ __('Close') }}</button>
    <button type="button" class="btn-primary" onclick="window.print()">{{ __('Print') }}</button>
</div>
<main class="mx-auto max-w-4xl px-5 py-6" data-customer-file>
    <header class="mb-4 border-b-4 border-navy-900 pb-3">
        <h1 class="text-2xl font-black">{{ $title }}</h1>
        <p class="text-xs text-navy-500">EgHub · {{ __('Printed :at', ['at' => local_time(now(), 'Y/m/d h:i A')]) }}</p>
    </header>

    <dl class="report-block mb-5 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        @foreach ($details as $label => $value)
            <div><dt class="text-xs font-semibold text-navy-500">{{ $label }}</dt><dd class="font-semibold whitespace-pre-line" dir="auto">{{ $value }}</dd></div>
        @endforeach
    </dl>

    <section class="mb-5" data-file-calls>
        <h2 class="section-title mb-2">{{ __('Phone calls') }}</h2>
        @if ($calls->isEmpty())
            <p class="text-sm text-navy-500">{{ __('No calls here yet.') }}</p>
        @else
            <table class="table-base" data-table-scroll>
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Team member') }}</th><th>{{ __('Duration') }}</th><th>{{ __('Summary') }}</th></tr></thead>
                <tbody>
                    @foreach ($calls as $call)
                        <tr><td class="whitespace-nowrap tabular-nums">{{ local_time($call->started_at, 'Y/m/d h:i A') }}<br><span class="text-xs text-navy-500">{{ $call->directionLabel() }}</span></td>
                            <td>{{ $call->admin?->name ?? '—' }}</td><td class="tabular-nums">{{ $call->durationLabel() }}</td><td dir="auto">{{ $call->notes ?: '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($appointments->isNotEmpty())
        <section class="mb-5" data-file-visits>
            <h2 class="section-title mb-2">{{ __('Visits') }}</h2>
            <table class="table-base" data-table-scroll>
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Field rep') }}</th><th>{{ __('Status') }}</th><th>{{ __('Notes') }}</th></tr></thead>
                <tbody>
                    @foreach ($appointments as $appointment)
                        <tr><td class="whitespace-nowrap tabular-nums">{{ local_time($appointment->scheduled_at, 'Y/m/d h:i A') }}</td><td>{{ $appointment->field?->name ?? '—' }}</td>
                            <td>{{ $appointment->statusLabel() }}</td><td dir="auto">{{ $appointment->notes ?: '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <section data-file-timeline>
        <h2 class="section-title mb-2">{{ __('Timeline') }}</h2>
        @forelse ($events as $event)
            <div class="report-block border-b border-navy-50 py-2 text-sm">
                <div class="font-semibold">{{ $event->typeLabel() }}@if ($event->outcomeLabel()) · {{ $event->outcomeLabel() }}@endif</div>
                <div class="text-xs text-navy-500">{{ local_time($event->created_at, 'Y/m/d h:i A') }}@if ($event->admin) · {{ $event->admin->name }}@endif</div>
                @if ($event->notes)<div class="mt-1 whitespace-pre-line" dir="auto">{{ $event->notes }}</div>@endif
            </div>
        @empty
            <p class="text-sm text-navy-500">{{ __('Nothing recorded yet.') }}</p>
        @endforelse
    </section>
</main>
@endsection
