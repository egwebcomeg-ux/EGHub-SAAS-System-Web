@extends('layouts.dashboard')
@section('title', __('Subscription'))

@section('content')
<x-dash.page-header :title="__('Subscription')" :help="__('Your plan, add-ons, WhatsApp credits and invoices.')" />

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
<div class="grid gap-4 lg:grid-cols-3" data-billing-plans>
    @foreach ($plans as $p)
        @php
            $current = $subscription && $subscription->plan_id === $p->id;
        @endphp
        @if ($p->isContactSales())
            <div class="card flex flex-col border-2 border-dashed border-navy-200" data-billing-plan="{{ $p->code }}" data-contact-sales>
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-extrabold">{{ $p->displayName() }}</h3>
                    @if ($current)<span class="badge bg-navy-800 text-white">{{ __('Current') }}</span>@endif
                </div>
                <p class="mt-1 min-h-10 text-sm text-navy-500">{{ tr($p->tagline, $p->tagline_en) }}</p>
                <div class="my-3 text-2xl font-extrabold">{{ __('Price on request') }}</div>
                <p class="mb-2 text-sm font-bold text-navy-900">{{ __('Everything in Complete, plus:') }}</p>
                <div class="mb-5 flex-1">@include('partials.enterprise-includes')</div>
                <a href="{{ $p->contactUrl(tenant()->name) }}" target="_blank" rel="noopener" class="btn-navy w-full" data-contact-sales-link>{{ __('Contact us') }}</a>
                <p class="mt-2 text-center text-xs text-navy-500">{{ __('We agree the price with you, then set it up from our side.') }}</p>
            </div>
            @continue
        @endif
        <form method="post" action="{{ route('dash.billing.checkout') }}" @class(['card flex flex-col', 'ring-2 ring-brand-500' => $p->is_featured]) data-billing-plan="{{ $p->code }}">
            @csrf
            <input type="hidden" name="plan_id" value="{{ $p->id }}">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-extrabold">{{ $p->displayName() }}</h3>
                @if ($current)<span class="badge bg-navy-800 text-white">{{ __('Current') }}</span>@elseif ($p->is_featured)<span class="badge bg-brand-600 text-white">{{ __('Most popular') }}</span>@endif
            </div>
            @unless ($p->is_public)<p class="mt-1 text-xs font-semibold text-navy-500" data-legacy-plan>{{ __('An older plan: it stays as it is for you, and you can renew it.') }}</p>@endunless
            <p class="mt-1 min-h-10 text-sm text-navy-500">{{ tr($p->tagline, $p->tagline_en) }}</p>
            <div class="my-3"><span class="text-3xl font-extrabold">{{ money($p->monthly_price) }}</span> <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span></div>
            @if ($p->code === \App\Billing\LaunchOffer::PLAN && \App\Billing\LaunchOffer::isRunning())
                <p class="mb-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800" data-launch-offer>{{ __('Launch offer: the second branch is free for the first 12 months.') }}</p>
            @endif

            @if ((float) $p->monthly_price > 0)
                <div class="mb-5 grid grid-cols-3 gap-2" role="radiogroup">
                    @foreach (\App\Models\Plan::CYCLES as $m)
                        @php
                            $pct = (float) ($p->cycle_discounts[(string) $m] ?? 0);
                        @endphp
                        <label class="cursor-pointer rounded-xl border border-navy-100 p-2 text-center text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="months" value="{{ $m }}" class="sr-only" @checked($m === 1)>
                            <span class="block font-bold">{{ cycle_label($m) }}</span>
                            <span class="block text-xs text-navy-500">{{ money($p->priceFor($m)) }}</span>
                            @if ($pct > 0)<span class="block text-xs font-bold text-emerald-700">-{{ money($pct) }}%</span>@endif
                        </label>
                    @endforeach
                </div>
                @if ($installmentsPromise)
                    <p class="-mt-3 mb-4 text-xs font-semibold text-emerald-800" data-installments-promise>{{ $installmentsPromise }}</p>
                @endif
            @else
                <input type="hidden" name="months" value="1">
            @endif

            <div class="mb-5 flex-1">@include('partials.plan-features', ['plan' => $p])</div>
            @if ((float) $p->monthly_price > 0)
                <button class="{{ $p->is_featured ? 'btn-primary' : 'btn-navy' }} w-full">{{ $current ? ($subscription?->status === 'trialing' ? __('Subscribe') : __('Renew')) : __('Choose :plan', ['plan' => $p->displayName()]) }}</button>
            @endif
        </form>
    @endforeach
</div>

