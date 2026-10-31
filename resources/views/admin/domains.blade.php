@extends('layouts.admin')
@section('title', __('Domain requests') . ' · EgHub Admin')

@section('content')
@php
    $canTenants = auth('admin')->user()->allows('tenants.view');
@endphp
<div class="mb-4">
    <h1 class="page-title">{{ __('Domain requests') }}</h1>
    <p class="muted">{{ __('Restaurants that entered their own domain. Add it on the server with SSL, then approve it; the menu and every QR code switch to it. A rejected domain shows your reason on the owner\'s domain page.') }}</p>
</div>

<nav class="-mx-1 mb-4 flex gap-1 overflow-x-auto pb-1" aria-label="{{ __('Status') }}" data-domain-filters>
    @foreach (['' => __('All'), 'waiting' => __('Waiting'), 'active' => __('Connected'), 'rejected' => __('Rejected')] as $key => $label)
        @php($on = $filter === $key)
        <a href="{{ route('admin.domains', $key === '' ? [] : ['status' => $key]) }}" @if ($on) aria-current="page" @endif @class(['shrink-0 rounded-full px-4 py-2 text-sm font-bold transition',
            'bg-navy-900 text-white' => $on, 'bg-white text-navy-700 ring-1 ring-navy-100 hover:bg-navy-50' => ! $on])>
            {{ $label }}@if ($key !== '') <span class="opacity-70">({{ number_format($counts[$key]) }})</span>@endif
        </a>
    @endforeach
</nav>

<section class="card overflow-x-auto" data-domains>
    <table class="table-base">
        <thead><tr><th>{{ __('Restaurant') }}</th><th>{{ __('Domain') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th><th>{{ __('DNS') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
        <tbody>
        @forelse ($domains as $d)
            @php($check = $dns[$d->id] ?? null)
            <tr data-domain-row="{{ $d->id }}" class="align-top">
                <td class="min-w-40">
                    @if ($canTenants)
                        <a href="{{ route('admin.tenants.show', $d->tenant_id) }}" class="font-semibold text-navy-800 hover:text-brand-700">{{ $d->tenant->name }}</a>
                    @else
                        <span class="font-semibold">{{ $d->tenant->name }}</span>
                    @endif
                    <div class="text-xs text-navy-500" dir="ltr">{{ $d->tenant_id }}</div>
                </td>
                <td class="min-w-40">
                    <a href="http://{{ $d->domain }}" target="_blank" rel="noopener" dir="ltr" class="break-all font-bold">{{ $d->domain }}</a>
                    <div class="text-xs text-navy-500">{{ $d->source === 'egweb' ? __('book on egweb.com.eg') : __('own domain') }} · {{ local_time($d->created_at, 'Y/m/d') }}</div>
                </td>
                <td class="whitespace-nowrap">
                    <div>{{ $d->tenant->subscription?->plan?->displayName() ?? '—' }}</div>
                    @if ($allowed[$d->id] ?? false)
                        <span class="badge bg-emerald-100 text-emerald-800" data-domain-allowed="yes">{{ __('Includes a custom domain') }}</span>
                    @else
                        <span class="badge bg-amber-100 text-amber-800" data-domain-allowed="no">{{ __('Plan does not include it') }}</span>
                    @endif
                </td>
                <td class="min-w-32">
                    <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $d->isActive(), 'bg-amber-100 text-amber-800' => in_array($d->status, ['requested', 'pending'], true), 'bg-red-100 text-red-700' => $d->status === 'rejected'])>{{ $d->statusLabel() }}</span>
                    @if ($d->note)<div class="mt-1 max-w-56 text-xs text-navy-500">{{ $d->note }}</div>@endif
                </td>
                <td class="whitespace-nowrap text-xs">
                    @if ($check === null)
                        <span class="text-navy-400">{{ __('Not checked') }}</span>
                    @elseif ($check['points'] === true)
                        <span class="badge bg-emerald-100 text-emerald-800" data-domain-dns="yes">{{ __('Points to us') }}</span>
                    @elseif ($check['points'] === false)
                        <span class="badge bg-red-100 text-red-700" data-domain-dns="no">{{ __('Points elsewhere') }}</span>
                        <div class="mt-1 text-navy-500" dir="ltr">{{ $check['ip'] ?? '—' }}</div>
                    @else
                        <span class="text-navy-500">{{ __('No server IP in the settings') }}</span>
                    @endif
                    <form method="post" action="{{ route('admin.domains.check', $d) }}" class="mt-1">@csrf<button class="text-brand-700 hover:underline">{{ __('Check now') }}</button></form>
                </td>
                <td class="min-w-64">
                    <div class="flex flex-wrap gap-2">
                        @unless ($d->isActive())
                            <form method="post" action="{{ route('admin.domains.approve', $d) }}">@csrf<button class="btn-primary min-h-9 px-3">{{ __('Approve') }}</button></form>
                        @endunless
                        @if ($d->status !== 'rejected')
                            <details class="w-full">
                                <summary class="btn-ghost min-h-9 cursor-pointer px-3 text-red-700">{{ __('Reject') }}</summary>
                                <form method="post" action="{{ route('admin.domains.reject', $d) }}" class="mt-2 flex flex-wrap gap-2">
                                    @csrf
                                    <label class="sr-only" for="reason-{{ $d->id }}">{{ __('Reason for the owner') }}</label>
                                    <input id="reason-{{ $d->id }}" name="reason" required maxlength="300" class="field min-h-9 flex-1" placeholder="{{ __('Reason for the owner') }}">
                                    <button class="btn-navy min-h-9 px-3">{{ __('Send') }}</button>
                                </form>
                            </details>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-8 text-center text-navy-500">{{ __('No domain requests.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $domains->links() }}</div>
</section>
@endsection
