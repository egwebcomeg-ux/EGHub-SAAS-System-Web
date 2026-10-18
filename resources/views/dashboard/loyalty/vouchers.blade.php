@extends('layouts.dashboard')
@section('title', __('Vouchers'))

@section('content')
@php
    $stateStyles = [
        'active' => 'bg-emerald-100 text-emerald-800', 'paused' => 'bg-navy-100 text-navy-700', 'used' => 'bg-sky-100 text-sky-800',
        'expired' => 'bg-navy-100 text-navy-500', 'scheduled' => 'bg-amber-100 text-amber-800',
    ];
    $openForm = old('count') !== null ? 'batch' : (old('type') !== null ? 'single' : '');
@endphp
<div class="dash-head">
    <div>
        <h1 class="page-title">{{ __('Vouchers') }}</h1>
        <p class="dash-help">{{ __('Discount codes on the loyalty card: one for a guest, or a printed batch to hand out. Guests type the code in the cart or scan its QR.') }}</p>
    </div>
    <div class="dash-head-actions">
        <a href="{{ route('dash.loyalty.setup') }}" class="btn-ghost">{{ __('Program settings') }}</a>
        @can('loyalty.stamp')<a href="{{ route('dash.loyalty.desk') }}" class="btn-ghost">{{ __('Counter') }}</a>@endcan
    </div>
</div>

