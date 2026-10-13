@extends('layouts.admin')
@section('title', __('Restaurants') . ' · EgMenu Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ __('Restaurants') }}</h1>
    <a href="{{ route('admin.tenants.create') }}" class="btn-primary">{{ __('New restaurant') }}</a>
</div>
<form class="mb-4 flex flex-wrap gap-2">
    <input class="field max-w-xs" name="q" value="{{ $q }}" placeholder="{{ __('Search name or link') }}">
    <select class="field max-w-48" name="status">
        <option value="">{{ __('All statuses') }}</option>
        @foreach (\App\Models\Subscription::STATUSES as $key => $l)
            <option value="{{ $key }}" @selected($status === $key)>{{ app()->getLocale() === 'en' ? $l[1] : $l[0] }}</option>
        @endforeach
        <option value="suspended" @selected($status === 'suspended')>{{ __('Suspended') }}</option>
    </select>
    <button class="btn-ghost">{{ __('Filter') }}</button>
</form>
<div class="card overflow-x-auto">
    @include('admin.tenants.table')
    <div class="mt-4">{{ $tenants->links() }}</div>
</div>
@endsection
