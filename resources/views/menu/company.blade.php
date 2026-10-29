@extends('layouts.base')
@section('title', $contract->name . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $statusStyles = ['requested' => 'bg-amber-100 text-amber-800', 'confirmed' => 'bg-emerald-100 text-emerald-800', 'closed' => 'bg-sky-100 text-sky-800',
        'declined' => 'bg-red-100 text-red-700', 'cancelled' => 'bg-navy-100 text-navy-500'];
    $active = $contract->status === 'active';
@endphp

@push('head')
@include('menu.partials.reserve-head', ['settings' => $settings])
@endpush

@section('body')
{{-- A contracted company's private page: its account, its visits and the vouchers they left it, and a visit request. --}}
<header class="m-secondary">
    <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-4">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 shrink-0 rounded-xl bg-white object-contain p-1">@endif
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-xl font-black" data-company-name>{{ $contract->name }}</h1>
            <p class="truncate text-sm text-white/75">{{ $brand }}</p>
        </div>
    </div>
</header>

<main class="mx-auto max-w-2xl space-y-4 px-4 py-5">
    <x-flash />

    <section class="card grid grid-cols-2 gap-3 text-sm sm:grid-cols-4" data-company-account>
        <div><div class="muted">{{ __('Charged') }}</div><div class="text-lg font-extrabold">{{ money($account['charged']) }}</div></div>
        <div><div class="muted">{{ __('Paid') }}</div><div class="text-lg font-extrabold text-emerald-700">{{ money($account['paid']) }}</div></div>
        <div>
            <div class="muted">{{ $account['owed'] > 0 ? __('You owe') : __('Paid ahead') }}</div>
            <div @class(['text-lg font-extrabold', 'text-red-600' => $account['owed'] > 0]) data-company-owed>{{ money(abs($account['owed'])) }}</div>
        </div>
        <div><div class="muted">{{ __('Left on your vouchers') }}</div><div class="text-lg font-extrabold">{{ money($account['voucher_balance']) }}</div></div>
        @if ($available !== null)
            <p class="col-span-2 rounded-xl bg-navy-50 p-2 text-center font-semibold sm:col-span-4" data-company-available>{{ __('You can book visits for up to :amount now.', ['amount' => money(max(0, $available))]) }}</p>
        @endif
    </section>

    @if ($vouchers->isNotEmpty())
        <section class="card space-y-2" data-company-vouchers>
            <h2 class="section-title">{{ __('Your vouchers') }}</h2>
            @foreach ($vouchers as $v)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border m-border p-3 text-sm">
                    <div>
                        <div class="font-mono font-extrabold tracking-wider" dir="ltr">{{ $v->formattedCode() }}</div>
                        <div class="muted">{{ $v->valid_from ? __('Meals of the visit on :date', ['date' => local_time($v->valid_from, 'Y/m/d')]) : __('Valid until :date', ['date' => local_time($v->expires_on, 'Y/m/d')]) }}</div>
                    </div>
                    <div class="text-lg font-extrabold">{{ money($v->balance) }}</div>
                </div>
            @endforeach
        </section>
    @endif

    @if ($active)
        <form method="post" action="{{ route('menu.company.book', $token) }}" class="card grid gap-3 sm:grid-cols-2" data-company-book>
            @csrf
            <div class="sm:col-span-2">
                <h2 class="section-title">{{ __('Book a visit') }}</h2>
                <p class="muted text-sm">{{ __('At least :n hours ahead. The restaurant confirms the visit and you see it below.', ['n' => $contract->booking_notice_hours]) }}</p>
            </div>
            @if ($branches->count() > 1)
                <label class="block sm:col-span-2"><span class="field-label">{{ __('Branch') }}</span>
                    <select class="field" name="branch_id">
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id') === $b->id)>{{ tr($b->name, $b->name_en) }}@if ($b->address) · {{ $b->address }}@endif</option>
                        @endforeach
                    </select></label>
            @elseif ($branches->count() === 1)
                <input type="hidden" name="branch_id" value="{{ $branches->first()->id }}">
            @endif
            <label class="block"><span class="field-label">{{ __('Day') }}</span>
                <input class="field" type="date" name="date" min="{{ $earliest->format('Y-m-d') }}" max="{{ $contract->expires_on?->format('Y-m-d') }}" value="{{ old('date', $earliest->format('Y-m-d')) }}" required></label>
            <label class="block"><span class="field-label">{{ __('Time') }}</span>
                <input class="field" type="time" name="time" value="{{ old('time', '13:00') }}" required dir="ltr"></label>
            <label class="block"><span class="field-label">{{ __('Guests') }}</span>
                <input class="field" type="number" name="party_size" min="1" max="{{ \App\Services\Corporate\CorporateBookings::MAX_PARTY }}" value="{{ old('party_size', 1) }}" required dir="ltr"></label>
            <label class="block"><span class="field-label">{{ __('Budget of the visit') }}</span>
                <input class="field" type="number" name="budget" min="1" step="0.01" value="{{ old('budget') }}" required dir="ltr"></label>
            <label class="block"><span class="field-label">{{ __('Who is coming') }}</span>
                <input class="field" name="rep_name" maxlength="100" value="{{ old('rep_name') }}" required autocomplete="name"></label>
            <label class="block"><span class="field-label">{{ __('Their mobile') }}</span>
                <input class="field" name="rep_phone" maxlength="20" inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx" value="{{ old('rep_phone') }}" required></label>
            <label class="block sm:col-span-2"><span class="field-label">{{ __('Notes (optional)') }}</span>
                <textarea class="field" name="notes" rows="2" maxlength="500">{{ old('notes') }}</textarea></label>
            @if ($errors->any())<p class="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700 sm:col-span-2" data-company-error>{{ $errors->first() }}</p>@endif
            <p class="muted text-xs sm:col-span-2">{{ __('The meals of the visit go on a code sent to the mobile of who is coming, for that day; what is left of the budget stays on a voucher for your company.') }}</p>
            <button class="m-primary flex min-h-12 items-center justify-center rounded-2xl text-lg font-extrabold sm:col-span-2">{{ __('Send the visit') }}</button>
        </form>
    @else
        <p class="card text-center font-semibold">{{ __('This contract is not active. Call the restaurant.') }}</p>
    @endif

    <section class="card space-y-2" data-company-visits>
        <h2 class="section-title">{{ __('Your visits') }}</h2>
        @forelse ($bookings as $b)
            <div class="flex flex-wrap items-start justify-between gap-2 rounded-xl border m-border p-3 text-sm" data-company-visit="{{ $b->status }}">
                <div>
                    <div class="font-bold" dir="ltr">{{ local_time($b->visit_at, 'Y/m/d h:i A') }}</div>
                    <div class="muted">{{ $b->rep_name }} · {{ __(':n guests', ['n' => $b->party_size]) }} · {{ money($b->budget) }}@if ($b->branch) · {{ tr($b->branch->name, $b->branch->name_en) }}@endif</div>
                    @if (in_array($b->status, ['declined', 'cancelled'], true) && $b->decline_reason)<div class="text-red-700">{{ $b->decline_reason }}</div>@endif
                    @if (in_array($b->status, ['confirmed', 'closed'], true))<div class="muted">{{ __('Meals :meals · voucher :voucher', ['meals' => money($b->meals_amount), 'voucher' => money($b->voucher_amount)]) }}</div>@endif
                </div>
                <span class="badge {{ $statusStyles[$b->status] ?? '' }}">{{ $b->statusLabel() }}</span>
            </div>
        @empty
            <p class="muted text-center">{{ __('No visits yet.') }}</p>
        @endforelse
    </section>
</main>
@endsection
