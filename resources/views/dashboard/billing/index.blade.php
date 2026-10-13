@extends('layouts.dashboard')
@section('title', __('Subscription'))

@section('content')
<h1 class="mb-4 text-2xl font-extrabold">{{ __('Subscription') }}</h1>

@if ($subscription)
    <section class="card mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <div class="text-sm text-navy-500">{{ __('Current plan') }}</div>
            <div class="text-xl font-extrabold">{{ $subscription->plan->displayName() }}</div>
        </div>
        <div>
            <div class="text-sm text-navy-500">{{ __('Status') }}</div>
            <div class="font-bold">{{ $subscription->statusLabel() }}</div>
        </div>
        <div>
            <div class="text-sm text-navy-500">{{ $subscription->status === 'trialing' ? __('Trial ends') : __('Paid until') }}</div>
            <div class="font-bold">{{ $subscription->endsAt() ? local_time($subscription->endsAt(), 'Y/m/d') : '—' }}
                @if ($subscription->endsAt()) <span class="text-sm font-normal text-navy-500">({{ __(':days days left', ['days' => $subscription->daysLeft()]) }})</span>@endif
            </div>
        </div>
        @if ($subscription->status === 'past_due' && $subscription->grace_ends_at)
            <div class="w-full rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700">
                {{ __('Your subscription ended. Pay before :date to keep your menu online.', ['date' => local_time($subscription->grace_ends_at, 'Y/m/d')]) }}
            </div>
        @endif
    </section>
@endif

<h2 class="mb-3 text-lg font-bold">{{ __('Choose a plan') }}</h2>
<div class="grid gap-4 lg:grid-cols-3">
    @foreach ($plans as $p)
        @php($current = $subscription && $subscription->plan_id === $p->id)
        <form method="post" action="{{ route('dash.billing.checkout') }}" @class(['card flex flex-col', 'ring-2 ring-brand-500' => $p->is_featured])>
            @csrf
            <input type="hidden" name="plan_id" value="{{ $p->id }}">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-extrabold">{{ $p->displayName() }}</h3>
                @if ($current)<span class="badge bg-navy-800 text-white">{{ __('Current') }}</span>@elseif ($p->is_featured)<span class="badge bg-brand-600 text-white">{{ __('Most popular') }}</span>@endif
            </div>
            <p class="mt-1 min-h-10 text-sm text-navy-500">{{ tr($p->tagline, $p->tagline_en) }}</p>
            <div class="my-3"><span class="text-3xl font-extrabold">{{ money($p->monthly_price) }}</span> <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span></div>

            <div class="mb-5 grid grid-cols-3 gap-2" role="radiogroup">
                @foreach (\App\Models\Plan::CYCLES as $m)
                    @php($pct = (float) ($p->cycle_discounts[(string) $m] ?? 0))
                    <label class="cursor-pointer rounded-xl border border-navy-100 p-2 text-center text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="months" value="{{ $m }}" class="sr-only" @checked($m === 1)>
                        <span class="block font-bold">{{ cycle_label($m) }}</span>
                        <span class="block text-xs text-navy-500">{{ money($p->priceFor($m)) }}</span>
                        @if ($pct > 0)<span class="block text-xs font-bold text-emerald-700">-{{ money($pct) }}%</span>@endif
                    </label>
                @endforeach
            </div>

            <div class="mb-5 flex-1">@include('partials.plan-features', ['plan' => $p])</div>
            <button class="{{ $p->is_featured ? 'btn-primary' : 'btn-navy' }} w-full">{{ $current ? __('Renew') : __('Choose :plan', ['plan' => $p->displayName()]) }}</button>
        </form>
    @endforeach
</div>

@if ($invoices->isNotEmpty())
    <section class="card mt-6 overflow-x-auto">
        <h2 class="mb-3 text-lg font-bold">{{ __('Invoices') }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Total') }}</th><th>{{ __('Status') }}</th><th>{{ __('Date') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($invoices as $inv)
                <tr>
                    <td dir="ltr" class="text-start font-semibold">{{ $inv->number }}</td>
                    <td>{{ $inv->plan?->displayName() }} · {{ cycle_label((int) $inv->cycle_months) }}</td>
                    <td>{{ money($inv->total) }} {{ __('EGP') }}</td>
                    <td>@include('partials.invoice-status', ['invoice' => $inv])</td>
                    <td>{{ local_time($inv->created_at, 'Y/m/d') }}</td>
                    <td><a href="{{ route('dash.billing.invoice', $inv->number) }}" class="text-brand-700">{{ __('View') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif
@endsection
