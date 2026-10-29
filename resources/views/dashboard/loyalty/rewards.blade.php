@extends('layouts.dashboard')
@section('title', __('Rewards'))

@section('content')
{{-- «المكافآت» (RewardsController): every reward setting on one page, one section per feature the user may manage. --}}
@php
    $sectionTitles = [
        'program' => __('Loyalty program'), 'welcome' => __('Welcome for new guests'), 'cashback' => __('Cashback'),
        'tiers' => __('Membership tiers'), 'packages' => __('Prepaid packages'), 'wallet' => __('Wallet'),
    ];
    $shown = array_keys(array_filter($sections));
@endphp
<x-dash.page-header :title="__('Rewards')" :help="__('Everything a guest can earn or get, in one place. Each section saves on its own.')">
    <x-slot:actions>
        @if ($sections['program'])<a href="{{ route('dash.coupons') }}" class="btn-ghost">{{ __('Coupons & vouchers') }}</a>@endif
        @if (auth()->user()->can('loyalty.stamp') && \App\Services\Entitlements::current()->allows(\App\Http\Controllers\Dashboard\LoyaltyController::DESK_FEATURES))
            <a href="{{ route('dash.loyalty.desk') }}" class="btn-primary">{{ __('Guest desk') }}</a>
        @endif
    </x-slot:actions>
</x-dash.page-header>

@if (count($shown) > 1)
    <nav class="mb-4 flex flex-wrap gap-2" aria-label="{{ __('Rewards') }}" data-rewards-nav>
        @foreach ($shown as $key)
            <a href="#{{ $key }}" class="btn min-h-10 rounded-full border border-navy-100 bg-white px-4 text-navy-700" data-rewards-nav-item="{{ $key }}">{{ $sectionTitles[$key] }}</a>
        @endforeach
    </nav>
@endif

<div class="space-y-8">
    @if ($sections['program'])
        @include('dashboard.loyalty.partials.rewards-program')
    @endif
    @if ($sections['welcome'])
        @include('dashboard.loyalty.partials.rewards-welcome')
    @endif
    @if ($sections['cashback'] || $sections['tiers'])
        <div class="grid gap-4 xl:grid-cols-2">
            @if ($sections['cashback'])
                @include('dashboard.loyalty.partials.rewards-cashback')
            @endif
            @if ($sections['tiers'])
                @include('dashboard.loyalty.partials.rewards-tiers')
            @endif
        </div>
    @endif
    @if ($sections['packages'])
        @include('dashboard.loyalty.partials.rewards-packages')
    @endif
    @if ($sections['wallet'])
        @include('dashboard.loyalty.partials.rewards-wallet')
    @endif
</div>
@endsection
