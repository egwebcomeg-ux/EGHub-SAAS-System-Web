@extends('layouts.admin')
@section('title', __('Restaurants') . ' · EgMenu Admin')

@section('content')
@php
    $f = $filters;
    $en = app()->getLocale() === 'en';
    // the chips keep every other filter and change only the business type (back to page 1)
    $chipUrl = fn (string $type) => route('admin.tenants', array_filter([...collect($f)->except('type')->all(), 'type' => $type], fn ($v) => $v !== '' && $v !== 'newest'));
    $allCount = array_sum($typeCounts);
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Restaurants') }}</h1>
        <p class="muted">{{ __(':count restaurants', ['count' => number_format($tenants->total())]) }}</p>
    </div>
    @if (auth('admin')->user()->allows('tenants.manage'))
        <a href="{{ route('admin.tenants.create') }}" class="btn-primary">{{ __('New restaurant') }}</a>
    @endif
</div>

{{-- business types: how many places of each match the other filters --}}
<nav class="-mx-1 mb-3 flex gap-2 overflow-x-auto px-1 pb-1" aria-label="{{ __('Business type') }}" data-type-chips>
    <a href="{{ $chipUrl('') }}" @class(['inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-sm font-semibold',
        'border-brand-600 bg-brand-600 text-white' => $f['type'] === '', 'border-navy-200 bg-white text-navy-700 hover:bg-navy-50' => $f['type'] !== ''])>
        {{ __('All') }} <span class="tabular-nums opacity-80">{{ number_format($allCount) }}</span>
    </a>
    @foreach (\App\Support\BusinessType::TYPES as $type => $label)
        <a href="{{ $chipUrl($type) }}" data-type-chip="{{ $type }}" @class(['inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-sm font-semibold',
            'border-brand-600 bg-brand-600 text-white' => $f['type'] === $type, 'border-navy-200 bg-white text-navy-700 hover:bg-navy-50' => $f['type'] !== $type])>
            <span aria-hidden="true">{{ \App\Support\BusinessType::emoji($type) }}</span> {{ $en ? $label[1] : $label[0] }}
            <span class="tabular-nums opacity-80">{{ number_format($typeCounts[$type] ?? 0) }}</span>
        </a>
    @endforeach
</nav>

<form class="card mb-4 grid gap-3" method="get" action="{{ route('admin.tenants') }}" data-tenant-filters>
    @if ($f['type'] !== '')<input type="hidden" name="type" value="{{ $f['type'] }}">@endif
    <div class="flex flex-col gap-2 sm:flex-row">
        <input class="field flex-1" type="search" name="q" value="{{ $f['q'] }}" placeholder="{{ __('Name, link, email, mobile or domain') }}" aria-label="{{ __('Search') }}">
        <div class="flex gap-2">
            <button class="btn-primary flex-1 sm:flex-none">{{ __('Filter') }}</button>
            @if ($filtered)
                <a href="{{ route('admin.tenants') }}" class="btn-ghost flex-1 sm:flex-none" data-filters-reset>{{ __('Reset') }}</a>
            @endif
        </div>
    </div>
    <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Plan') }}
            <select class="field" name="plan">
                <option value="">{{ __('All plans') }}</option>
                @foreach ($plans as $p)<option value="{{ $p->code }}" @selected($f['plan'] === $p->code)>{{ $p->displayName() }}</option>@endforeach
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Status') }}
            <select class="field" name="status">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (\App\Models\Subscription::STATUSES as $key => $l)
                    <option value="{{ $key }}" @selected($f['status'] === $key)>{{ $en ? $l[1] : $l[0] }}</option>
                @endforeach
                <option value="suspended" @selected($f['status'] === 'suspended')>{{ __('Suspended') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Paying') }}
            <select class="field" name="paid">
                <option value="">{{ __('Paid and unpaid') }}</option>
                <option value="paid" @selected($f['paid'] === 'paid')>{{ __('Paying (active, not Free)') }}</option>
                <option value="unpaid" @selected($f['paid'] === 'unpaid')>{{ __('Not paying yet') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Directory') }}
            <select class="field" name="hidden">
                <option value="">{{ __('All places') }}</option>
                <option value="1" @selected($f['hidden'] === '1')>{{ __('Hidden from the directory') }}</option>
                <option value="0" @selected($f['hidden'] === '0')>{{ __('Not hidden') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Created') }}
            <select class="field" name="created">
                <option value="">{{ __('Any time') }}</option>
                <option value="week" @selected($f['created'] === 'week')>{{ __('This week') }}</option>
                <option value="month" @selected($f['created'] === 'month')>{{ __('This month') }}</option>
                <option value="custom" @selected($f['created'] === 'custom')>{{ __('Between dates') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Sort') }}
            <select class="field" name="sort">
                <option value="newest" @selected($f['sort'] === 'newest')>{{ __('Newest first') }}</option>
                <option value="oldest" @selected($f['sort'] === 'oldest')>{{ __('Oldest first') }}</option>
                <option value="name" @selected($f['sort'] === 'name')>{{ __('Name') }}</option>
                <option value="activity" @selected($f['sort'] === 'activity')>{{ __('Last order') }}</option>
            </select>
        </label>
    </div>
    <div class="grid grid-cols-2 gap-2 sm:max-w-md">
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('From') }}<input class="field" type="date" name="from" value="{{ $f['from'] }}" dir="ltr"></label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('To') }}<input class="field" type="date" name="to" value="{{ $f['to'] }}" dir="ltr"></label>
    </div>
</form>

<div class="card">
    @include('admin.tenants.table', ['types' => $types])
    <div class="mt-4">{{ $tenants->links() }}</div>
</div>
@endsection
