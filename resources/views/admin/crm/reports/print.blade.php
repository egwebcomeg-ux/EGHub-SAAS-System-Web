@extends('layouts.base')
@section('title', __('Sales report') . ' · EgHub')
@section('body_class', 'bg-white text-navy-900')

@push('head')
<style>
    @media print { .no-print { display: none !important; } .report-block { break-inside: avoid; } a { text-decoration: none; } }
    @page { margin: 12mm; }
</style>
@endpush

@section('body')
<div class="no-print sticky top-0 z-10 flex items-center justify-between gap-2 border-b border-navy-100 bg-white px-4 py-3">
    <a href="{{ route('admin.crm.reports', $query) }}" class="btn-ghost">← {{ __('Back') }}</a>
    <button type="button" class="btn-primary" onclick="window.print()">{{ __('Print') }}</button>
</div>
<main class="mx-auto max-w-4xl px-5 py-6" data-report-print-page>
    <header class="mb-4 border-b-4 border-navy-900 pb-3">
        <h1 class="text-2xl font-black">{{ __('Sales report') }} · EgHub</h1>
        <p class="mt-1 text-sm text-navy-600">{{ $person?->name ?? __('All the team') }} · <span dir="ltr">{{ $from }} → {{ $to }}</span></p>
        <p class="text-xs text-navy-500">{{ __('Printed :at', ['at' => local_time(now(), 'Y/m/d h:i A')]) }}</p>
    </header>
    @include('admin.crm.reports.body')
</main>
@endsection
