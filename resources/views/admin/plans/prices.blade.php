@extends('layouts.admin')
@section('title', __('Prices') . ' · EgHub Admin')

@section('content')
@include('admin.plans.tabs')
@php
    $typeOf = fn ($a) => $a->isFeaturePack() ? 'feature' : ($a->isCreditPack() ? 'credits' : 'limit');
    $groups = [
        'feature' => __('Modules (feature packs)'),
        'limit' => __('Extra limits'),
        'credits' => __('WhatsApp credits'),
    ];
@endphp
<p class="muted mb-3">{{ __('Every price on one page: change what you need and save once. Restaurants see the new prices on their next invoice; an invoice already issued keeps its amount.') }}</p>

<form method="post" action="{{ route('admin.prices.update') }}" class="space-y-4" data-prices-form>
    @csrf

    <section class="card">
        <h2 class="section-title">{{ __('Pricing rules') }}</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="field-label" for="r-min">{{ __('Your own plan: minimum a month (EGP)') }}</label>
                <input class="field" id="r-min" name="rules[custom_minimum]" type="number" step="1" min="0" value="{{ old('rules.custom_minimum', $rules['custom_minimum']) }}" required>
                <p class="mt-1 text-xs text-navy-500">{{ __('A restaurant that ticks fewer modules still pays this.') }}</p>
            </div>
            <div>
                <label class="field-label" for="r-markup">{{ __('Operations module without the Complete plan: markup %') }}</label>
                <input class="field" id="r-markup" name="rules[operations_markup_pct]" type="number" step="1" min="0" max="300" value="{{ old('rules.operations_markup_pct', $rules['operations_markup_pct']) }}" required>
                <p class="mt-1 text-xs text-navy-500">{{ __('The cashier, kitchen screen, call center and stock cost this much more on «Your own plan».') }}</p>
            </div>
            <div>
                <label class="field-label" for="r-branch">{{ __('Discount on each extra branch %') }}</label>
                <input class="field" id="r-branch" name="rules[extra_branch_off_pct]" type="number" step="1" min="0" max="100" value="{{ old('rules.extra_branch_off_pct', $rules['extra_branch_off_pct']) }}" required>
                <p class="mt-1 text-xs text-navy-500">{{ __('A module priced per branch costs this much less for every branch after the first.') }}</p>
            </div>
        </div>
    </section>

    <section class="card p-0">
        <h2 class="section-title px-4 pt-4 sm:px-5">{{ __('Plans') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-navy-500">
                    <tr class="border-b border-navy-100">
                        <th class="px-4 py-2 text-start sm:px-5">{{ __('Plan') }}</th>
                        <th class="px-2 py-2 text-start">{{ __('Monthly price (EGP)') }}</th>
                        <th class="px-4 py-2 text-start sm:px-5">{{ __('Price before discount (shown struck through)') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-navy-50">
                    @foreach ($plans as $plan)
                        <tr>
                            <td class="px-4 py-2 font-bold text-navy-900 sm:px-5">
                                {{ $plan->displayName() }}
                                @if ($plan->contact_sales)<span class="block text-xs font-normal text-navy-500">{{ __('Contact sales') }}</span>@endif
                                @if ($plan->isCustom())<span class="block text-xs font-normal text-navy-500">{{ __('Priced by the modules ticked') }}</span>@endif
                            </td>
                            <td class="px-2 py-2"><input class="field w-32" type="number" step="1" min="0" name="plans[{{ $plan->id }}][monthly_price]" value="{{ old("plans.{$plan->id}.monthly_price", $plan->monthly_price + 0) }}" aria-label="{{ __('Monthly price (EGP)') }} — {{ $plan->displayName() }}" required></td>
                            <td class="px-4 py-2 sm:px-5"><input class="field w-32" type="number" step="1" min="0" name="plans[{{ $plan->id }}][original_price]" value="{{ old("plans.{$plan->id}.original_price", $plan->original_price !== null ? $plan->original_price + 0 : '') }}" aria-label="{{ __('Price before discount (shown struck through)') }} — {{ $plan->displayName() }}"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="px-4 pb-4 pt-2 text-xs text-navy-500 sm:px-5">{{ __('Features, limits and the 6 / 12 month discounts are on each plan\'s page.') }}</p>
    </section>

    @foreach ($groups as $type => $title)
        @php($rows = $addons->filter(fn ($a) => $typeOf($a) === $type))
        @continue($rows->isEmpty())
        <section class="card p-0">
            <h2 class="section-title px-4 pt-4 sm:px-5">{{ $title }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-navy-500">
                        <tr class="border-b border-navy-100">
                            <th class="px-4 py-2 text-start sm:px-5">{{ __('Name') }}</th>
                            <th class="px-2 py-2 text-start">{{ $type === 'credits' ? __('Price (EGP, once)') : __('Monthly price (EGP)') }}</th>
                            <th class="px-4 py-2 text-start sm:px-5">{{ __('On sale') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-navy-50">
                        @foreach ($rows as $a)
                            <tr>
                                <td class="px-4 py-2 sm:px-5">
                                    <a href="{{ route('admin.addons.edit', $a) }}" class="font-bold text-navy-900 hover:text-brand-700">{{ $a->displayName() }}</a>
                                    @if ($a->per_branch)<span class="block text-xs text-navy-500">{{ __('Per branch') }}</span>@endif
                                </td>
                                <td class="px-2 py-2"><input class="field w-32" type="number" step="1" min="0" name="addons[{{ $a->id }}][monthly_price]" value="{{ old("addons.{$a->id}.monthly_price", $a->monthly_price + 0) }}" aria-label="{{ __('Price') }} — {{ $a->displayName() }}" required></td>
                                <td class="px-4 py-2 sm:px-5"><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="addons[{{ $a->id }}][is_active]" value="1" @checked(old("addons.{$a->id}.is_active", $a->is_active)) class="size-5 accent-brand-600"> <span class="sr-only">{{ __('On sale') }} — {{ $a->displayName() }}</span></label></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-t border-navy-100 bg-white px-4 py-3 sm:mx-0 sm:rounded-2xl sm:border">
        <button class="btn-primary">{{ __('Save prices') }}</button>
        <span class="text-xs text-navy-500">{{ __('The pricing page and the plan builder update right away.') }}</span>
    </div>
</form>
@endsection
