@extends('layouts.dashboard')
@section('title', __('Invoice') . ' ' . $invoice->number)

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ __('Invoice') }} <span dir="ltr">{{ $invoice->number }}</span></h1>
    <a href="{{ route('dash.billing') }}" class="btn-ghost">{{ __('Back to subscription') }}</a>
</div>

<div class="grid gap-4 lg:grid-cols-5">
    <section class="card lg:col-span-3">
        <div class="mb-4 flex items-center justify-between">
            <div class="text-sm text-navy-500">{{ local_time($invoice->created_at, 'Y/m/d') }}</div>
            @include('partials.invoice-status')
        </div>
        <table class="table-base">
            <tbody>
            @foreach ($invoice->lines as $line)
                <tr><td>{{ $line['label'] }}</td><td class="text-end font-semibold" dir="ltr">{{ money($line['amount']) }}</td></tr>
            @endforeach
            <tr><td class="text-lg font-extrabold">{{ __('Total') }}</td><td class="text-end text-lg font-extrabold">{{ money($invoice->total) }} {{ __('EGP') }}</td></tr>
            </tbody>
        </table>
    </section>

    <div class="space-y-4 lg:col-span-2">
    @if ($invoice->status === 'open' && ($payNow || $installments))
        <section class="card" id="pay-online" data-pay-online>
            <h2 class="mb-1 text-lg font-bold">{{ __('Pay online') }}</h2>
            <p class="mb-3 text-sm text-navy-500">{{ __('You pay on Kashier’s secure page; your plan switches on by itself once the payment is confirmed.') }}</p>
            @if ($online?->status === 'pending' && $online->created_at->gt(now()->subHours(2)))
                <p class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800" data-online-processing>
                    {{ __('A payment is being confirmed…') }}
                    <a href="{{ route('dash.billing.return', $invoice->number) }}" class="underline">{{ __('Check again') }}</a>
                </p>
            @elseif ($online?->status === 'failed')
                <p class="mb-3 rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700" data-online-failed>{{ __('The last online payment did not go through. You can try again.') }}</p>
            @endif
            @error('online')<p class="mb-3 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror

            @if ($payNow)
                <form method="post" action="{{ route('dash.billing.pay', $invoice->number) }}" class="rounded-xl border border-navy-100 p-3" data-pay-now>
                    @csrf
                    <input type="hidden" name="plan" value="now">
                    <div class="mb-2 font-bold">{{ __('Pay now: :amount EGP', ['amount' => money($invoice->total)]) }}</div>
                    <div class="mb-3 flex flex-wrap gap-1.5">
                        @foreach ($payNow as $m)<span class="rounded-full bg-navy-50 px-2.5 py-1 text-xs font-bold text-navy-700">{{ \App\Billing\OnlinePayments::label($m) }}</span>@endforeach
                    </div>
                    <button class="btn-primary w-full">{{ __('Pay now') }}</button>
                </form>
            @endif
            @if ($installments)
                <form method="post" action="{{ route('dash.billing.pay', $invoice->number) }}" class="mt-3 rounded-xl border border-navy-100 p-3" data-pay-installments>
                    @csrf
                    <input type="hidden" name="plan" value="installments">
                    <div class="mb-1 font-bold">{{ __('Pay in installments') }}</div>
                    <p class="mb-2 text-xs text-navy-500">{{ __('The provider shows its plans and approves you on the next page.') }}</p>
                    <div class="mb-3 flex flex-wrap gap-1.5">
                        @foreach ($installments as $m)<span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-bold text-brand-700" data-installment-provider="{{ $m }}">{{ \App\Billing\OnlinePayments::label($m) }}</span>@endforeach
                    </div>
                    <button class="btn-navy w-full">{{ __('Pay in installments') }}</button>
                </form>
            @endif
        </section>
    @endif

    <section class="card">
        @if ($invoice->status === 'paid')
            <p class="font-semibold text-emerald-700">{{ __('Paid on :date. Thank you!', ['date' => local_time($invoice->paid_at, 'Y/m/d')]) }}</p>
        @elseif ($invoice->status === 'void')
            <p class="text-navy-500">{{ __('This invoice was replaced by a newer one.') }}</p>
        @elseif ($pending)
            <p class="font-semibold text-amber-800">{{ __('We received your transfer details (:ref) and will confirm shortly.', ['ref' => $pending->reference]) }}</p>
        @elseif (!$methods)
            <p class="text-navy-500">{{ $payNow || $installments ? __('Prefer a transfer? Contact EgWeb support.') : __('Contact EgWeb support to pay this invoice.') }}</p>
        @else
            <h2 class="mb-2 text-lg font-bold">{{ $payNow || $installments ? __('Or pay by transfer') : __('How to pay') }}</h2>
            <ol class="mb-4 space-y-2 text-sm">
                @foreach ($methods as $m)
                    <li class="rounded-xl bg-navy-50 p-3">
                        <div class="font-bold">{{ $m['label'] }}</div>
                        <div dir="ltr" class="text-start font-mono text-base">{{ $m['account'] }}</div>
                    </li>
                @endforeach
            </ol>
            @if ($instructions)<p class="mb-4 whitespace-pre-line text-sm text-navy-500">{{ $instructions }}</p>@endif

            <form method="post" action="{{ route('dash.billing.claim', $invoice->number) }}" class="space-y-3">
                @csrf
                <p class="text-sm font-semibold">{{ __('After transferring :amount EGP, send us the details:', ['amount' => money($invoice->total)]) }}</p>
                <select class="field" name="method" required>
                    @foreach ($methods as $m)<option value="{{ $m['key'] }}">{{ $m['label'] }}</option>@endforeach
                </select>
                <input class="field" name="reference" required maxlength="80" placeholder="{{ __('Transaction reference') }}" dir="ltr">
                <input class="field" name="sender" maxlength="40" placeholder="{{ __('Sender number / name (optional)') }}" dir="ltr">
                <button class="btn-primary w-full">{{ __('I have paid') }}</button>
            </form>
        @endif
    </section>
    </div>
</div>
@endsection
