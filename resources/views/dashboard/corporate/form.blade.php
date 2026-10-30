@extends('layouts.dashboard')
@php
    $editing = $contract->exists;
    $chosenBranches = array_map('intval', old('branch_ids', $contract->branch_ids ?? []));
    $chosenDays = array_map('intval', old('days', $contract->days ?? []));
@endphp
@section('title', $editing ? __('Edit contract') : __('New contract'))

@section('content')
<x-dash.page-header :title="$editing ? __('Edit contract') : __('New contract')" :help="__('The company, the vouchers (value, how many, until when) and where and when they work. The codes are made for you, each unique, with a check character that catches typos.')">
    <x-slot:actions>
        <a href="{{ $editing ? route('dash.corporate.show', $contract) : route('dash.corporate') }}" class="btn-ghost">{{ __('Back') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<form method="post" action="{{ $editing ? route('dash.corporate.update', $contract) : route('dash.corporate.store') }}" class="grid max-w-5xl gap-4 lg:grid-cols-2" data-contract-form>
    @csrf
    <section class="card space-y-3">
        <h2 class="section-title">{{ __('The company') }}</h2>
        <label class="block"><span class="field-label">{{ __('Company name') }} *</span>
            <input class="field" name="name" value="{{ old('name', $contract->name) }}" required maxlength="150" placeholder="{{ __('e.g. Eva Pharma') }}"></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block"><span class="field-label">{{ __('Contact person') }}</span>
                <input class="field" name="contact_name" value="{{ old('contact_name', $contract->contact_name) }}" maxlength="100"></label>
            <label class="block"><span class="field-label">{{ __('Contact mobile') }}</span>
                <input class="field" name="contact_phone" value="{{ old('contact_phone', $contract->contact_phone) }}" maxlength="20" dir="ltr" inputmode="tel"></label>
        </div>
        <label class="block"><span class="field-label">{{ __('Contract / invoice number') }}</span>
            <input class="field" name="reference" value="{{ old('reference', $contract->reference) }}" maxlength="60" dir="auto"></label>
        @php $billing = old('billing', $contract->billing ?? 'prepaid'); @endphp
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block"><span class="field-label">{{ __('How the company pays') }}</span>
                <select class="field" name="billing" data-billing>
                    @foreach (\App\Models\CorporateContract::BILLING as $key => $labels)
                        <option value="{{ $key }}" @selected($billing === $key)>{{ \App\Models\CorporateContract::pick(\App\Models\CorporateContract::BILLING, $key) }}</option>
                    @endforeach
                </select></label>
            <label class="block"><span class="field-label">{{ __('Most the company may owe (empty = no limit)') }}</span>
                <input class="field" name="credit_limit" type="number" min="0" step="0.01" value="{{ old('credit_limit', $contract->credit_limit) }}" dir="ltr"></label>
        </div>
        <label class="block"><span class="field-label">{{ __('Company visits are booked at least (hours ahead)') }}</span>
            <input class="field" name="booking_notice_hours" type="number" min="0" max="720" value="{{ old('booking_notice_hours', $contract->booking_notice_hours ?? 24) }}" dir="ltr" data-notice-hours></label>
        <div class="grid gap-3 sm:grid-cols-2">
            @if ($editing)
                <div class="rounded-xl bg-navy-50 p-3 text-sm">
                    <div class="text-navy-500">{{ __('Contract total') }}</div>
                    <div class="text-lg font-extrabold">{{ money($contract->totalValue()) }}</div>
                </div>
                <div class="rounded-xl bg-navy-50 p-3 text-sm">
                    <div class="text-navy-500">{{ __('Paid by the company so far') }}</div>
                    <div class="text-lg font-extrabold">{{ money($contract->paid_amount) }}</div>
                    <a class="text-xs font-bold text-brand-700" href="{{ route('dash.corporate.statement', $contract) }}">{{ __('Record payments on the company statement') }}</a>
                </div>
            @else
                <label class="block"><span class="field-label">{{ __('Paid when signing') }}</span>
                    <input class="field" name="paid_amount" type="number" min="0" step="0.01" value="{{ old('paid_amount', 0) }}" dir="ltr"></label>
                <label class="block"><span class="field-label">{{ __('Paid by') }}</span>
                    <select class="field" name="paid_method">
                        @foreach (\App\Models\CorporatePayment::METHODS as $key => $labels)
                            <option value="{{ $key }}" @selected(old('paid_method', 'transfer') === $key)>{{ \App\Models\CorporateContract::pick(\App\Models\CorporatePayment::METHODS, $key) }}</option>
                        @endforeach
                    </select></label>
            @endif
        </div>
        <label class="block"><span class="field-label">{{ __('Notes') }}</span>
            <textarea class="field min-h-20 py-2" name="notes" maxlength="2000">{{ old('notes', $contract->notes) }}</textarea></label>
    </section>

    <section class="card space-y-3">
        <h2 class="section-title">{{ __('The vouchers') }}</h2>
        @if ($editing)
            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                <div class="rounded-xl bg-navy-50 p-3"><div class="text-navy-500">{{ __('Value') }}</div><div class="font-extrabold">{{ money($contract->voucher_value) }}</div></div>
                <div class="rounded-xl bg-navy-50 p-3"><div class="text-navy-500">{{ __('Valid until') }}</div><div class="font-extrabold">{{ local_time($contract->expires_on, 'Y/m/d') }}</div></div>
                <div class="col-span-2 rounded-xl bg-navy-50 p-3 sm:col-span-1"><div class="text-navy-500">{{ __('Use') }}</div><div class="font-extrabold">{{ $contract->modeLabel() }}</div></div>
            </div>
            <p class="text-xs text-navy-500">{{ __('The value and the use are printed on the vouchers and stay as they are. Change the date with “Extend” on the contract page.') }}</p>
            <label class="block"><span class="field-label">{{ __('How many vouchers') }}</span>
                <input class="field" name="quantity" type="number" min="{{ $contract->quantity }}" max="{{ \App\Services\Corporate\CorporateVouchers::MAX_QUANTITY }}" value="{{ old('quantity', $contract->quantity) }}" required dir="ltr"></label>
            <p class="-mt-2 text-xs text-navy-500">{{ __('A bigger number adds new vouchers with the next serials.') }}</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block"><span class="field-label">{{ __('Value of one voucher') }} *</span>
                    <input class="field text-lg" name="voucher_value" type="number" min="1" step="0.01" value="{{ old('voucher_value', 200) }}" required dir="ltr"></label>
                <label class="block"><span class="field-label">{{ __('How many vouchers') }} *</span>
                    <input class="field text-lg" name="quantity" type="number" min="0" max="{{ \App\Services\Corporate\CorporateVouchers::MAX_QUANTITY }}" value="{{ old('quantity', 50) }}" required dir="ltr"></label>
                <label class="block"><span class="field-label">{{ __('Valid until (inclusive)') }} *</span>
                    <input class="field" name="expires_on" type="date" min="{{ local_today() }}" value="{{ old('expires_on', $contract->expires_on?->format('Y-m-d')) }}" required></label>
                <label class="block"><span class="field-label">{{ __('Serial prefix') }}</span>
                    <input class="field uppercase" name="prefix" value="{{ old('prefix') }}" maxlength="6" pattern="[A-Za-z0-9]{1,6}" dir="ltr" placeholder="EVA"></label>
            </div>
            <p class="-mt-1 text-xs text-navy-500">{{ __('Serials look like EVA-0001. Empty = taken from the company name.') }} {{ __('0 vouchers = a contract for company visits only.') }}</p>
            <fieldset class="space-y-2">
                <legend class="field-label">{{ __('How it is used') }}</legend>
                @foreach (['single' => __('The whole value in one order. If the order is smaller, the rest is lost (the cashier is warned).'), 'balance' => __('Like a balance: what is left stays on the voucher for the next order.')] as $mode => $help)
                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="mode" value="{{ $mode }}" class="mt-1 size-5" @checked(old('mode', $contract->mode) === $mode)>
                        <span><b>{{ \App\Models\CorporateContract::pick(\App\Models\CorporateContract::MODES, $mode) }}</b><span class="block text-sm text-navy-500">{{ $help }}</span></span>
                    </label>
                @endforeach
            </fieldset>
        @endif
    </section>

    <section class="card space-y-3 lg:col-span-2">
        <h2 class="section-title">{{ __('Where and when') }}</h2>
        @if ($branches->count() > 1)
            <fieldset>
                <legend class="field-label">{{ __('Branches (none ticked = every branch)') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($branches as $b)
                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" class="size-5" @checked(in_array($b->id, $chosenBranches, true))> {{ tr($b->name, $b->name_en) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif
        <fieldset>
            <legend class="field-label">{{ __('Days (none ticked = every day)') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Models\CorporateContract::dayNames() as $d => $dayName)
                    <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="checkbox" name="days[]" value="{{ $d }}" class="size-5" @checked(in_array($d, $chosenDays, true))> {{ $dayName }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-3">
            <label class="block"><span class="field-label">{{ __('From (hour)') }}</span>
                <input class="field" type="time" name="hours_from" value="{{ old('hours_from', $contract->hours_from) }}"></label>
            <label class="block"><span class="field-label">{{ __('To (hour)') }}</span>
                <input class="field" type="time" name="hours_to" value="{{ old('hours_to', $contract->hours_to) }}"></label>
            <label class="block"><span class="field-label">{{ __('Minimum order (optional)') }}</span>
                <input class="field" type="number" min="0" step="0.01" name="min_order" value="{{ old('min_order', $contract->min_order) }}" dir="ltr"></label>
        </div>
        <p class="text-xs text-navy-500">{{ __('Leave the hours empty for any time the restaurant is open.') }}</p>
    </section>

    <div class="flex flex-wrap gap-2 lg:col-span-2">
        <button class="btn-primary min-w-48">{{ $editing ? __('Save') : __('Create the contract and its vouchers') }}</button>
        <a href="{{ $editing ? route('dash.corporate.show', $contract) : route('dash.corporate') }}" class="btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
