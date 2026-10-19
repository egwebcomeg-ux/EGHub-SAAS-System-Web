@extends('layouts.dashboard')
@section('title', __('Coupons & vouchers'))

@section('content')
@php
    $stateStyles = [
        'active' => 'bg-emerald-100 text-emerald-800', 'paused' => 'bg-navy-100 text-navy-700', 'used' => 'bg-sky-100 text-sky-800',
        'expired' => 'bg-navy-100 text-navy-500', 'scheduled' => 'bg-amber-100 text-amber-800',
    ];
    $kindStyles = [
        'campaign' => 'ring-sky-200 text-sky-800', 'guest' => 'ring-brand-200 text-brand-700',
        'batch' => 'ring-amber-200 text-amber-800', 'public' => 'ring-emerald-200 text-emerald-800',
    ];
    // which create form to reopen after a validation error
    $openForm = old('_form', old('count') !== null ? 'batch' : (old('type') !== null ? 'single' : ''));
    $voucherKinds = ['public', 'guest', 'batch'];
@endphp
<x-dash.page-header :title="__('Coupons & vouchers')" :help="__('Every discount code in one place: campaign coupons, public codes, vouchers held by one guest and printed batches. Guests type the code in the cart or scan its QR.')">
    <x-slot:actions>
        @if ($managesCampaigns)<a href="{{ route('dash.campaigns') }}" class="btn-ghost">{{ __('Campaigns') }}</a>@endif
        @if ($managesVouchers)<a href="{{ route('dash.loyalty.setup') }}" class="btn-ghost">{{ __('Program settings') }}</a>@endif
        @can('loyalty.stamp')<a href="{{ route('dash.loyalty.desk') }}" class="btn-ghost">{{ __('Counter') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>

<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
    <div class="stat-card"><div class="stat-label">{{ __('Codes') }}</div><div class="stat-value">{{ number_format($stats['total']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Active') }}</div><div class="stat-value">{{ number_format($stats['active']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Orders with a code') }}</div><div class="stat-value">{{ number_format($stats['orders']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Their sales') }}</div><div class="stat-value">{{ money($stats['revenue']) }} <span class="text-sm">{{ __('EGP') }}</span></div></div>
    <div class="stat-card col-span-2 lg:col-span-1"><div class="stat-label">{{ __('Discount given') }}</div><div class="stat-value">{{ money($stats['discount']) }} <span class="text-sm">{{ __('EGP') }}</span></div></div>
</div>

<div class="mb-4 grid items-start gap-4 lg:grid-cols-2 2xl:grid-cols-3">
    @if ($managesVouchers)
        <details class="card" @if ($openForm === 'single' || ($openForm === '' && $stats['total'] === 0)) open @endif data-voucher-form>
            <summary class="section-title cursor-pointer">＋ {{ __('New public coupon') }}</summary>
            <form method="post" action="{{ route('dash.vouchers.store') }}" class="mt-3 space-y-3">
                @csrf
                <input type="hidden" name="_form" value="single">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="field-label">{{ __('Code (empty = made for you)') }}</span>
                        <input class="field uppercase" name="code" maxlength="30" value="{{ $openForm === 'single' ? old('code') : '' }}" placeholder="{{ $prefix }}-7KQ2" dir="ltr" autocomplete="off"></label>
                    <label class="block"><span class="field-label">{{ __('Uses in total (empty = no limit)') }}</span>
                        <input class="field" name="max_uses" type="number" min="1" value="{{ $openForm === 'single' ? old('max_uses') : '' }}" dir="ltr"></label>
                </div>
                <p class="text-xs text-navy-500">{{ __('Anyone with the code can use it, within its limits. Pick it later for a campaign.') }}</p>
                @include('dashboard.loyalty.partials.voucher-rules')
                <button class="btn-primary">{{ __('Create coupon') }}</button>
            </form>
        </details>
    @elseif ($managesCampaigns)
        <div class="card text-sm text-navy-600" data-coupon-campaign-hint>
            <h2 class="section-title mb-1">{{ __('New coupon') }}</h2>
            {{ __('Campaign coupons are made with the campaign: add one in the coupon box of a new campaign.') }}
            <a href="{{ route('dash.campaigns') }}" class="font-semibold text-brand-700 underline">{{ __('New campaign') }}</a>
        </div>
    @endif

    @if ($givesVouchers)
        <details class="card" @if ($openForm === 'give') open @endif data-give-form>
            <summary class="section-title cursor-pointer">🎁 {{ __('Give a voucher to a guest') }}</summary>
            <form method="post" action="{{ route('dash.coupons.give') }}" class="mt-3 space-y-3">
                @csrf
                <input type="hidden" name="_form" value="give">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="field-label">{{ __('Guest mobile') }}</span>
                        <input class="field" name="phone" maxlength="20" required value="{{ $openForm === 'give' ? old('phone') : '' }}" placeholder="01xxxxxxxxx" dir="ltr" inputmode="tel">
                        @error('phone')<span class="field-error">{{ $message }}</span>@enderror</label>
                    <label class="block"><span class="field-label">{{ __('Guest name (optional)') }}</span>
                        <input class="field" name="name" maxlength="100" value="{{ $openForm === 'give' ? old('name') : '' }}"></label>
                    <fieldset class="sm:col-span-2">
                        <legend class="field-label">{{ __('Discount type') }}</legend>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (['percent' => __('Percent %'), 'amount' => __('Amount (EGP)')] as $type => $text)
                                <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-200 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                                    <input type="radio" name="type" value="{{ $type }}" class="sr-only" @checked(($openForm === 'give' ? old('type', 'amount') : 'amount') === $type)>{{ $text }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <label class="block"><span class="field-label">{{ __('Value') }}</span>
                        <input class="field" name="value" type="number" min="1" step="0.5" required value="{{ $openForm === 'give' ? old('value') : '' }}" dir="ltr"></label>
                    <label class="block"><span class="field-label">{{ __('Valid for (days)') }}</span>
                        <input class="field" name="days" type="number" min="1" max="365" required value="{{ $openForm === 'give' ? old('days', 30) : 30 }}" dir="ltr"></label>
                    <label class="block"><span class="field-label">{{ __('Minimum order (EGP)') }}</span>
                        <input class="field" name="min_order" type="number" min="0" step="1" value="{{ $openForm === 'give' ? old('min_order', 0) : 0 }}" dir="ltr"></label>
                </div>
                <p class="text-xs text-navy-500">{{ __('One use, only with the guest\'s mobile number; it shows on their loyalty card.') }}</p>
                <button class="btn-primary">{{ __('Give the voucher') }}</button>
            </form>
        </details>
    @endif

    @if ($managesVouchers)
        <details class="card" @if ($openForm === 'batch') open @endif data-batch-form>
            <summary class="section-title cursor-pointer">▦ {{ __('Generate a batch to print') }}</summary>
            <form method="post" action="{{ route('dash.vouchers.batch') }}" class="mt-3 space-y-3">
                @csrf
                <input type="hidden" name="_form" value="batch">
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
    @endif
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
                        <td class="font-semibold"><a href="{{ route('dash.coupons', ['batch' => $b->batch]) }}" class="text-brand-700">{{ $b->batch }}</a></td>
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

<form class="mb-4 flex flex-wrap gap-2" data-coupon-filters>
    <input class="field max-w-xs" name="q" value="{{ $q }}" placeholder="{{ __('Search code, note, campaign or guest mobile') }}">
    @if (count($kinds) > 1)
        <select class="field max-w-48" name="kind" onchange="this.form.submit()" aria-label="{{ __('Type') }}">
            <option value="">{{ __('Every type') }}</option>
            @foreach ($kinds as $k)
                <option value="{{ $k }}" @selected($kind === $k)>{{ \App\Models\Coupon::kindLabel($k) }}</option>
            @endforeach
        </select>
    @endif
    <select class="field max-w-44" name="status" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
        <option value="">{{ __('Any status') }}</option>
        @foreach (['active' => __('Active'), 'used' => __('Used up'), 'expired' => __('Expired'), 'paused' => __('Paused')] as $key => $text)
            <option value="{{ $key }}" @selected($status === $key)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($batches->isNotEmpty())
        <select class="field max-w-52" name="batch" onchange="this.form.submit()" aria-label="{{ __('Batch') }}">
            <option value="">{{ __('All batches') }}</option>
            @foreach ($batches as $b)<option value="{{ $b->batch }}" @selected($batch === $b->batch)>{{ $b->batch }}</option>@endforeach
        </select>
    @endif
    <button class="btn-ghost">{{ __('Search') }}</button>
    @php
        $printable = $managesVouchers ? $coupons->filter(fn ($c) => in_array($c->kind(), $voucherKinds, true)) : collect();
    @endphp
    @if ($printable->isNotEmpty())
        <a href="{{ route('dash.vouchers.print', ['ids' => $printable->pluck('id')->implode(','), 'layout' => 'a4']) }}" target="_blank" class="btn-ghost">🖨 {{ __('Print this list') }}</a>
    @endif
</form>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr>
            <th>{{ __('Code') }}</th><th>{{ __('Type') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Valid') }}</th>
            <th>{{ __('Uses') }}</th><th>{{ __('Orders') }}</th><th>{{ __('Sales') }}</th><th>{{ __('Discount given') }}</th><th>{{ __('Status') }}</th><th></th>
        </tr></thead>
        <tbody>
        @forelse ($coupons as $c)
            @php
                $state = $c->state();
                $cKind = $c->kind();
            @endphp
            <tr data-voucher="{{ $c->code }}" data-coupon-kind="{{ $cKind }}">
                <td>
                    <code class="whitespace-nowrap font-mono font-bold tracking-wider" dir="ltr">{{ $c->code }}</code>
                    @if ($c->batch)<div class="text-xs text-navy-500">{{ $c->batch }}</div>@endif
                    @if ($c->campaigns->isNotEmpty())<div class="text-xs text-navy-500">📣 {{ $c->campaigns->pluck('name')->take(2)->implode('، ') }}</div>@endif
                    @if ($c->note)<div class="text-xs text-navy-500">{{ $c->note }}</div>@endif
                </td>
                <td><span class="badge bg-white ring-1 {{ $kindStyles[$cKind] }}">{{ \App\Models\Coupon::kindLabel($cKind) }}</span>@if ($c->source === 'automation')<div class="mt-1 text-xs text-navy-500">{{ __('Automation') }}</div>@endif</td>
                <td>
                    <div class="font-semibold">{{ $c->headline() }}</div>
                    <div class="text-xs text-navy-500">{{ $c->conditions() }}</div>
                </td>
                <td>@if ($c->customer)<span dir="ltr">{{ $c->customer->phone }}</span>@if ($c->customer->name)<div class="text-xs text-navy-500">{{ $c->customer->name }}</div>@endif @else<span class="text-navy-400">—</span>@endif</td>
                <td class="text-xs">
                    @if ($c->starts_at)<div>{{ __('from :date', ['date' => local_time($c->starts_at, 'Y/m/d')]) }}</div>@endif
                    <div>{{ $c->expires_at ? __('until :date', ['date' => local_time($c->expires_at, 'Y/m/d')]) : __('No end date') }}</div>
                </td>
                <td dir="ltr" class="text-start">{{ $c->uses }}{{ $c->max_uses !== null ? '/'.$c->max_uses : '' }}</td>
                <td>{{ $c->orders_count }}</td>
                <td>{{ money((float) $c->revenue) }}</td>
                <td>{{ money((float) $c->discount_given) }}</td>
                <td><span class="badge {{ $stateStyles[$state] }}">{{ \App\Http\Controllers\Dashboard\VouchersController::stateLabel($state) }}</span></td>
                <td>
                    <div class="flex items-center gap-3 text-sm">
                        @if ($managesVouchers && in_array($cKind, $voucherKinds, true))
                            <a href="{{ route('dash.vouchers.print', ['ids' => $c->id]) }}" target="_blank" class="text-brand-700">{{ __('Print') }}</a>
                        @endif
                        <form method="post" action="{{ route('dash.coupons.toggle', $c) }}">
                            @csrf<button class="text-navy-600" data-coupon-toggle>{{ $c->is_active ? __('Pause') : __('Resume') }}</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="11" class="py-10 text-center text-navy-500">{{ __('No codes here yet. Create one above, add one to a campaign, or give one to a guest from the counter.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $coupons->links() }}</div>
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
