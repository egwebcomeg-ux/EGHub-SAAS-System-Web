@extends('layouts.dashboard')
@section('title', __('Company visits'))

@section('content')
@php
    $statusStyles = ['requested' => 'bg-amber-100 text-amber-800', 'confirmed' => 'bg-emerald-100 text-emerald-800', 'closed' => 'bg-sky-100 text-sky-800',
        'declined' => 'bg-red-100 text-red-700', 'cancelled' => 'bg-navy-100 text-navy-500'];
    $brand = tr(\App\Services\Settings::get('brand_name'), \App\Services\Settings::get('brand_name_en')) ?: tenant()->name;
@endphp
<x-dash.page-header :title="__('Company visits')" :help="__('A company sends its rep with a budget: the meals part goes on a voucher for the visit day on the rep\'s mobile, the rest on a voucher for the company. Every visit waits for a manager.')" />
@include('dashboard.partials.area-tabs', ['area' => 'corporate', 'current' => 'visits', 'tabCounts' => ['visits' => $waiting]])

@if ($errors->has('booking'))<p class="mb-4 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700" data-booking-error>{{ $errors->first('booking') }}</p>@endif

@include('dashboard.partials.tabs', ['label' => __('Visits'), 'current' => $filter, 'tabs' => collect([
    'open' => __('Waiting and upcoming') . ($waiting ? ' (' . $waiting . ')' : ''), 'requested' => __('Waiting for a manager'), 'confirmed' => __('Confirmed'),
    'closed' => __('Visit closed'), 'declined' => __('Declined'), 'cancelled' => __('Cancelled'), 'all' => __('All'),
])->map(fn ($label, $key) => [$key, $label, route('dash.corporate.bookings', array_filter(['filter' => $key, 'contract' => request('contract')]))])->values()->all()])

