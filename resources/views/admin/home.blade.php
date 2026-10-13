@extends('layouts.admin')
@section('title', __('Overview') . ' · EgMenu Admin')

@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="text-2xl font-extrabold">{{ __('Overview') }}</h1>
    <a href="{{ route('admin.tenants.create') }}" class="btn-primary">{{ __('New restaurant') }}</a>
</div>
<div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
    @foreach ([
        [__('Restaurants'), $counts['tenants']],
        [__('Trial'), $counts['trialing']],
        [__('Active'), $counts['active']],
        [__('Past due'), $counts['past_due']],
        [__('Suspended'), $counts['suspended']],
        [__('MRR'), money($mrr) . ' ' . __('EGP')],
    ] as [$label, $value])
        <div class="card">
            <div class="text-sm text-navy-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-extrabold">{{ $value }}</div>
        </div>
    @endforeach
</div>

<div class="card mt-4 overflow-x-auto">
    <h2 class="mb-3 text-lg font-bold">{{ __('Latest restaurants') }}</h2>
    @include('admin.tenants.table', ['tenants' => $latest])
</div>
@endsection