<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
    <div class="stat-card"><div class="stat-label">{{ __('Vouchers') }}</div><div class="stat-value">{{ number_format($stats['total']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Active') }}</div><div class="stat-value">{{ number_format($stats['active']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Orders with a voucher') }}</div><div class="stat-value">{{ number_format($stats['orders']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Their sales') }}</div><div class="stat-value">{{ money($stats['revenue']) }} <span class="text-sm">{{ __('EGP') }}</span></div></div>
    <div class="stat-card col-span-2 lg:col-span-1"><div class="stat-label">{{ __('Discount given') }}</div><div class="stat-value">{{ money($stats['discount']) }} <span class="text-sm">{{ __('EGP') }}</span></div></div>
</div>

<div class="mb-4 grid items-start gap-4 xl:grid-cols-2">
    <details class="card" @if ($openForm === 'single' || ($openForm === '' && $stats['total'] === 0)) open @endif data-voucher-form>
        <summary class="section-title cursor-pointer">＋ {{ __('New voucher') }}</summary>
        <form method="post" action="{{ route('dash.vouchers.store') }}" class="mt-3 space-y-3">
            @csrf
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block"><span class="field-label">{{ __('Code (empty = made for you)') }}</span>
                    <input class="field uppercase" name="code" maxlength="30" value="{{ $openForm === 'single' ? old('code') : '' }}" placeholder="{{ $prefix }}-7KQ2" dir="ltr" autocomplete="off"></label>
                <label class="block"><span class="field-label">{{ __('Uses in total (empty = no limit)') }}</span>
                    <input class="field" name="max_uses" type="number" min="1" value="{{ $openForm === 'single' ? old('max_uses') : '' }}" dir="ltr"></label>
                <label class="block"><span class="field-label">{{ __('For one guest only: mobile (optional)') }}</span>
                    <input class="field" name="phone" maxlength="20" value="{{ $openForm === 'single' ? old('phone') : '' }}" placeholder="01xxxxxxxxx" dir="ltr" inputmode="tel"></label>
                <label class="block"><span class="field-label">{{ __('Guest name (optional)') }}</span>
                    <input class="field" name="name" maxlength="100" value="{{ $openForm === 'single' ? old('name') : '' }}"></label>
            </div>
            <p class="text-xs text-navy-500">{{ __('A voucher for a guest works only with their mobile number and shows on their loyalty card.') }}</p>
            @include('dashboard.loyalty.partials.voucher-rules')
            <button class="btn-primary">{{ __('Create voucher') }}</button>
        </form>
    </details>

    <details class="card" @if ($openForm === 'batch') open @endif data-batch-form>
        <summary class="section-title cursor-pointer">▦ {{ __('Generate a batch to print') }}</summary>
        <form method="post" action="{{ route('dash.vouchers.batch') }}" class="mt-3 space-y-3">
            @csrf
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="block"><span class="field-label">{{ __('How many codes') }}</span>
                    <input class="field" name="count" type="number" min="1" max="{{ \App\Http\Controllers\Dashboard\VouchersController::MAX_BATCH }}" required value="{{ old('count', 50) }}" dir="ltr"></label>
                <label class="block"><span class="field-label">{{ __('Batch name') }}</span>
                    <input class="field" name="batch" maxlength="40" value="{{ old('batch') }}" placeholder="{{ __('e.g. Ramadan flyers') }}"></label>
                <label class="block"><span class="field-label">{{ __('Code start') }}</span>
                    <input class="field uppercase" name="prefix" maxlength="8" value="{{ old('prefix', $prefix) }}" dir="ltr"></label>
            </div>
            <p class="text-xs text-navy-500">{{ __('Every code works once. Up to :n codes per batch.', ['n' => \App\Http\Controllers\Dashboard\VouchersController::MAX_BATCH]) }}</p>
            @include('dashboard.loyalty.partials.voucher-rules')
            <button class="btn-primary">{{ __('Generate the codes') }}</button>
        </form>
    </details>
</div>

@if ($batches->isNotEmpty())
    <section class="card mb-4" data-batches>
        <h2 class="section-title mb-3">{{ __('Batches') }}</h2>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>{{ __('Batch') }}</th><th>{{ __('Codes') }}</th><th>{{ __('Used') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($batches as $b)
                    <tr>
                        <td class="font-semibold"><a href="{{ route('dash.vouchers', ['batch' => $b->batch]) }}" class="text-brand-700">{{ $b->batch }}</a></td>
                        <td>{{ number_format($b->codes) }}</td>
                        <td>{{ number_format((int) $b->used) }}</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <a href="{{ route('dash.vouchers.print', ['batch' => $b->batch, 'layout' => 'a6']) }}" target="_blank" class="text-brand-700">{{ __('Print A6') }}</a>
                                <a href="{{ route('dash.vouchers.print', ['batch' => $b->batch, 'layout' => 'a4']) }}" target="_blank" class="text-brand-700">{{ __('Print 8 per A4') }}</a>
                                <a href="{{ route('dash.vouchers.export', ['batch' => $b->batch]) }}" class="text-brand-700">⬇ CSV</a>
                                <form method="post" action="{{ route('dash.vouchers.batch.toggle') }}" class="inline">
                                    @csrf<input type="hidden" name="batch" value="{{ $b->batch }}">
                                    <button name="active" value="0" class="text-navy-600">{{ __('Pause all') }}</button> ·
                                    <button name="active" value="1" class="text-navy-600">{{ __('Resume all') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<form class="mb-4 flex flex-wrap gap-2" data-voucher-filters>
    <input class="field max-w-xs" name="q" value="{{ $q }}" placeholder="{{ __('Search code, note or guest mobile') }}">
    <select class="field max-w-44" name="status" onchange="this.form.submit()">
        <option value="">{{ __('Any status') }}</option>
        @foreach (['active' => __('Active'), 'used' => __('Used up'), 'expired' => __('Expired'), 'paused' => __('Paused')] as $key => $label)
            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
        @endforeach
    </select>
    @if ($batches->isNotEmpty())
        <select class="field max-w-52" name="batch" onchange="this.form.submit()">
            <option value="">{{ __('All batches') }}</option>
            @foreach ($batches as $b)<option value="{{ $b->batch }}" @selected($batch === $b->batch)>{{ $b->batch }}</option>@endforeach
        </select>
    @endif
    <button class="btn-ghost">{{ __('Search') }}</button>
    @if ($vouchers->isNotEmpty())
        <a href="{{ route('dash.vouchers.print', ['ids' => $vouchers->pluck('id')->implode(','), 'layout' => 'a4']) }}" target="_blank" class="btn-ghost">🖨 {{ __('Print this list') }}</a>
    @endif
</form>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr>
            <th>{{ __('Code') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Valid') }}</th>
            <th>{{ __('Uses') }}</th><th>{{ __('Orders') }}</th><th>{{ __('Sales') }}</th><th>{{ __('Discount given') }}</th><th>{{ __('Status') }}</th><th></th>
        </tr></thead>
        <tbody>
        @forelse ($vouchers as $v)
            @php($state = $v->state())
            <tr data-voucher="{{ $v->code }}">
                <td>
                    <code class="whitespace-nowrap font-mono font-bold tracking-wider" dir="ltr">{{ $v->code }}</code>
                    @if ($v->batch)<div class="text-xs text-navy-500">{{ $v->batch }}</div>@endif
                    @if ($v->note)<div class="text-xs text-navy-500">{{ $v->note }}</div>@endif
                </td>
                <td>
                    <div class="font-semibold">{{ $v->headline() }}</div>
                    <div class="text-xs text-navy-500">{{ $v->conditions() }}</div>
                </td>
                <td>@if ($v->customer)<span dir="ltr">{{ $v->customer->phone }}</span>@if ($v->customer->name)<div class="text-xs text-navy-500">{{ $v->customer->name }}</div>@endif @else<span class="text-navy-400">—</span>@endif</td>
                <td class="text-xs">
                    @if ($v->starts_at)<div>{{ __('from :date', ['date' => local_time($v->starts_at, 'Y/m/d')]) }}</div>@endif
                    <div>{{ $v->expires_at ? __('until :date', ['date' => local_time($v->expires_at, 'Y/m/d')]) : __('No end date') }}</div>
                </td>
                <td dir="ltr" class="text-start">{{ $v->uses }}{{ $v->max_uses !== null ? '/'.$v->max_uses : '' }}</td>
                <td>{{ $v->orders_count }}</td>
                <td>{{ money((float) $v->revenue) }}</td>
                <td>{{ money((float) $v->discount_given) }}</td>
                <td><span class="badge {{ $stateStyles[$state] }}">{{ \App\Http\Controllers\Dashboard\VouchersController::stateLabel($state) }}</span></td>
                <td>
                    <div class="flex items-center gap-3 text-sm">
                        <a href="{{ route('dash.vouchers.print', ['ids' => $v->id]) }}" target="_blank" class="text-brand-700">{{ __('Print') }}</a>
                        <form method="post" action="{{ route('dash.vouchers.toggle', $v) }}">
                            @csrf<button class="text-navy-600">{{ $v->is_active ? __('Pause') : __('Resume') }}</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="10" class="py-10 text-center text-navy-500">{{ __('No vouchers yet. Create one above, or give one to a guest from the counter.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $vouchers->links() }}</div>
</div>

<script>
    // the cap only applies to a percent discount
    document.querySelectorAll('[data-voucher-form] form, [data-batch-form] form').forEach((form) => {
        const sync = () => {
            const percent = form.querySelector('[data-voucher-type]:checked')?.value !== 'amount';
            form.querySelector('[data-percent-only]')?.classList.toggle('hidden', !percent);
        };
        form.querySelectorAll('[data-voucher-type]').forEach((r) => r.addEventListener('change', sync));
        sync();
    });
</script>
@endsection
