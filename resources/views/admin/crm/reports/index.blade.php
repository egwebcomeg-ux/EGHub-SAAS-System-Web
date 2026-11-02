@extends('layouts.admin')
@section('title', __('Sales report') . ' · EgHub Admin')

@section('content')
@include('admin.crm.partials.tabs')

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Sales report') }}</h1>
        <p class="muted">{{ $person?->name ?? __('All the team') }} · <span dir="ltr">{{ $from }} → {{ $to }}</span></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.crm.reports.export', $query) }}" class="btn-ghost" data-report-export>{{ __('Export CSV') }}</a>
        <a href="{{ route('admin.crm.reports.print', $query) }}" target="_blank" rel="noopener" class="btn-primary" data-report-print>{{ __('Print') }}</a>
    </div>
</div>

<form method="get" action="{{ route('admin.crm.reports') }}" class="card mb-4 grid gap-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end" data-report-filters>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('From') }}<input class="field" type="date" name="from" value="{{ $from }}" dir="ltr"></label>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('To') }}<input class="field" type="date" name="to" value="{{ $to }}" dir="ltr"></label>
    @if ($team->isNotEmpty())
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Team member') }}
            <select class="field" name="admin">
                <option value="">{{ __('All the team') }}</option>
                @foreach ($team as $member)<option value="{{ $member->id }}" @selected($person?->id === $member->id)>{{ $member->name }}</option>@endforeach
            </select>
        </label>
    @else
        <div></div>
    @endif
    <button class="btn-navy">{{ __('Filter') }}</button>
</form>

@include('admin.crm.reports.body')
@endsection
