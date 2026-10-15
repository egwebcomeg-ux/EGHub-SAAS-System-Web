@extends('layouts.dashboard')
@section('title', __('Wallet & gift cards'))

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="page-title">{{ __('Wallet & gift cards') }}</h1>
    @can('pos.use')<a href="{{ route('dash.pos') }}" class="btn-primary">{{ __('Top up or sell a gift card from the POS') }}</a>@endcan
</div>

@if ($errors->any())
    <div class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
@endif

<div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card"><div class="muted">{{ __('Balances owed to guests') }}</div><div class="text-2xl font-extrabold text-brand-700" data-liability>{{ money($totals['liability']) }}</div></div>
    <div class="card"><div class="muted">{{ __('Unused gift cards') }}</div><div class="text-2xl font-extrabold">{{ money($totals['gifts']) }}</div></div>
    <div class="card"><div class="muted">{{ __('Guests with a balance') }}</div><div class="text-2xl font-extrabold">{{ $totals['customers'] }}</div></div>
    <div class="card"><div class="muted">{{ __('Top-ups this month') }}</div><div class="text-2xl font-extrabold">{{ money($totals['month_topups']) }}</div></div>
</div>
<p class="muted mb-4">{{ __('Balances and unused gift cards are money guests already paid you: they come back to you as orders. Paid at the counter, they are in the shift report.') }}</p>

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap gap-2">
        @foreach (['balances' => __('Balances'), 'ledger' => __('Ledger'), 'gifts' => __('Gift cards')] as $key => $label)
            <a href="{{ route('dash.wallet', ['tab' => $key]) }}" @class(['btn min-h-9 rounded-full px-4', 'bg-navy-800 text-white' => $tab === $key, 'bg-white text-navy-700 border border-navy-100' => $tab !== $key])>{{ $label }}</a>
        @endforeach
    </div>
    <form class="flex gap-2">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input class="field min-h-9 max-w-56" name="q" value="{{ $q }}" placeholder="{{ $tab === 'gifts' ? __('Code or mobile') : __('Search name or mobile') }}">
        <button class="btn-ghost min-h-9">{{ __('Search') }}</button>
    </form>
</div>

<div class="card overflow-x-auto">
    @if ($tab === 'balances')
        <table class="table-base">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Balance') }}</th>@if ($canAdjust)<th>{{ __('Adjust') }}</th>@endif</tr></thead>
            <tbody>
            @forelse ($balances as $c)
                <tr>
                    <td class="font-semibold">{{ $c->name ?: '—' }}</td>
                    <td dir="ltr" class="text-start">{{ $c->phone }}</td>
                    <td class="font-extrabold">{{ money($c->credit_balance) }}</td>
                    @if ($canAdjust)
                        <td>
                            <form method="post" action="{{ route('dash.wallet.adjust', $c) }}" class="flex flex-wrap gap-1" onsubmit="return confirm(@js(__('Change this balance?')))">
                                @csrf
                                <input class="field min-h-9 w-24" type="number" step="0.01" name="delta" required placeholder="±">
                                <input class="field min-h-9 w-40" name="note" required maxlength="200" placeholder="{{ __('Reason') }}">
                                <button class="btn-ghost min-h-9">{{ __('Save') }}</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="4" class="py-10 text-center text-navy-500">{{ __('No guest has a balance yet. Top up from the POS or sell a gift card.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $balances->links() }}</div>
    @elseif ($tab === 'ledger')
        <table class="table-base">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Type') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Balance after') }}</th><th>{{ __('By') }}</th><th>{{ __('Note') }}</th></tr></thead>
            <tbody>
            @forelse ($ledger as $t)
                <tr>
                    <td>{{ local_time($t->created_at, 'Y/m/d h:i A') }}</td>
                    <td>{{ $t->customer?->name ?: '' }} <span dir="ltr">{{ $t->customer?->phone }}</span></td>
                    <td>{{ $t->kindLabel() }}@if ($t->method) · {{ \App\Billing\PaymentMethods::label($t->method) }}@endif</td>
                    <td @class(['font-bold', 'text-emerald-700' => $t->delta > 0, 'text-red-600' => $t->delta < 0])><bdi dir="ltr">{{ $t->delta > 0 ? '+' : '' }}{{ money($t->delta) }}</bdi></td>
                    <td>{{ money($t->balance_after) }}</td>
                    <td>{{ $t->user?->name ?? '—' }}</td>
                    <td>{{ $t->note }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10 text-center text-navy-500">{{ __('Nothing yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $ledger->links() }}</div>
    @else
        <table class="table-base">
            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th>{{ __('Recipient') }}</th><th>{{ __('Sold') }}</th><th>{{ __('Valid until') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($gifts as $g)
                <tr>
                    <td dir="ltr" class="text-start font-mono font-bold">{{ $g->formattedCode() }}</td>
                    <td class="font-bold">{{ money($g->amount) }}</td>
                    <td>
                        <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $g->statusKey() === 'active', 'bg-navy-100 text-navy-700' => $g->statusKey() === 'redeemed', 'bg-red-100 text-red-700' => in_array($g->statusKey(), ['void', 'expired'], true)])>{{ $g->statusLabel() }}</span>
                        @if ($g->redeemedBy)<div class="text-xs text-navy-500"><span dir="ltr">{{ $g->redeemedBy->phone }}</span> · {{ local_time($g->redeemed_at, 'Y/m/d') }}</div>@endif
                    </td>
                    <td dir="ltr" class="text-start">{{ $g->recipient_phone ?: '—' }}</td>
                    <td>{{ local_time($g->created_at, 'Y/m/d') }}<div class="text-xs text-navy-500">{{ $g->seller?->name }} · {{ \App\Billing\PaymentMethods::label($g->payment_method ?? 'cash') }}</div></td>
                    <td>{{ $g->expires_at ? local_time($g->expires_at, 'Y/m/d') : '—' }}</td>
                    <td class="flex flex-wrap gap-3">
                        <a href="{{ route('dash.wallet.gift.print', $g) }}" target="_blank" class="text-brand-700">{{ __('Print') }}</a>
                        @if ($g->statusKey() === 'active')
                            <form method="post" action="{{ route('dash.wallet.gift.void', $g) }}" onsubmit="return confirm(@js(__('Void this gift card? It can no longer be added to a wallet.')))">
                                @csrf
                                <button class="text-red-600">{{ __('Void') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-10 text-center text-navy-500">{{ __('No gift cards sold yet. Sell one from the POS.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $gifts->links() }}</div>
    @endif
</div>
@endsection
