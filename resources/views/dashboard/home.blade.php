@extends('layouts.dashboard')
@section('title', __('Home') . ' · ' . tenant()->name)

@section('content')
@if ($lowStock)
    <a href="{{ route('dash.inventory') }}" class="mb-4 flex items-center justify-between gap-3 rounded-2xl bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
        <span>⚠ {{ __(':n ingredients are running low.', ['n' => $lowStock]) }}</span><span class="underline">{{ __('Inventory') }}</span>
    </a>
@endif
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ([
        [__('Categories'), $stats['categories']],
        [__('Menu items'), $stats['items']],
        [__('Orders today'), $stats['orders_today']],
        [__('Customers'), $stats['customers']],
    ] as [$label, $value])
        <div class="card">
            <div class="text-sm text-navy-500">{{ $label }}</div>
            <div class="mt-1 text-3xl font-extrabold">{{ $value }}</div>
        </div>
    @endforeach
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <h2 class="mb-1 text-lg font-bold">{{ __('Your menu link') }}</h2>
        <p class="mb-4 text-sm text-navy-500">{{ __('Share it on social media, or print a QR card for every table from Tables & QR.') }}</p>
        <div class="flex flex-wrap items-center gap-2">
            <code dir="ltr" class="rounded-lg bg-navy-50 px-3 py-2 text-sm">{{ tenant()->url() }}</code>
            <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-primary">{{ __('Open menu') }}</a>
            @can('tables.manage')<a href="{{ route('dash.tables') }}" class="btn-ghost">{{ __('Tables & QR') }}</a>@endcan
        </div>
    </div>

    <div class="card">
        <h2 class="mb-1 text-lg font-bold">{{ __('Your plan') }}</h2>
        @if ($subscription)
            <p class="mb-4 text-sm text-navy-500">
                {{ $subscription->plan->displayName() }} · {{ $subscription->statusLabel() }}
                @if ($subscription->endsAt()) · {{ __('until') }} {{ local_time($subscription->endsAt(), 'Y/m/d') }} @endif
            </p>
        @endif
        <div class="space-y-3">
            @foreach ($limits as $l)
                <x-usage-bar :label="$l['label']" :used="$l['used']" :limit="$l['limit']" />
            @endforeach
        </div>
    </div>
</div>
@if ($suggestions)
    <section class="card mt-4">
        <h2 class="mb-3 text-lg font-bold">{{ __('Recommended for your :type', ['type' => \App\Support\BusinessType::label($businessType)]) }}</h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($suggestions as $sug)
                @php
                    $has = \App\Services\Entitlements::current()->allows($sug['feature']);
                @endphp
                <div class="rounded-xl border border-navy-100 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <b>{{ $sug['title'] }}</b>
                        @if ($has)<span class="badge bg-emerald-100 text-emerald-800">{{ __('Included') }}</span>@endif
                    </div>
                    <p class="mt-1 text-sm text-navy-500">{{ $sug['text'] }}</p>
                    @unless ($has)<a href="{{ route('dash.billing') }}" class="mt-2 inline-block text-sm font-semibold text-brand-700">{{ __('Add') }} ←</a>@endunless
                </div>
            @endforeach
        </div>
    </section>
@endif
@endsection
