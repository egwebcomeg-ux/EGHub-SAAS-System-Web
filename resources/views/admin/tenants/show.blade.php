@extends('layouts.admin')
@section('title', $tenant->name . ' · EgMenu Admin')

@section('content')
@php($sub = $tenant->subscription)
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-extrabold">{{ $tenant->name }}</h1>
        <a href="{{ $tenant->url() }}" target="_blank" rel="noopener" dir="ltr" class="text-sm text-brand-700 hover:underline">{{ $tenant->url() }}</a>
    </div>
    @if ($tenant->isSuspended())
        <form method="post" action="{{ route('admin.tenants.activate', $tenant) }}">@csrf<button class="btn-primary">{{ __('Activate') }}</button></form>
    @else
        <form method="post" action="{{ route('admin.tenants.suspend', $tenant) }}" onsubmit="return confirm(@js(__('Take this menu offline?')))">@csrf<button class="btn-danger">{{ __('Suspend') }}</button></form>
    @endif
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <form method="post" action="{{ route('admin.tenants.subscription', $tenant) }}" class="card space-y-3 lg:col-span-2">
        @csrf
        <h2 class="text-lg font-bold">{{ __('Subscription') }}</h2>
        <p class="text-sm text-navy-500">{{ __('Use after confirming a manual payment (InstaPay / Vodafone Cash).') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="field-label">{{ __('Plan') }}</label>
                <select class="field" name="plan_id">
                    @foreach ($plans as $p)
                        <option value="{{ $p->id }}" @selected($sub?->plan_id === $p->id)>{{ $p->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label">{{ __('Status') }}</label>
                <select class="field" name="status">
                    @foreach (\App\Models\Subscription::STATUSES as $key => $l)
                        <option value="{{ $key }}" @selected($sub?->status === $key)>{{ app()->getLocale() === 'en' ? $l[1] : $l[0] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label">{{ __('Billing cycle') }}</label>
                <select class="field" name="cycle_months">
                    @foreach (\App\Models\Plan::CYCLES as $m)
                        <option value="{{ $m }}" @selected(($sub?->cycle_months ?? 1) === $m)>{{ cycle_label($m) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="field-label">{{ __('Paid until') }}</label>
                <input class="field" type="date" name="current_period_end" value="{{ $sub?->current_period_end?->format('Y-m-d') }}">
            </div>
            <div>
                <label class="field-label">{{ __('Trial ends') }}</label>
                <input class="field" type="date" name="trial_ends_at" value="{{ $sub?->trial_ends_at?->format('Y-m-d') }}">
            </div>
        </div>
        <button class="btn-primary">{{ __('Save subscription') }}</button>
    </form>

    <div class="card space-y-3">
        <h2 class="text-lg font-bold">{{ __('Usage') }}</h2>
        @foreach ($usage as $key => $u)
            <x-usage-bar :label="\App\Models\Plan::label($key)" :used="$u['used']" :limit="$u['limit']" />
        @endforeach
    </div>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <form method="post" action="{{ route('admin.tenants.addons', $tenant) }}" class="card space-y-2">
        @csrf
        <h2 class="mb-1 text-lg font-bold">{{ __('Add-ons') }}</h2>
        @foreach ($addons as $a)
            <label class="flex min-h-10 items-center justify-between gap-3 text-sm">
                <span>{{ $a->displayName() }} <span class="text-navy-500">· {{ money($a->monthly_price) }} {{ __('EGP / month') }}</span></span>
                @if ($a->isFeaturePack())
                    <span><input type="hidden" name="addons[{{ $a->key }}]" value="0"><input type="checkbox" name="addons[{{ $a->key }}]" value="1" @checked(($myAddons[$a->key] ?? 0) > 0) class="size-5 accent-brand-600"></span>
                @else
                    <input class="field w-20" type="number" min="0" max="50" name="addons[{{ $a->key }}]" value="{{ $myAddons[$a->key] ?? 0 }}">
                @endif
            </label>
        @endforeach
        <button class="btn-primary">{{ __('Save add-ons') }}</button>
    </form>

    <div class="card overflow-x-auto">
        <h2 class="mb-3 text-lg font-bold">{{ __('Invoices') }}</h2>
        <table class="table-base">
            <tbody>
            @forelse ($invoices as $inv)
                <tr>
                    <td dir="ltr" class="text-start">{{ $inv->number }}</td>
                    <td>{{ $inv->description() }}</td>
                    <td>{{ money($inv->total) }}</td>
                    <td>@include('partials.invoice-status', ['invoice' => $inv])</td>
                </tr>
            @empty
                <tr><td class="py-4 text-center text-navy-500">{{ __('No invoices here.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-4 overflow-x-auto">
    <h2 class="mb-3 text-lg font-bold">{{ __('Staff') }}</h2>
    <table class="table-base">
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Login') }}</th><th>{{ __('Role') }}</th><th>{{ __('Last login') }}</th></tr></thead>
        <tbody>
        @foreach ($users as $u)
            <tr>
                <td>{{ $u->name }}</td>
                <td dir="ltr" class="text-start">{{ $u->email ?? $u->phone }}</td>
                <td>{{ $u->roleName() }}</td>
                <td>{{ local_time($u->last_login_at) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
