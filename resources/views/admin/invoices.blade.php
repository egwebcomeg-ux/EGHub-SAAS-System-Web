@extends('layouts.admin')
@section('title', __('Invoices') . ' · EgMenu Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ __('Invoices') }}</h1>
    <nav class="flex flex-wrap gap-1 text-sm font-semibold">
        @foreach (['awaiting' => __('Awaiting confirmation'), 'open' => __('Unpaid'), 'paid' => __('Paid'), 'void' => __('Void'), 'all' => __('All')] as $key => $label)
            <a href="?status={{ $key }}" @class(['rounded-lg px-3 py-2', 'bg-navy-800 text-white' => $status === $key, 'bg-white text-navy-700 ring-1 ring-navy-100' => $status !== $key])>{{ $label }}</a>
        @endforeach
    </nav>
</div>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Restaurant') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Total') }}</th><th>{{ __('Status') }}</th><th>{{ __('Transfer') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($invoices as $inv)
            @php($claim = $inv->payments->firstWhere('status', 'pending'))
            <tr>
                <td dir="ltr" class="text-start font-semibold">{{ $inv->number }}<div class="text-xs font-normal text-navy-500">{{ local_time($inv->created_at, 'Y/m/d') }}</div></td>
                <td><a class="text-brand-700" href="{{ route('admin.tenants.show', $inv->tenant_id) }}">{{ $inv->tenant?->name ?? $inv->tenant_id }}</a></td>
                <td>{{ $inv->plan?->displayName() }} · {{ cycle_label((int) $inv->cycle_months) }}</td>
                <td class="font-bold">{{ money($inv->total) }}</td>
                <td>@include('partials.invoice-status', ['invoice' => $inv])</td>
                <td class="text-sm">
                    @if ($claim)
                        {{ \App\Billing\PaymentMethods::label($claim->gateway) }} · <span dir="ltr">{{ $claim->reference }}</span>
                        @if ($claim->payload['sender'] ?? null)<div class="text-xs text-navy-500" dir="ltr">{{ $claim->payload['sender'] }}</div>@endif
                    @endif
                </td>
                <td>
                    @if ($inv->status === 'open')
                        <div class="flex flex-wrap gap-2">
                            <form method="post" action="{{ route('admin.invoices.paid', $inv) }}" onsubmit="return confirm(@js(__('Confirm that :amount EGP was received?', ['amount' => money($inv->total)])))">
                                @csrf
                                <input type="hidden" name="gateway" value="{{ $claim->gateway ?? 'manual' }}">
                                <input type="hidden" name="reference" value="{{ $claim->reference ?? '' }}">
                                <input type="hidden" name="amount" value="{{ $inv->total }}">
                                <button class="btn-primary min-h-9 px-3">{{ __('Confirm payment') }}</button>
                            </form>
                            <form method="post" action="{{ route('admin.invoices.void', $inv) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ __('Void') }}</button></form>
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-8 text-center text-navy-500">{{ __('No invoices here.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $invoices->links() }}</div>
</div>
@endsection