@if ($addons->isNotEmpty())
    <h2 class="mt-8 mb-1 text-lg font-bold">{{ __('Add-ons') }}</h2>
    <p class="mb-3 muted">{{ __('For bigger restaurants: add only what you need, on top of any plan.') }}</p>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($addons as $addon)
            @php
                $qty = (int) ($myAddons[$addon->key] ?? 0);
            @endphp
            <div class="card flex flex-col">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-bold">{{ $addon->displayName() }}</h3>
                    @if ($qty > 0)<span class="badge bg-emerald-100 text-emerald-800">{{ $addon->isFeaturePack() ? __('Active') : '× ' . $qty }}</span>@endif
                </div>
                <p class="mt-1 flex-1 text-sm text-navy-500">{{ $addon->displayDescription() }}</p>
                @if ($addon->key === 'branches' && $launchOffer)
                    <p class="mt-2 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-800" data-launch-offer-branch>{{ __('Your first extra branch is free until :date (launch offer).', ['date' => local_time($launchOffer, 'Y/m/d')]) }}</p>
                @endif
                @php
                    $myPrice = $addon->priceFor(tenant());
                    $branchCap = $addon->key === 'branches' && $qty >= \App\Models\AddonPrice::MAX_SELF_SERVE_EXTRA_BRANCHES;
                    $planOk = $addon->availableFor($subscription?->plan);
                    $covered = $addon->isFeaturePack() && $qty === 0 && $addon->features && collect($addon->features)->every(fn ($f) => \App\Services\Entitlements::current()->allows($f));
                @endphp
                <div class="mt-3 flex items-center justify-between gap-2">
                    <span>
                        <b class="text-lg">{{ money($myPrice) }}</b> <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span>
                        @if ($addon->per_branch && $myPrice > $addon->monthly_price)<span class="block text-xs text-navy-500">{{ __('For all your branches (40% off each extra branch)') }}</span>@endif
                    </span>
                    @if ($branchCap)
                        <a href="{{ $plans->first(fn ($pl) => $pl->isContactSales())?->contactUrl(tenant()->name) ?? route('services') }}" target="_blank" rel="noopener" class="text-xs font-bold text-brand-700" data-branch-cap>{{ __('More than 5 branches? Contact us') }}</a>
                    @elseif (! $addon->is_active)
                        <span class="text-xs text-navy-500">{{ __('No longer sold') }}</span>
                    @elseif (! $planOk)
                        <span class="text-xs text-navy-500">{{ __('Needs the :plan plan', ['plan' => $plans->firstWhere('code', $addon->min_plan)?->displayName()]) }}</span>
                    @elseif ($covered)
                        <span class="badge bg-emerald-100 text-emerald-800">{{ __('Included') }}</span>
                    @elseif (! $addon->isFeaturePack() || $qty === 0)
                        <form method="post" action="{{ route('dash.billing.addon', $addon->key) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ $addon->isFeaturePack() ? __('Add') : __('Add one') }}</button></form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif

@if ($creditPacks->isNotEmpty())
    <section class="mt-8" id="whatsapp-credits">
        <h2 class="mb-1 text-lg font-bold">{{ __('WhatsApp credits') }}</h2>
        <p class="mb-3 muted">{{ __('Every WhatsApp message to a guest (order updates, campaigns) uses one credit. Credits are paid once and never expire.') }}</p>
        <div class="grid gap-4 md:grid-cols-3">
            <div @class(['card flex flex-col justify-center', 'ring-2 ring-red-300' => $creditBalance < 1])>
                <div class="text-sm text-navy-500">{{ __('Your balance') }}</div>
                <div class="text-3xl font-extrabold">{{ number_format($creditBalance) }} <span class="text-base font-semibold text-navy-500">{{ __('messages') }}</span></div>
                @if ($creditBalance < 1)<div class="mt-1 text-sm font-semibold text-red-700">{{ __('Your WhatsApp credits ran out') }}</div>@endif
            </div>
            @foreach ($creditPacks as $pack)
                <div class="card flex flex-col">
                    <h3 class="font-bold">{{ $pack->displayName() }}</h3>
                    <p class="mt-1 flex-1 text-sm text-navy-500">{{ tr($pack->description, $pack->description_en) }}</p>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <span><b class="text-lg">{{ money($pack->monthly_price) }}</b> <span class="text-sm text-navy-500">{{ __('EGP') }}</span>
                            <span class="block text-xs text-navy-500">{{ __(':price EGP per message', ['price' => money(round($pack->monthly_price / max(1, $pack->credits), 2))]) }}</span></span>
                        <form method="post" action="{{ route('dash.billing.addon', $pack->key) }}">@csrf<button class="btn-ghost min-h-9 px-3">{{ __('Buy') }}</button></form>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

@if ($invoices->isNotEmpty())
    <section class="card mt-6 overflow-x-auto">
        <h2 class="mb-3 text-lg font-bold">{{ __('Invoices') }}</h2>
        <table class="table-base">
            <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Total') }}</th><th>{{ __('Status') }}</th><th>{{ __('Date') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($invoices as $inv)
                <tr>
                    <td dir="ltr" class="text-start font-semibold">{{ $inv->number }}</td>
                    <td>{{ $inv->description() }}</td>
                    <td>{{ money($inv->total) }} {{ __('EGP') }}</td>
                    <td>@include('partials.invoice-status', ['invoice' => $inv])</td>
                    <td>{{ local_time($inv->created_at, 'Y/m/d') }}</td>
                    <td>
                        @if ($onlineEnabled && $inv->status === 'open' && $inv->total > 0)
                            <a href="{{ route('dash.billing.invoice', $inv->number) }}#pay-online" class="btn-primary min-h-9 px-3" data-pay-online-link>{{ __('Pay online') }}</a>
                        @else
                            <a href="{{ route('dash.billing.invoice', $inv->number) }}" class="text-brand-700">{{ __('View') }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endif
@endsection
