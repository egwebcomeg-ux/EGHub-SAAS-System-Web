@extends('layouts.admin')
@section('title', __('Subscriptions & revenue') . ' · EgHub Admin')

@section('content')
@php
    $f = $filters;
    $en = app()->getLocale() === 'en';
    $canTenants = auth('admin')->user()->allows('tenants.view');
    $revenueMax = max(1, ...array_column($revenue, 'value'));
    $revenueTotal = array_sum(array_column($revenue, 'value'));
    $exportQuery = array_filter(['plan' => $f['plan'], 'status' => $f['status']], fn ($v) => $v !== '');
    $tenantLink = fn (?string $id, ?string $name) => $canTenants && $id
        ? '<a href="'.e(route('admin.tenants.show', $id)).'" class="font-semibold text-navy-800 hover:text-brand-700">'.e($name ?: $id).'</a>'
        : e($name ?: $id);
@endphp
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Subscriptions & revenue') }}</h1>
        <p class="muted">{{ __('Subscriptions by plan, the monthly recurring revenue and the paid invoices, from the billing records.') }}</p>
    </div>
    <a href="{{ route('admin.subscriptions.export', $exportQuery) }}" class="btn-ghost" data-subscriptions-export>{{ __('Export CSV') }}</a>
</div>

<div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" data-subscription-totals>
    @foreach ([
        ['mrr', __('MRR'), money($mrr).' '.__('EGP'), 'text-navy-900', __('Active subscriptions on a paid plan, per month')],
        ['paying', __('Paying restaurants'), number_format($totals['paying']), 'text-emerald-700', __('Active on a paid plan')],
        ['trialing', __('Trial'), number_format($totals['trialing']), 'text-brand-700', __('On the free trial')],
        ['past_due', __('Past due'), number_format($totals['past_due']), 'text-amber-700', __('Renewal not paid yet')],
        ['free', __('On the Free plan'), number_format($totals['free']), 'text-navy-700', __('Trial or subscription ended')],
        ['canceled', __('Canceled'), number_format($totals['canceled']), 'text-navy-500', __('Canceled subscriptions')],
    ] as [$key, $label, $value, $tone, $hint])
        <div class="card p-4" data-total="{{ $key }}">
            <div class="text-sm font-semibold text-navy-500">{{ $label }}</div>
            <div class="mt-1 truncate text-2xl font-black {{ $tone }}">{{ $value }}</div>
            <div class="mt-1 truncate text-xs text-navy-500">{{ $hint }}</div>
        </div>
    @endforeach
</div>

