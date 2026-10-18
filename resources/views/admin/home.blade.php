@extends('layouts.admin')
@section('title', __('Overview') . ' · EgMenu Admin')

@section('content')
@php
    $canTenants = $admin->allows('tenants.view');
    $canBilling = $admin->allows('billing.manage');
    $canServices = $admin->allows('services.manage');
    $signupTotal = (int) array_sum(array_column($signups, 'value'));
    $revenueTotal = array_sum(array_column($revenue, 'value'));
@endphp
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Welcome, :name', ['name' => \Illuminate\Support\Str::before($admin->name, ' ')]) }}</h1>
        <p class="muted">{{ __('What is happening on the platform today.') }}</p>
    </div>
    <div class="flex flex-wrap gap-2" data-quick-actions>
        @if ($admin->allows('tenants.manage'))
            <a href="{{ route('admin.tenants.create') }}" class="btn-primary">{{ __('New restaurant') }}</a>
        @endif
        @if ($canBilling)
            <a href="{{ route('admin.invoices', ['status' => 'awaiting']) }}" class="btn-ghost">{{ __('Confirm transfers') }}</a>
        @endif
        @if ($canServices)
            <a href="{{ route('admin.services') }}" class="btn-ghost">{{ __('Service requests') }}</a>
        @endif
    </div>
</div>

@if ($canTenants)
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" data-kpis="tenants">
        @foreach ([
            [__('Restaurants'), $counts['tenants'], route('admin.tenants'), 'text-navy-900', __('+:n this month', ['n' => $counts['new_this_month']])],
            [__('Active'), $counts['active'], route('admin.tenants', ['status' => 'active']), 'text-emerald-700', __('Paying subscriptions')],
            [__('Trial'), $counts['trialing'], route('admin.tenants', ['status' => 'trialing']), 'text-brand-700', __('On the free trial')],
            [__('Past due'), $counts['past_due'], route('admin.tenants', ['status' => 'past_due']), 'text-amber-700', __('Renewal not paid yet')],
            [__('Suspended'), $counts['suspended'], route('admin.tenants', ['status' => 'suspended']), 'text-navy-500', __('Menu offline')],
        ] as [$label, $value, $href, $tone, $hint])
            <a href="{{ $href }}" class="card block p-4 transition hover:border-navy-200 hover:shadow-md">
                <div class="text-sm font-semibold text-navy-500">{{ $label }}</div>
                <div class="mt-1 text-3xl font-black {{ $tone }}">{{ number_format($value) }}</div>
                <div class="mt-1 truncate text-xs text-navy-500">{{ $hint }}</div>
            </a>
        @endforeach
    </div>
@endif

<div class="mt-3 grid grid-cols-2 gap-3 xl:grid-cols-4" data-kpis="money">
    @if ($canBilling)
        <div class="card p-4">
            <div class="text-sm font-semibold text-navy-500">{{ __('MRR') }}</div>
            <div class="mt-1 text-2xl font-black">{{ money($mrr) }} <span class="text-sm font-bold text-navy-500">{{ __('EGP') }}</span></div>
            <div class="mt-1 text-xs text-navy-500">{{ __('Monthly recurring revenue of active subscriptions') }}</div>
        </div>
        <a href="{{ route('admin.invoices', ['status' => 'open']) }}" class="card block p-4 transition hover:border-navy-200 hover:shadow-md">
            <div class="text-sm font-semibold text-navy-500">{{ __('Unpaid invoices') }}</div>
            <div class="mt-1 text-2xl font-black">{{ money($unpaid) }} <span class="text-sm font-bold text-navy-500">{{ __('EGP') }}</span></div>
            <div class="mt-1 text-xs text-navy-500">{{ __('Open invoices: :count', ['count' => $counts['unpaid_count']]) }}</div>
        </a>
    @endif
    @if ($canTenants)
        <div class="card p-4">
            <div class="text-sm font-semibold text-navy-500">{{ __('New sign-ups this month') }}</div>
            <div class="mt-1 text-2xl font-black">{{ number_format($counts['new_this_month']) }}</div>
            <div class="mt-1 text-xs text-navy-500">{{ __('Restaurants created since the 1st') }}</div>
        </div>
    @endif
    @if ($canServices)
        <a href="{{ route('admin.services') }}" class="card block p-4 transition hover:border-navy-200 hover:shadow-md">
            <div class="text-sm font-semibold text-navy-500">{{ __('Pending service requests') }}</div>
            <div class="mt-1 text-2xl font-black {{ $counts['services_new'] > 0 ? 'text-brand-700' : '' }}">{{ number_format($counts['services_new']) }}</div>
            <div class="mt-1 text-xs text-navy-500">{{ __('New requests nobody answered yet') }}</div>
        </a>
    @endif
</div>

@if ($canTenants || $canBilling)
    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        @if ($canTenants)
            <section class="card">
                <div class="mb-3 flex items-baseline justify-between gap-2">
                    <h2 class="section-title">{{ __('Sign-ups, last 30 days') }}</h2>
                    <span class="text-sm font-bold text-navy-600">{{ number_format($signupTotal) }}</span>
                </div>
                @include('admin.partials.bar-chart', ['series' => $signups, 'label' => __('Sign-ups, last 30 days'), 'empty' => __('No new restaurants in the last 30 days.')])
            </section>
        @endif
        @if ($canBilling)
            <section class="card">
                <div class="mb-3 flex items-baseline justify-between gap-2">
                    <h2 class="section-title">{{ __('Paid invoices, last 30 days') }}</h2>
                    <span class="text-sm font-bold text-navy-600">{{ money($revenueTotal) }} {{ __('EGP') }}</span>
                </div>
                @include('admin.partials.bar-chart', ['series' => $revenue, 'money' => true, 'label' => __('Paid invoices, last 30 days'), 'empty' => __('No paid invoices in the last 30 days.')])
            </section>
        @endif
    </div>
@endif

<div class="mt-4 grid gap-4 xl:grid-cols-2">
    @if ($canTenants)
        <section class="card">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="section-title">{{ __('Latest restaurants') }}</h2>
                <a href="{{ route('admin.tenants') }}" class="text-sm font-semibold text-navy-600 hover:underline">{{ __('All restaurants') }}</a>
            </div>
            @include('admin.tenants.table', ['tenants' => $latest, 'compact' => true])
        </section>
    @endif
    @if ($canBilling)
        <section class="card">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="section-title">{{ __('Latest invoices') }}</h2>
                <a href="{{ route('admin.invoices', ['status' => 'all']) }}" class="text-sm font-semibold text-navy-600 hover:underline">{{ __('All invoices') }}</a>
            </div>
            <ul class="divide-y divide-navy-50" data-latest-invoices>
                @forelse ($invoices as $inv)
                    <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5">
                        <div class="min-w-0">
                            <a href="{{ route('admin.tenants.show', $inv->tenant_id) }}" class="block truncate font-semibold text-navy-900 hover:text-brand-700">{{ $inv->tenant?->name ?? $inv->tenant_id }}</a>
                            <div class="truncate text-xs text-navy-500"><span dir="ltr">{{ $inv->number }}</span> · {{ $inv->description() }} · {{ local_time($inv->created_at, 'Y/m/d') }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="font-bold">{{ money($inv->total) }}</span>
                            @include('partials.invoice-status', ['invoice' => $inv])
                        </div>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-navy-500">{{ __('No invoices here.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
@endsection
