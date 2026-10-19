{{-- What the picked modules mean for the plan (App\Services\ModuleChoice::coverage). Informs only, never subscribes. --}}
@php
    $best = $coverage['best'];
@endphp
<h2 class="section-title">{{ __('Your plan and your pick') }}</h2>
<p class="muted mt-1">
    @if ($coverage['trialing'])
        {{ __('You are on the :plan trial: everything in it works until the trial ends.', ['plan' => $coverage['plan']->displayName()]) }}
    @else
        {{ __('Your plan now: :plan.', ['plan' => $coverage['plan']->displayName()]) }}
    @endif
</p>

@if ($coverage['included'] !== [])
    <div class="mt-4" data-summary-included>
        <div class="mb-1.5 text-xs font-bold text-navy-600">{{ $coverage['trialing'] ? __('Working now in your trial') : __('Included in your plan') }}</div>
        <ul class="flex flex-wrap gap-1.5">
            @foreach ($coverage['included'] as $m)
                <li class="badge bg-navy-50 text-navy-700">{{ $m['name'] }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($coverage['missing'] !== [])
    <div class="mt-4" data-summary-missing>
        <div class="mb-1.5 text-xs font-bold text-brand-700">{{ __('Needs a higher plan or an add-on') }}</div>
        <ul class="space-y-1.5">
            @foreach ($coverage['missing'] as $row)
                <li class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm" data-summary-missing-module="{{ $row['module']['slug'] }}">
                    <span class="font-semibold text-navy-900">{{ $row['module']['name'] }}</span>
                    @if ($row['where'] !== '')<span class="text-xs text-navy-500">{{ $row['where'] }}</span>@endif
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div class="mt-4 rounded-xl bg-navy-50 p-3 text-sm text-navy-800" data-summary-best="{{ $best ? $best['plan']->code : '' }}">
    @if ($best && $best['current'])
        {{ __('Your current plan covers everything you picked.') }}
    @elseif ($best)
        <div class="text-xs font-bold text-navy-600">{{ $coverage['trialing'] ? __('To keep all of it after the trial') : __('The cheapest way to get all of it') }}</div>
        <div class="mt-1 font-bold text-navy-900">
            {{ __(':plan plan', ['plan' => $best['plan']->displayName()]) }}@foreach ($best['addons'] as $addon) + <span data-summary-addon="{{ $addon->key }}">{{ $addon->displayName() }}</span>@endforeach
        </div>
        <div class="mt-0.5 text-navy-600">{{ __('About :price EGP / month', ['price' => money($best['total'])]) }}</div>
    @elseif ($coverage['missing'] === [])
        {{ __('Your current plan covers everything you picked.') }}
    @elseif ($coverage['contact'] ?? null)
        <div data-summary-contact="{{ $coverage['contact']->code }}">
            <div class="text-xs font-bold text-navy-600">{{ __('What you picked is in the :plan plan', ['plan' => $coverage['contact']->displayName()]) }}</div>
            <p class="mt-1 text-navy-700">{{ __('Its price is agreed with you, for the branches and screens you need. It also includes:') }}</p>
            <div class="mt-2">@include('partials.enterprise-includes', ['listClass' => 'space-y-1.5 text-xs'])</div>
            <a href="{{ $coverage['contact']->contactUrl(tenant()->name) }}" target="_blank" rel="noopener" class="btn-navy mt-3 w-full" data-contact-sales-link>{{ __('Contact us') }}</a>
        </div>
    @else
        {{ __('Some of what you picked is not sold in one package yet. Message us and we will sort it out.') }}
    @endif
</div>

@can('billing.manage')
    <a href="{{ route('dash.billing') }}" class="btn-ghost mt-3 w-full">{{ __('See plans and add-ons') }}</a>
@endcan
<p class="mt-2 text-xs text-navy-500">{{ __('Picking a module does not change your subscription or charge anything.') }}</p>