<section class="card mt-4 overflow-x-auto" data-by-plan>
    <h2 class="section-title mb-3">{{ __('By plan') }}</h2>
    <table class="table-base">
        <thead><tr>
            <th>{{ __('Plan') }}</th>
            @foreach (\App\Models\Subscription::STATUSES as $l)<th>{{ $en ? $l[1] : $l[0] }}</th>@endforeach
            <th>{{ __('Total') }}</th><th>{{ __('MRR') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($byPlan as $row)
            <tr data-plan-row="{{ $row['plan']->code }}">
                <td class="font-semibold">{{ $row['plan']->displayName() }}</td>
                @foreach ($row['counts'] as $status => $n)<td class="tabular-nums" data-count="{{ $status }}">{{ number_format($n) }}</td>@endforeach
                <td class="tabular-nums font-bold">{{ number_format($row['total']) }}</td>
                <td class="tabular-nums" data-plan-mrr>{{ money($row['mrr']) }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ count(\App\Models\Subscription::STATUSES) + 3 }}" class="py-8 text-center text-navy-500">{{ __('No subscriptions yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <section class="card" data-revenue>
        <div class="mb-3 flex items-baseline justify-between gap-2">
            <h2 class="section-title">{{ __('Paid invoices per month') }}</h2>
            <span class="text-sm font-bold text-navy-600">{{ money($revenueTotal) }} {{ __('EGP') }}</span>
        </div>
        @if ($revenueTotal <= 0)
            <p class="rounded-xl border border-dashed border-navy-200 py-8 text-center text-sm text-navy-500">{{ __('No paid invoices in the last 12 months.') }}</p>
        @else
            <ul class="space-y-1.5">
                @foreach ($revenue as $point)
                    <li class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-2 text-sm" data-revenue-month="{{ $point['month'] }}">
                        <span class="tabular-nums text-navy-500" dir="ltr">{{ $point['month'] }}</span>
                        <span class="h-4 overflow-hidden rounded-full bg-navy-50">
                            <span class="block h-full rounded-full bg-brand-600" style="width: {{ $point['value'] > 0 ? max(2, round($point['value'] / $revenueMax * 100, 1)) : 0 }}%"></span>
                        </span>
                        <span class="min-w-20 text-end font-semibold tabular-nums" title="{{ __(':count invoices', ['count' => $point['count']]) }}">{{ money($point['value']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card" data-movement>
        <form method="get" class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <h2 class="section-title">{{ __('New and churned') }}</h2>
            @foreach (['plan', 'status'] as $keep)
                @if ($f[$keep] !== '')<input type="hidden" name="{{ $keep }}" value="{{ $f[$keep] }}">@endif
            @endforeach
            <div class="flex items-end gap-2">
                <label class="sr-only" for="f-month">{{ __('Month') }}</label>
                <input id="f-month" type="month" name="month" value="{{ $f['month'] }}" class="field">
                <button class="btn-navy">{{ __('Filter') }}</button>
            </div>
        </form>
        <div class="grid grid-cols-3 gap-2">
            @foreach ([
                ['new', __('Started paying'), $movement['new']->count(), 'text-emerald-700'],
                ['churned', __('Stopped paying'), $movement['churned']->count(), 'text-red-700'],
                ['signups', __('New sign-ups'), $movement['signups'], 'text-navy-900'],
            ] as [$key, $label, $n, $tone])
                <div class="rounded-xl border border-navy-100 p-3" data-movement-count="{{ $key }}">
                    <div class="text-xs font-semibold text-navy-500">{{ $label }}</div>
                    <div class="text-2xl font-black {{ $n > 0 ? $tone : 'text-navy-300' }}">{{ number_format($n) }}</div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-navy-500">{{ __('Started paying: the first paid plan invoice was paid this month. Stopped paying: paid before, and the paid access ended this month without a renewal (past due, moved to the Free plan or canceled).') }}</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 text-sm">
            @foreach (['new' => __('Started paying'), 'churned' => __('Stopped paying')] as $key => $label)
                <div data-movement-list="{{ $key }}">
                    <div class="mb-1 font-bold text-navy-700">{{ $label }}</div>
                    <ul class="space-y-0.5">
                        @forelse ($movement[$key] as $tenant)
                            <li class="truncate">{!! $tenantLink($tenant->id, $tenant->name) !!}</li>
                        @empty
                            <li class="text-navy-400">—</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
</div>

<section class="card mt-4 overflow-x-auto" data-expiring>
    <h2 class="section-title mb-3">{{ __('Ending in the next :days days', ['days' => \App\Services\AdminSubscriptionReport::EXPIRING_DAYS]) }}</h2>
    <table class="table-base">
        <thead><tr><th>{{ __('Restaurant') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th><th>{{ __('Ends') }}</th><th>{{ __('Days left') }}</th></tr></thead>
        <tbody>
        @forelse ($expiring as $sub)
            <tr data-expiring-row="{{ $sub->tenant_id }}">
                <td>{!! $tenantLink($sub->tenant_id, $sub->tenant?->name) !!}</td>
                <td>{{ $sub->plan?->displayName() }}</td>
                <td><span class="badge {{ $sub->status === 'trialing' ? 'bg-brand-50 text-brand-800' : 'bg-emerald-100 text-emerald-800' }}">{{ $sub->statusLabel() }}</span></td>
                <td class="whitespace-nowrap">{{ local_time($sub->endsAt(), 'Y/m/d') }}</td>
                <td class="tabular-nums font-bold {{ $sub->daysLeft() <= 3 ? 'text-red-700' : '' }}">{{ $sub->daysLeft() }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-8 text-center text-navy-500">{{ __('Nothing ends in the next :days days.', ['days' => \App\Services\AdminSubscriptionReport::EXPIRING_DAYS]) }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>

<section class="card mt-4" data-subscriptions-list>
    <form method="get" class="mb-3 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
        <input type="hidden" name="month" value="{{ $f['month'] }}">
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
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button class="btn-navy flex-1">{{ __('Filter') }}</button>
            @if ($f['plan'] !== '' || $f['status'] !== '')
                <a href="{{ route('admin.subscriptions', ['month' => $f['month']]) }}" class="btn-ghost">{{ __('Reset') }}</a>
            @endif
        </div>
    </form>
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead><tr><th>{{ __('Restaurant') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th><th>{{ __('Cycle') }}</th><th>{{ __('Ends') }}</th><th>{{ __('Monthly value') }}</th></tr></thead>
            <tbody>
            @forelse ($subscriptions as $sub)
                <tr data-subscription-row="{{ $sub->tenant_id }}">
                    <td>{!! $tenantLink($sub->tenant_id, $sub->tenant?->name) !!}<div class="text-xs text-navy-400" dir="ltr">{{ $sub->tenant_id }}</div></td>
                    <td>{{ $sub->plan?->displayName() }}</td>
                    <td>{{ $sub->statusLabel() }}</td>
                    <td class="whitespace-nowrap">{{ cycle_label((int) $sub->cycle_months) }}</td>
                    <td class="whitespace-nowrap">{{ local_time($sub->endsAt(), 'Y/m/d') }}</td>
                    <td class="tabular-nums">{{ money(\App\Services\AdminSubscriptionReport::monthlyValue($sub)) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-navy-500">{{ __('No subscriptions for these filters.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $subscriptions->links() }}</div>
</section>
@endsection
