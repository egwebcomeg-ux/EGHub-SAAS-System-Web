@extends('layouts.admin')
@section('title', __('Restaurants') . ' · EgMenu Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Restaurants') }}</h1>
        <p class="muted">{{ __(':count restaurants', ['count' => number_format($tenants->total())]) }}</p>
    </div>
    @if (auth('admin')->user()->allows('tenants.manage'))
        <a href="{{ route('admin.tenants.create') }}" class="btn-primary">{{ __('New restaurant') }}</a>
    @endif
</div>
<form class="mb-4 grid gap-2 sm:flex sm:flex-wrap">
    <input class="field sm:max-w-xs" name="q" value="{{ $q }}" placeholder="{{ __('Search name or link') }}" aria-label="{{ __('Search name or link') }}">
    <div class="flex gap-2">
        <select class="field sm:w-48" name="status" aria-label="{{ __('Status') }}">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Models\Subscription::STATUSES as $key => $l)
                <option value="{{ $key }}" @selected($status === $key)>{{ app()->getLocale() === 'en' ? $l[1] : $l[0] }}</option>
            @endforeach
            <option value="suspended" @selected($status === 'suspended')>{{ __('Suspended') }}</option>
        </select>
        <button class="btn-ghost shrink-0">{{ __('Filter') }}</button>
    </div>
</form>
<div class="card">
    @include('admin.tenants.table')
    <div class="mt-4">{{ $tenants->links() }}</div>
</div>
@endsection
