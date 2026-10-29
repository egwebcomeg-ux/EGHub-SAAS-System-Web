@extends('layouts.dashboard', ['viteEntries' => ['resources/js/voucher-redeem.js']])
@section('title', __('Redeem company vouchers'))

@section('content')
@php
    $methods = ['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet')];
    $data = [
        'currency' => tr($settings['currency'], $settings['currency_en']),
        'canApprove' => $canApprove,
        'lookup' => $lookup,
        'urls' => [
            'check' => route('dash.redeem.check'), 'link' => route('dash.redeem.link'), 'exception' => url('/vouchers/redeem/__ID__/exception'),
            'orders' => route('dash.redeem.orders'), 'pay' => url('/vouchers/redeem/orders/__ID__/pay'), 'manual' => route('dash.redeem.manual'),
            'cancel' => url('/vouchers/redeem/batches/__B__/cancel'),
        ],
    ];
    $i18n = [
        'balance' => __('Balance :balance of :value', ['balance' => '__B__', 'value' => '__V__']), 'until' => __('Valid until :date', ['date' => '__D__']),
        'use' => __('Use'), 'added' => __('Added'), 'askException' => __('Request an exception'), 'confirmLink' => __('Confirm the link'),
        'link' => __('Link to a mobile'), 'linkAsk' => __('The guest\'s mobile number'), 'noneForPhone' => __('No vouchers linked to this mobile.'),
        'noCamera' => __('The camera is not available: type the code.'), 'approvedBy' => __('Approved by :name', ['name' => '__N__']),
        'exception' => __('Exception'), 'notNeeded' => __('Not needed for this bill: stays as it is.'),
        'lost' => __('One use: :amount of it is lost.', ['amount' => '__A__']), 'remove' => __('Remove'),
        'singleWarn' => __('The bill is smaller than the voucher: :amount will be lost.', ['amount' => '__A__']),
        'leftAfter' => __(':amount left after', ['amount' => '__A__']),
        'rest' => __('Left to pay: :amount', ['amount' => '__A__']), 'covered' => __('Fully paid by the vouchers.'),
        'noOrders' => __('No open orders today in this branch.'), 'table' => __('Table :name', ['name' => '__N__']),
        'addVoucher' => __('Add a corporate voucher first.'), 'pickOrder' => __('Choose the order.'), 'enterAmount' => __('Enter the bill amount.'),
        'chooseMethod' => __('Choose how the rest is paid.'), 'order' => __('Order #:n', ['n' => '__N__']), 'paper' => __('Paper bill'),
        'billOf' => __('Bill :amount', ['amount' => '__A__']), 'cancelled' => __('Cancelled'),
    ];
@endphp

<x-dash.page-header :title="__('Redeem company vouchers')" :help="__('Scan or type the voucher code, or the guest\'s mobile, then take it on an open order or on a paper bill.')">
    <x-slot:actions>
        @if ($branches->count() > 1)
            <form>
                <select name="branch" class="field" onchange="this.form.submit()" aria-label="{{ __('Branch') }}">
                    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach
                </select>
            </form>
        @endif
    </x-slot:actions>
</x-dash.page-header>
@include('dashboard.partials.area-tabs', ['area' => 'corporate', 'current' => 'redeem'])

