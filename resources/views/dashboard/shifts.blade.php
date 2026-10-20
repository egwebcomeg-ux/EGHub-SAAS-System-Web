@extends('layouts.dashboard')
@section('title', __('Shifts'))

@section('content')
@php
    $methods = ['cash' => __('Cash'), 'card' => __('Card'), 'instapay' => 'InstaPay', 'wallet' => __('Mobile wallet'), 'credit' => __('Wallet balance'), 'corporate' => __('Corporate voucher')];
@endphp
@php
    $posArea = auth()->user()->can('pos.use') && \App\Services\Entitlements::current()->allows('pos');
@endphp
<x-dash.page-header :title="$posArea ? __('POS') : __('Shifts')" :help="__('The cashier screen and its shifts: open a shift with the cash in the drawer, close it with the count.')">
    <x-slot:actions>
        @can('pos.use')<a href="{{ route('dash.pos') }}" class="btn-primary">{{ __('Go to POS') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>
{{-- shifts are a tab of the POS area (the cashier screen itself opens full screen) --}}
@if ($posArea)
    @include('dashboard.pos.tabs', ['current' => 'shifts'])
@endif

@if (!$current)
    <form method="post" action="{{ route('dash.shifts.open') }}" class="card max-w-md space-y-3">
        @csrf
        <h2 class="section-title">{{ __('Open a shift') }}</h2>
        <p class="muted">{{ __('Count the cash in the drawer before you start.') }} · {{ tr($branch->name, $branch->name_en) }}</p>
        {{-- this device's terminal (picked on the POS, kept in this browser): one open shift per terminal --}}
        <input type="hidden" name="terminal_id" data-shift-terminal-id>
        <input type="hidden" name="terminal_token" data-shift-terminal-token>
        <p class="hidden rounded-xl bg-navy-50 p-2 text-sm font-semibold" data-shift-terminal-label></p>
        <label class="field-label" for="opening_cash">{{ __('Cash in drawer') }}</label>
        <input class="field text-lg" id="opening_cash" name="opening_cash" type="number" min="0" step="1" required autofocus>
        <button class="btn-primary w-full">{{ __('Open shift') }}</button>
    </form>
@else
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="card lg:col-span-2">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 class="section-title">{{ __('Current shift') }}</h2>
                <span class="muted">{{ __('Since :time', ['time' => local_time($current->opened_at)]) }}@if ($current->terminal) · <b data-shift-terminal>{{ $current->terminal->label() }}</b>@endif</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-navy-50 p-3"><div class="muted">{{ __('Opening cash') }}</div><div class="text-xl font-extrabold">{{ money($current->opening_cash) }}</div></div>
                <div class="rounded-xl bg-navy-50 p-3"><div class="muted">{{ __('Paid orders') }}</div><div class="text-xl font-extrabold">{{ $summary['orders'] }}</div></div>
                <div class="rounded-xl bg-brand-50 p-3"><div class="muted">{{ __('Expected cash in drawer') }}</div><div class="text-xl font-extrabold text-brand-700">{{ money($summary['expected_cash']) }}</div></div>
            </div>
            <table class="table-base mt-4">
                <tbody>
                @foreach ($methods as $key => $label)
                    @continue(in_array($key, ['credit', 'corporate'], true) && empty($summary['by_method'][$key]))
                    <tr @if ($key === 'corporate') data-shift-corporate @endif><td>{{ $label }}@if ($key === 'corporate') <span class="muted">· {{ __('not cash in the drawer') }}</span>@endif</td><td class="text-end font-semibold">{{ money($summary['by_method'][$key]) }}</td></tr>
                @endforeach
                @foreach ($summary['prepaid'] as $key => $amount)
                    <tr><td>{{ __('Wallet top-ups & gift cards') }} · {{ $methods[$key] ?? $key }}</td><td class="text-end font-semibold text-emerald-700">+{{ money($amount) }}</td></tr>
                @endforeach
                <tr><td>{{ __('Cash in') }}</td><td class="text-end font-semibold text-emerald-700">+{{ money($summary['cash_in']) }}</td></tr>
                <tr><td>{{ __('Cash out') }}@if ($summary['expenses'] > 0) <span class="muted">· {{ __('of it expenses :amount', ['amount' => money($summary['expenses'])]) }}</span>@endif</td><td class="text-end font-semibold text-navy-700">−{{ money($summary['cash_out']) }}</td></tr>
                @foreach ($summary['refunds'] as $key => $amount)
                    <tr data-shift-refund><td>{{ __('Refunds') }} · {{ $methods[$key] ?? $key }} <span class="muted">· {{ __('already taken off above') }}</span></td><td class="text-end font-semibold text-red-600">−{{ money($amount) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            @if ($movements->isNotEmpty())
                <ul class="mt-4 space-y-1 text-sm">
                    @foreach ($movements as $m)
                        <li class="flex justify-between" data-movement="{{ $m->category ?? $m->type }}"><span>{{ local_time($m->created_at, 'h:i A') }} · {{ $m->reason }}@if ($m->categoryLabel()) <span class="badge bg-navy-100 text-navy-700">{{ $m->categoryLabel() }}</span>@endif @if ($m->method && $m->method !== 'cash') · {{ $methods[$m->method] ?? $m->method }}@endif</span><b @class(['text-emerald-700' => $m->type !== 'out', 'text-red-600' => $m->type === 'out'])>{{ $m->type === 'out' ? '−' : '+' }}{{ money($m->amount) }}</b></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="space-y-4">
            <form method="post" action="{{ route('dash.shifts.move') }}" class="card space-y-2">
                @csrf
                <h2 class="section-title">{{ __('Cash in / out') }}</h2>
                <div class="grid grid-cols-2 gap-2">
                    <select class="field" name="type"><option value="out">{{ __('Cash out') }}</option><option value="in">{{ __('Cash in') }}</option></select>
                    <input class="field" name="amount" type="number" min="1" step="1" required placeholder="{{ __('Amount') }}">
                </div>
                <select class="field" name="category" aria-label="{{ __('Category') }}">
                    <option value="">{{ __('Category (optional)') }}</option>
                    @foreach (\App\Models\CashMovement::CATEGORIES as $key => $label)<option value="{{ $key }}">{{ tr($label[0], $label[1]) }}</option>@endforeach
                </select>
                <input class="field" name="reason" required maxlength="120" placeholder="{{ __('Reason, e.g. buying ice') }}">
                <button class="btn-ghost w-full">{{ __('Save') }}</button>
            </form>
            <form method="post" action="{{ route('dash.shifts.close') }}" class="card space-y-2" onsubmit="return confirm(@js(__('Close the shift?')))">
                @csrf
                <h2 class="section-title">{{ __('Close shift') }}</h2>
                <label class="field-label">{{ __('Counted cash in drawer') }}</label>
                <input class="field text-lg" name="closing_cash" type="number" min="0" step="1" required>
                <input class="field" name="notes" maxlength="300" placeholder="{{ __('Notes (optional)') }}">
                <button class="btn-navy w-full">{{ __('Close shift') }}</button>
            </form>
        </div>
    </div>
@endif

@if ($history->isNotEmpty())
    <section class="card mt-6 overflow-x-auto">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="section-title">{{ __('Closed shifts') }}</h2>
            @if ($terminals->isNotEmpty())
                <form class="flex items-center gap-2" data-shifts-by-terminal>
                    <label class="muted text-sm" for="terminal-filter">{{ __('Terminal') }}</label>
                    <select class="field min-h-10 py-0" id="terminal-filter" name="terminal" onchange="this.form.submit()">
                        <option value="">{{ __('All terminals') }}</option>
                        @foreach ($terminals as $t)<option value="{{ $t->id }}" @selected($terminal === $t->id)>{{ $t->label() }}</option>@endforeach
                    </select>
                </form>
            @endif
        </div>
        <table class="table-base">
            <thead><tr><th>{{ __('Cashier') }}</th><th>{{ __('Terminal') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Total') }}</th><th>{{ __('Expected cash') }}</th><th>{{ __('Counted') }}</th><th>{{ __('Difference') }}</th></tr></thead>
            <tbody>
            @foreach ($history as ['shift' => $s, 'summary' => $sum])
                <tr>
                    <td>{{ $s->user?->name }}</td>
                    <td>{{ $s->terminal?->label() ?? '—' }}</td>
                    <td>{{ local_time($s->opened_at, 'm/d h:i A') }}</td>
                    <td>{{ local_time($s->closed_at, 'm/d h:i A') }}</td>
                    <td>{{ money($sum['total']) }}</td>
                    <td>{{ money($s->expected_cash) }}</td>
                    <td>{{ money($s->closing_cash) }}</td>
                    <td @class(['font-bold', 'text-red-600' => $sum['difference'] < 0, 'text-emerald-700' => $sum['difference'] >= 0])>{{ money($sum['difference']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif
<script>
    // the terminal this browser picked on the POS goes with the shift it opens
    (() => {
        let t = null;
        try { t = JSON.parse(localStorage.getItem(@js('egmenu.pos.terminal.' . tenant()->getTenantKey())) || 'null'); } catch (e) {}
        const id = document.querySelector('[data-shift-terminal-id]');
        if (!t || !id) return;
        id.value = t.id;
        document.querySelector('[data-shift-terminal-token]').value = t.token;
        const label = document.querySelector('[data-shift-terminal-label]');
        label.textContent = @js(__('Terminal: :name', ['name' => '__N__'])).replace('__N__', t.name + ' · ' + t.code);
        label.classList.remove('hidden');
    })();
</script>
@endsection
