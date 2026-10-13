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

    <section class="card lg:col-span-2">
        @if ($invoice->status === 'paid')
            <p class="font-semibold text-emerald-700">{{ __('Paid on :date. Thank you!', ['date' => local_time($invoice->paid_at, 'Y/m/d')]) }}</p>
        @elseif ($invoice->status === 'void')
            <p class="text-navy-500">{{ __('This invoice was replaced by a newer one.') }}</p>
        @elseif ($pending)
            <p class="font-semibold text-amber-800">{{ __('We received your transfer details (:ref) and will confirm shortly.', ['ref' => $pending->reference]) }}</p>
        @elseif (!$methods)
            <p class="text-navy-500">{{ __('Contact EgWeb support to pay this invoice.') }}</p>
        @else
            <h2 class="mb-2 text-lg font-bold">{{ __('How to pay') }}</h2>
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
@endsection
