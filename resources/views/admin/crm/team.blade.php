@extends('layouts.admin')
@section('title', __('Sales team') . ' · EgHub Admin')

@section('content')
@include('admin.crm.partials.tabs')

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Sales team') }}</h1>
        <p class="muted">{{ __('What each person did this month against the target, and what they earn.') }}</p>
    </div>
    <form method="get" class="flex items-center gap-2">
        <input type="month" name="month" value="{{ $month }}" class="field" dir="ltr" aria-label="{{ __('Month') }}">
        <button class="btn-ghost">{{ __('Show') }}</button>
    </form>
</div>

{{-- the team's month as a funnel --}}
<section class="card mb-4" data-funnel>
    <h2 class="section-title mb-3">{{ __('The month, step by step') }}</h2>
    <div class="grid grid-cols-5 gap-2 text-center">
        @foreach (['calls' => __('Calls'), 'appointments' => __('Visits booked'), 'visits' => __('Visits'), 'trials' => __('Signed up'), 'paid' => __('Paid')] as $key => $label)
            <div class="rounded-xl bg-navy-50 p-2">
                <div class="text-2xl font-black text-navy-900">{{ number_format($funnel[$key]) }}</div>
                <div class="text-xs font-semibold text-navy-500">{{ $label }}</div>
            </div>
        @endforeach
    </div>
</section>

@forelse ($cards as $card)
    @php($m = $card['admin'])
    <div class="mb-6" data-member="{{ $m->id }}">
        @include('admin.crm.partials.progress', ['card' => $card, 'title' => $m->name])
        @if (($overdue[$m->id] ?? 0) > 0)
            <p class="-mt-2 mb-3 text-sm font-semibold text-red-700">{{ __(':n follow-ups overdue', ['n' => $overdue[$m->id]]) }}</p>
        @endif
        <details class="card text-sm">
            <summary class="cursor-pointer font-bold text-navy-800">{{ __('Targets of :month', ['month' => $month]) }}</summary>
            <form method="post" action="{{ route('admin.crm.team.targets', $m) }}" class="mt-3 grid gap-3 sm:grid-cols-3">
                @csrf
                <input type="hidden" name="month" value="{{ $month }}">
                @foreach (\App\Models\SalesTarget::METRICS as $metric => $l)
                    <div><label class="field-label" for="t-{{ $m->id }}-{{ $metric }}">{{ \App\Models\Lead::pick($l) }}</label>
                        <input id="t-{{ $m->id }}-{{ $metric }}" type="number" min="0" name="{{ $metric }}" value="{{ $card['targets'][$metric] }}" class="field"></div>
                @endforeach
                <button class="btn-navy sm:col-span-3">{{ __('Save targets') }}</button>
            </form>
        </details>
    </div>
@empty
    <p class="card py-8 text-center text-navy-500">{{ __('No one on the sales team yet. Add them in Admin team with the role Sales or Field rep.') }}</p>
@endforelse

@if ($admin->isOwner())
    <details class="card mt-6" data-pay-plan>
        <summary class="cursor-pointer font-bold text-navy-800">{{ __('Pay plan numbers') }}</summary>
        <form method="post" action="{{ route('admin.crm.team.plan') }}" class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @csrf
            @foreach (\App\Support\SalesPlan::EDITABLE as $path => $l)
                <div><label class="field-label" for="sp-{{ $loop->index }}">{{ \App\Models\Lead::pick($l) }}</label>
                    <input id="sp-{{ $loop->index }}" type="number" step="any" min="0" name="{{ str_replace('.', '__', $path) }}" value="{{ data_get($plan, $path) }}" class="field"></div>
            @endforeach
            <button class="btn-primary sm:col-span-2 lg:col-span-3">{{ __('Save the pay plan') }}</button>
        </form>
    </details>
@endif
@endsection