<details class="card mb-4" @if ($errors->any() && ! $errors->has('booking')) open @endif data-booking-new>
    <summary class="cursor-pointer font-bold">＋ {{ __('Add a visit the company asked by phone') }}</summary>
    <form method="post" action="{{ route('dash.corporate.bookings.store') }}" class="mt-3 grid gap-3 sm:grid-cols-3">
        @csrf
        <label class="block sm:col-span-2"><span class="field-label">{{ __('Company') }} *</span>
            <select class="field" name="corporate_contract_id" required>
                @foreach ($contracts as $c)
                    <option value="{{ $c->id }}" @selected((int) old('corporate_contract_id', request('contract')) === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select></label>
        <label class="block"><span class="field-label">{{ __('Branch') }}</span>
            <select class="field" name="branch_id">
                <option value="">—</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected((int) old('branch_id') === $b->id)>{{ tr($b->name, $b->name_en) }}</option>
                @endforeach
            </select></label>
        <label class="block"><span class="field-label">{{ __('Day') }} *</span>
            <input class="field" type="date" name="date" min="{{ now()->toDateString() }}" value="{{ old('date', now()->toDateString()) }}" required></label>
        <label class="block"><span class="field-label">{{ __('Time') }} *</span>
            <input class="field" type="time" name="time" value="{{ old('time', '13:00') }}" required dir="ltr"></label>
        <label class="block"><span class="field-label">{{ __('Guests') }} *</span>
            <input class="field" type="number" name="party_size" min="1" max="{{ \App\Services\Corporate\CorporateBookings::MAX_PARTY }}" value="{{ old('party_size', 1) }}" required dir="ltr"></label>
        <label class="block"><span class="field-label">{{ __('Budget') }} *</span>
            <input class="field" type="number" name="budget" min="1" step="0.01" value="{{ old('budget') }}" required dir="ltr"></label>
        <label class="block"><span class="field-label">{{ __('Who is coming') }} *</span>
            <input class="field" name="rep_name" maxlength="100" value="{{ old('rep_name') }}" required></label>
        <label class="block"><span class="field-label">{{ __('Their mobile') }} *</span>
            <input class="field" name="rep_phone" maxlength="20" inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx" value="{{ old('rep_phone') }}" required></label>
        <label class="block sm:col-span-3"><span class="field-label">{{ __('Notes (optional)') }}</span>
            <input class="field" name="notes" maxlength="500" value="{{ old('notes') }}"></label>
        @error('date')<p class="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700 sm:col-span-3">{{ $message }}</p>@enderror
        <button class="btn-primary sm:col-span-3">{{ __('Add the visit') }}</button>
    </form>
</details>

<div class="space-y-3" data-bookings>
    @forelse ($bookings as $b)
        @php
            $meals = $b->vouchers->first(fn ($v) => $v->valid_from !== null);
            $rest = $b->vouchers->first(fn ($v) => $v->valid_from === null);
            $left = $available[$b->corporate_contract_id] ?? null;
        @endphp
        <article class="card space-y-3" data-booking="{{ $b->id }}" data-booking-status="{{ $b->status }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="font-extrabold">{{ $b->contract->name }} · <span dir="ltr">{{ local_time($b->visit_at, 'Y/m/d h:i A') }}</span></h2>
                    <p class="text-sm text-navy-600">
                        {{ __(':n guests', ['n' => $b->party_size]) }} · {{ __('Budget') }} {{ money($b->budget) }} ({{ __(':amount per guest', ['amount' => money($b->perPerson())]) }})
                        @if ($b->branch) · {{ tr($b->branch->name, $b->branch->name_en) }}@endif
                    </p>
                    <p class="text-sm">{{ $b->rep_name }} · <a class="font-bold text-emerald-700" dir="ltr" href="https://wa.me/{{ whatsapp_number($b->rep_phone) }}" target="_blank" rel="noopener">{{ $b->rep_phone }}</a>
                        · <span class="text-navy-500">{{ $b->source === 'portal' ? __('From the company link') : __('Entered by staff') }}</span></p>
                    @if ($b->notes)<p class="mt-1 rounded-lg bg-navy-50 p-2 text-sm">{{ $b->notes }}</p>@endif
                </div>
                <span class="badge {{ $statusStyles[$b->status] ?? '' }}">{{ $b->statusLabel() }}</span>
            </div>

            @if ($b->status === 'requested' && $canDecide)
                <div class="flex flex-wrap items-end gap-2 border-t border-navy-100 pt-3">
                    <form method="post" action="{{ route('dash.corporate.bookings.approve', $b) }}" class="flex flex-wrap items-end gap-2" data-booking-approve>
                        @csrf
                        <label class="block"><span class="field-label">{{ __('Meal price per guest') }}</span>
                            <input class="field w-36" type="number" name="meal_price" min="0" step="0.01" value="{{ floor($b->perPerson()) }}" required dir="ltr"></label>
                        <button class="btn-primary">{{ __('Confirm the visit') }}</button>
                    </form>
                    <form method="post" action="{{ route('dash.corporate.bookings.decline', $b) }}" data-booking-decline
                          onsubmit="const r = prompt(@js(__('Why is the visit declined? The company sees it.'))); if (!r) return false; this.reason.value = r;">
                        @csrf <input type="hidden" name="reason">
                        <button class="btn-ghost text-red-700">{{ __('Decline') }}</button>
                    </form>
                    <p class="w-full text-xs text-navy-500">
                        {{ __('Meals = meal price × guests (at most the budget); the rest becomes a voucher for the company. The whole budget is charged to the company.') }}
                        @if ($left !== null) <b>{{ __('The company can take :amount now.', ['amount' => money(max(0, $left))]) }}</b>@endif
                    </p>
                </div>
            @elseif ($b->status === 'requested')
                <p class="border-t border-navy-100 pt-3 text-sm text-navy-500">{{ __('Waiting for a manager to confirm it.') }}</p>
            @endif

            @if (in_array($b->status, ['confirmed', 'closed'], true))
                <div class="grid gap-2 border-t border-navy-100 pt-3 text-sm sm:grid-cols-2">
                    <div class="rounded-xl bg-emerald-50 p-3" data-meals-voucher>
                        <div class="text-navy-500">{{ __('Meals on the visit day') }}</div>
                        <div class="font-extrabold">{{ money($b->meals_amount) }}</div>
                        @if ($meals)<div class="font-mono text-xs" dir="ltr">{{ $meals->serial }} · {{ $meals->formattedCode() }} · {{ __('left') }} {{ money($meals->balance) }}</div>@endif
                    </div>
                    <div class="rounded-xl bg-brand-50 p-3" data-company-voucher>
                        <div class="text-navy-500">{{ __('Voucher for the company') }}</div>
                        <div class="font-extrabold">{{ money($b->voucher_amount) }}</div>
                        @if ($rest)<div class="font-mono text-xs" dir="ltr">{{ $rest->serial }} · {{ $rest->formattedCode() }} · {{ __('until') }} {{ local_time($rest->expires_on, 'Y/m/d') }}</div>@endif
                    </div>
                </div>
            @endif

            @if ($b->status === 'confirmed' && $canDecide)
                <div class="flex flex-wrap gap-2">
                    @if ($meals)
                        @php
                            $message = __('Hello :name, your visit to :brand on :date is confirmed. Meals code: :code (:amount), it works on the visit day only, with your mobile number.', [
                                'name' => $b->rep_name, 'brand' => $brand, 'date' => local_time($b->visit_at, 'Y/m/d h:i A'), 'code' => $meals->formattedCode(), 'amount' => money($meals->value),
                            ]);
                        @endphp
                        <a class="btn-primary" target="_blank" rel="noopener" href="https://wa.me/{{ whatsapp_number($b->rep_phone) }}?text={{ rawurlencode($message) }}" data-send-rep>WhatsApp · {{ __('Send the code to :name', ['name' => $b->rep_name]) }}</a>
                    @endif
                    @if (! $b->visit_at->copy()->startOfDay()->isFuture())
                        <form method="post" action="{{ route('dash.corporate.bookings.close', $b) }}" onsubmit="return confirm(@js(__('Close the visit? What the meals did not use moves to the company\'s voucher.')))" data-booking-close>
                            @csrf <button class="btn-ghost">{{ __('Close the visit') }}</button>
                        </form>
                    @endif
                    <form method="post" action="{{ route('dash.corporate.bookings.cancel', $b) }}" data-booking-cancel
                          onsubmit="const r = prompt(@js(__('Why is the visit cancelled?'))); if (!r) return false; this.reason.value = r;">
                        @csrf <input type="hidden" name="reason">
                        <button class="btn-ghost text-red-700">{{ __('Cancel the visit') }}</button>
                    </form>
                </div>
            @endif

            @if (in_array($b->status, ['declined', 'cancelled'], true) && $b->decline_reason)
                <p class="text-sm text-red-700">{{ $b->decline_reason }}</p>
            @endif
            @if ($b->decider)
                <p class="text-xs text-navy-500">{{ $b->statusLabel() }} · {{ $b->decider->name }} · {{ local_time($b->decided_at, 'Y/m/d h:i A') }}</p>
            @endif
        </article>
    @empty
        <p class="card py-8 text-center text-navy-500">{{ __('No visits here.') }}</p>
    @endforelse
</div>
<div class="mt-4">{{ $bookings->links() }}</div>
@endsection