<div class="grid gap-4 lg:grid-cols-5" data-redeem>
    <div class="space-y-4 lg:col-span-3">
        {{-- 1. the voucher --}}
        <section class="card space-y-3">
            <h2 class="section-title"><span class="text-brand-600">1</span> · {{ __('The voucher') }}</h2>
            <div class="flex gap-2">
                <input class="field min-h-14 min-w-0 flex-1 text-xl uppercase" data-query maxlength="300" dir="ltr" autocomplete="off" autofocus
                       placeholder="{{ __('Scan / type the code, or the guest\'s mobile') }}" aria-label="{{ __('Voucher code or guest mobile') }}">
                <button type="button" class="btn-primary min-h-14 px-5" data-check>{{ __('Check') }}</button>
            </div>
            <button type="button" class="btn-ghost hidden w-full" data-scan>📷 {{ __('Scan the QR with the camera') }}</button>
            <video class="hidden w-full rounded-xl bg-black" data-video playsinline muted></video>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700" data-error role="alert"></p>
            <ul class="space-y-2" data-results></ul>
        </section>

        {{-- 2. what it pays for --}}
        <section class="card space-y-3">
            <h2 class="section-title"><span class="text-brand-600">2</span> · {{ __('What does it pay for?') }}</h2>
            <div class="grid grid-cols-2 gap-2" role="tablist">
                <button type="button" class="btn-ghost aria-selected:border-brand-600 aria-selected:bg-brand-50 aria-selected:text-brand-800" role="tab" aria-selected="true" data-target-tab="order">🧾 {{ __('An open order') }}</button>
                <button type="button" class="btn-ghost aria-selected:border-brand-600 aria-selected:bg-brand-50 aria-selected:text-brand-800" role="tab" aria-selected="false" data-target-tab="manual">✍️ {{ __('A paper bill') }}</button>
            </div>

            <div class="space-y-2" data-panel="order">
                @if ($needsShift)
                    <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800" data-needs-shift>
                        {{ __('Open a shift to take payments on orders.') }} <a href="{{ route('dash.shifts') }}" class="underline">{{ __('Open shift') }}</a>
                    </p>
                @endif
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm text-navy-600">{{ __('Today\'s unpaid orders in this branch') }}</span>
                    <button type="button" class="btn-ghost px-3" data-orders-refresh aria-label="{{ __('Refresh') }}">↻ {{ __('Refresh') }}</button>
                </div>
                <ul class="grid max-h-80 gap-2 overflow-y-auto sm:grid-cols-2" data-orders><li class="text-sm text-navy-500">…</li></ul>
            </div>

            <div class="hidden space-y-3" data-panel="manual">
                <label class="block"><span class="field-label">{{ __('Bill amount') }}</span>
                    <input class="field text-xl font-bold" type="number" inputmode="decimal" min="0.01" step="0.01" dir="ltr" data-amount></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="field-label">{{ __('Bill number (optional)') }}</span>
                        <input class="field" maxlength="60" data-reference dir="auto"></label>
                    <label class="block"><span class="field-label">{{ __('Note (optional)') }}</span>
                        <input class="field" maxlength="200" data-note></label>
                </div>
                <p class="text-xs text-navy-500">{{ __('For places that write the order on paper: the vouchers are recorded with no order, and show in the company statement.') }}</p>
            </div>
        </section>

        {{-- 3. confirm --}}
        <section class="card space-y-3" data-summary>
            <h2 class="section-title"><span class="text-brand-600">3</span> · {{ __('Confirm') }}</h2>
            <div class="rounded-xl bg-navy-50 p-3 text-sm text-navy-600" data-empty-summary>{{ __('Add a voucher and choose the order or type the bill amount.') }}</div>
            <div class="hidden divide-y divide-emerald-100 rounded-xl bg-emerald-50 px-3 py-1 text-sm" data-applied></div>
            <div class="hidden text-center text-lg font-bold" data-due></div>
            <div class="hidden grid grid-cols-2 gap-2 sm:grid-cols-4" data-methods>
                @foreach ($methods as $key => $label)
                    <button type="button" class="btn-ghost aria-pressed:border-brand-600 aria-pressed:bg-brand-50 aria-pressed:text-brand-800" aria-pressed="false" data-method="{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700" data-submit-error role="alert"></p>
            <button type="button" class="btn-primary min-h-14 w-full text-lg" data-submit>{{ __('Redeem') }}</button>
        </section>
    </div>

    {{-- today's uses in this branch --}}
    <aside class="lg:col-span-2">
        <section class="card">
            <h2 class="section-title mb-3">{{ __('Today in this branch') }}</h2>
            <ul class="divide-y divide-navy-100" data-recent>
                @forelse ($recent as $r)
                    <li class="py-3" data-recent-row="{{ $r['batch'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-bold">
                                    {{ $r['order'] ? __('Order #:n', ['n' => $r['order']]) : __('Paper bill') . ($r['reference'] ? ' ' . $r['reference'] : '') }}
                                    @if ($r['cancelled'])<span class="badge bg-navy-100 text-navy-500">{{ __('Cancelled') }}</span>@endif
                                    @if ($r['exception'])<span class="badge bg-amber-100 text-amber-800">{{ __('Exception') }}</span>@endif
                                </div>
                                <div class="truncate text-sm text-navy-600">{{ implode('، ', $r['companies']) }} · <span dir="ltr">{{ implode(', ', $r['serials']) }}</span></div>
                                <div class="text-xs text-navy-500">{{ local_time($r['at'], 'h:i A') }} · {{ $r['cashier'] ?? '—' }}@if ($r['cancelled'] && $r['cancelledBy']) · {{ __('cancelled by :name', ['name' => $r['cancelledBy']]) }}@endif</div>
                            </div>
                            <div @class(['text-end font-extrabold', 'line-through opacity-60' => $r['cancelled']])>{{ money($r['amount']) }}</div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <a href="{{ route('dash.redeem.receipt', $r['batch']) }}" target="_blank" class="btn-ghost px-3 text-sm">🖨 {{ __('Receipt') }}</a>
                            @if ($r['manual'] && ! $r['cancelled'])
                                <button type="button" class="btn-ghost px-3 text-sm text-red-700" data-cancel="{{ $r['batch'] }}"
                                        data-cancel-label="{{ __('Paper bill') }} · {{ money($r['amount']) }}">{{ __('Cancel') }}</button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-navy-500" data-recent-empty>{{ __('No vouchers taken today in this branch yet.') }}</li>
                @endforelse
            </ul>
        </section>
    </aside>
</div>

{{-- done: what each voucher gave, the balance left, the guest's receipt --}}
<dialog class="m-auto max-h-[95dvh] w-full max-w-md overflow-y-auto rounded-2xl p-0 backdrop:bg-black/50" data-done-dialog>
    <div class="space-y-3 p-5">
        <div class="text-center text-4xl" aria-hidden="true">✅</div>
        <h3 class="text-center text-lg font-extrabold">{{ __('Vouchers taken') }}</h3>
        <p class="text-center text-sm text-navy-600" data-done-title></p>
        <table class="table-base text-sm">
            <thead><tr><th>{{ __('Voucher') }}</th><th>{{ __('Taken') }}</th><th>{{ __('Balance left') }}</th></tr></thead>
            <tbody data-done-lines></tbody>
        </table>
        <div class="rounded-xl bg-emerald-50 p-3 text-center font-bold text-emerald-800" data-done-total></div>
        <div class="hidden rounded-xl bg-amber-50 p-3 text-center font-bold text-amber-800" data-done-rest></div>
        <div class="grid grid-cols-2 gap-2">
            <a href="#" target="_blank" class="btn-primary" data-done-receipt>🖨 {{ __('Print a receipt') }}</a>
            <button type="button" class="btn-ghost" data-done-new>{{ __('New redemption') }}</button>
        </div>
    </div>
</dialog>

{{-- exception after expiry, and cancelling a paper bill: a manager signed in here, or a manager's PIN on this device --}}
@foreach (['exception' => [__('Exception request'), __('e.g. the company asked to extend, staff came on the last day'), __('Approve')],
           'cancel' => [__('Cancel the paper-bill redemption'), __('e.g. the guest paid cash in the end, typed by mistake'), __('Cancel and return the value')]] as $kind => [$heading, $hint, $cta])
    <dialog class="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-black/50" data-approve-dialog="{{ $kind }}">
        <form method="dialog" class="space-y-3 p-5">
            <h3 class="text-lg font-extrabold">{{ $heading }}</h3>
            <p class="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800" data-approve-subject></p>
            <label class="block"><span class="field-label">{{ __('Why? (short reason)') }}</span>
                <textarea class="field min-h-16 py-2" maxlength="200" data-approve-reason placeholder="{{ $hint }}"></textarea></label>
            @if (! $canApprove)
                @if (count($approvers))
                    <label class="block"><span class="field-label">{{ __('Manager') }}</span>
                        <select class="field" data-approve-manager>
                            @foreach ($approvers as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach
                        </select></label>
                    <label class="block"><span class="field-label">{{ __('Manager PIN') }}</span>
                        <input class="field text-center text-2xl tracking-[.5em]" type="password" inputmode="numeric" maxlength="6" autocomplete="off" dir="ltr" data-approve-pin></label>
                    <p class="text-xs text-navy-500">{{ __('The manager types their PIN here: you stay signed in.') }}</p>
                @else
                    <p class="rounded-xl bg-navy-50 p-3 text-sm">{{ __('No manager has a PIN yet: a manager can sign in on this device, or set a PIN in Account security.') }}</p>
                @endif
            @else
                <p class="text-sm text-navy-600">{{ __('You approve it as :name. It is recorded in the audit log.', ['name' => auth()->user()->name]) }}</p>
            @endif
            <p class="hidden rounded-xl bg-red-50 p-3 text-sm text-red-700" data-approve-error role="alert"></p>
            <div class="flex gap-2">
                <button value="cancel" class="btn-ghost flex-1">{{ __('Back') }}</button>
                <button type="button" class="btn-primary flex-1" data-approve-confirm>{{ $cta }}</button>
            </div>
        </form>
    </dialog>
@endforeach

<script type="application/json" id="redeem-data">{!! json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script type="application/json" id="redeem-i18n">{!! json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection
