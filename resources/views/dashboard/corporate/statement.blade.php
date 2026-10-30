@extends('layouts.dashboard')
@section('title', __('Company statement') . ' · ' . $contract->name)

@section('content')
<x-dash.page-header :title="__('Company statement') . ' · ' . $contract->name" :help="__('What the company was charged, what it paid and what it owes, then every use of its vouchers. A cancelled payment or use is shown crossed out and not counted.')">
    <x-slot:actions>
        <a href="{{ route('dash.corporate.statement', ['contract' => $contract, 'format' => 'csv'] + array_filter(['from' => request('from'), 'to' => request('to')])) }}" class="btn-primary" data-statement-csv>CSV</a>
        <a href="{{ route('dash.corporate.statement', ['contract' => $contract, 'format' => 'xlsx'] + array_filter(['from' => request('from'), 'to' => request('to')])) }}" class="btn-ghost">Excel</a>
        <a href="{{ route('dash.corporate.show', $contract) }}" class="btn-ghost">{{ __('Back to the contract') }}</a>
    </x-slot:actions>
</x-dash.page-header>

{{-- the account: what the company owes or paid ahead, and what its people drew --}}
<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-6" data-account>
    <div class="stat-card"><div class="stat-label">{{ __('Charged') }}</div><div class="stat-value" data-account-charged>{{ money($account['charged']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Paid by the company') }}</div><div class="stat-value text-emerald-700" data-account-paid>{{ money($account['paid']) }}</div></div>
    <div class="stat-card">
        <div class="stat-label">{{ $account['owed'] >= 0 ? __('The company owes') : __('Paid ahead') }}</div>
        <div @class(['stat-value', 'text-red-600' => $account['owed'] > 0, 'text-emerald-700' => $account['owed'] <= 0]) data-account-owed>{{ money(abs($account['owed'])) }}</div>
        @if ($account['credit_left'] !== null)
            <div class="text-xs text-navy-500">{{ __('Limit :limit · :left left', ['limit' => money($contract->credit_limit), 'left' => money($account['credit_left'])]) }}</div>
        @endif
    </div>
    <div class="stat-card"><div class="stat-label">{{ __('Drawn') }}</div><div class="stat-value text-brand-700" data-account-drawn>{{ money($account['drawn']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Left on the vouchers') }}</div><div class="stat-value" data-account-vouchers>{{ money($account['voucher_balance']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Expired unused') }}</div><div class="stat-value text-navy-500">{{ money($account['expired_balance']) }}</div></div>
</div>

<div class="mb-4 grid gap-4 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <h2 class="section-title mb-3">{{ __('Account') }} · {{ $contract->billingLabel() }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Charged') }}</th><th>{{ __('Paid') }}</th><th>{{ __('Balance') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($ledger as $line)
                @php $payment = $line['payment']; @endphp
                <tr @class(['line-through opacity-60' => $payment?->cancelled_at]) data-ledger-row="{{ $line['kind'] }}">
                    <td class="whitespace-nowrap">{{ local_time($line['at'], 'Y/m/d') }}</td>
                    <td>
                        {{ ['issue' => __('Vouchers issued'), 'visit' => __('Company visit'), 'payment' => __('Payment')][$line['kind']] }} · {{ $line['label'] }}
                        @if ($payment?->note)<div class="text-xs text-navy-500">{{ $payment->note }}</div>@endif
                        @if ($payment?->cancelled_at)<div class="text-xs text-red-600">{{ __('Cancelled by :name: :reason', ['name' => $payment->canceller?->name ?? '—', 'reason' => $payment->cancel_reason]) }}</div>@endif
                    </td>
                    <td>{{ $line['charge'] > 0 ? money($line['charge']) : '' }}</td>
                    <td class="text-emerald-700">{{ $payment ? money($payment->amount) : '' }}</td>
                    <td @class(['font-extrabold', 'text-red-600' => $line['balance'] > 0])>{{ money(abs($line['balance'])) }}{{ $line['balance'] < 0 ? ' ' . __('ahead') : '' }}</td>
                    <td>
                        @if ($payment && ! $payment->cancelled_at)
                            <form method="post" action="{{ route('dash.corporate.payments.cancel', $payment) }}" class="flex gap-1" data-payment-cancel
                                  onsubmit="const r = prompt(@js(__('Why is this payment cancelled?'))); if (!r) return false; this.reason.value = r;">
                                @csrf <input type="hidden" name="reason">
                                <button class="btn-ghost min-h-8 px-2 text-xs text-red-600">{{ __('Cancel') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-6 text-center text-navy-500">{{ __('Nothing yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    @if ($contract->status !== 'void')
        <form method="post" action="{{ route('dash.corporate.payments.store', $contract) }}" class="card space-y-3" data-payment-form>
            @csrf
            <h2 class="section-title">{{ __('Record a payment') }}</h2>
            <label class="block"><span class="field-label">{{ __('Amount') }} *</span>
                <input class="field" name="amount" type="number" min="0.01" step="0.01" required dir="ltr" value="{{ old('amount') }}"></label>
            <label class="block"><span class="field-label">{{ __('Paid by') }}</span>
                <select class="field" name="method">
                    @foreach (\App\Models\CorporatePayment::METHODS as $key => $labels)
                        <option value="{{ $key }}" @selected(old('method', 'transfer') === $key)>{{ \App\Models\CorporateContract::pick(\App\Models\CorporatePayment::METHODS, $key) }}</option>
                    @endforeach
                </select></label>
            <label class="block"><span class="field-label">{{ __('Date') }}</span>
                <input class="field" name="paid_on" type="date" max="{{ local_today() }}" value="{{ old('paid_on', local_today()) }}"></label>
            <label class="block"><span class="field-label">{{ __('Transfer / cheque / receipt number') }}</span>
                <input class="field" name="reference" maxlength="60" dir="auto" value="{{ old('reference') }}"></label>
            <label class="block"><span class="field-label">{{ __('Note') }}</span>
                <input class="field" name="note" maxlength="200" value="{{ old('note') }}"></label>
            @error('amount')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error('reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <button class="btn-primary w-full">{{ __('Record the payment') }}</button>
        </form>
    @endif
</div>

<h2 class="section-title mb-2">{{ __('Uses of the vouchers') }}</h2>
<form class="card mb-4 flex flex-wrap items-end gap-2">
    <label class="block"><span class="field-label">{{ __('From') }}</span><input class="field" type="date" name="from" value="{{ $from?->format('Y-m-d') }}"></label>
    <label class="block"><span class="field-label">{{ __('To') }}</span><input class="field" type="date" name="to" value="{{ $to?->format('Y-m-d') }}"></label>
    <button class="btn-ghost">{{ __('Apply') }}</button>
</form>

<div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <div class="stat-card"><div class="stat-label">{{ __('Uses') }}</div><div class="stat-value">{{ $rows->whereNull('refunded_at')->count() }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Used value') }}</div><div class="stat-value text-brand-700" data-statement-total>{{ money($total) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Lost (one use)') }}</div><div class="stat-value text-navy-500">{{ money($forfeited) }}</div></div>
</div>

<section class="card">
    <table class="table-base">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Order') }}</th><th>{{ __('Serial') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Cashier') }}</th><th>{{ __('Note') }}</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr @class(['line-through opacity-60' => $r->refunded_at]) data-statement-row>
                <td class="whitespace-nowrap">{{ local_time($r->created_at, 'Y/m/d h:i A') }}</td>
                <td>{{ $r->branch ? tr($r->branch->name, $r->branch->name_en) : '—' }}</td>
                <td @if ($r->isManual()) data-statement-paper @endif>{{ \App\Http\Controllers\Dashboard\CorporateContractsController::billLabel($r) ?: '—' }}</td>
                <td dir="ltr" class="font-bold">{{ $r->voucher?->serial }}</td>
                <td class="font-extrabold">{{ money($r->amount) }}@if ($r->forfeited > 0) <span class="text-xs font-normal text-navy-500">+ {{ __(':amount lost', ['amount' => money($r->forfeited)]) }}</span>@endif</td>
                <td>{{ $r->cashier?->name ?? '—' }}</td>
                <td class="text-sm text-navy-600">
                    @if ($r->refunded_at && $r->isManual()){{ __('Cancelled by :name: :reason', ['name' => $r->refunder?->name ?? '—', 'reason' => $r->refund_reason]) }}
                    @elseif ($r->refunded_at){{ __('Order cancelled: value returned') }}@endif
                    @if ($r->note)<div>{{ $r->note }}</div>@endif
                    @if ($r->exception_reason)⚠ {{ __('Exception after expiry by :name: :reason', ['name' => $r->approver?->name ?? '—', 'reason' => $r->exception_reason]) }}@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-8 text-center text-navy-500">{{ __('No uses yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</section>
@endsection
