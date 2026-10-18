@extends('layouts.landing')
@section('title', __('Pricing') . ' · EgMenu')
@section('description', __('EgMenu prices in Egyptian pounds: a free plan for your QR menu, paid plans for ordering and loyalty, add-ons for POS, kiosk, kitchen, call center and inventory, and WhatsApp credits paid once.'))

@php
    $groups = collect(\App\Models\Plan::GROUPS)->except('operations');
    $planByCode = $plans->keyBy('code');
    $extraBranchOff = (int) round((1 - \App\Models\AddonPrice::EXTRA_BRANCH_SHARE) * 100);
    $limitPacks = $addons->where('type', 'limit')->values();
    // add-ons in three groups: packs that unlock an operations feature, the other feature packs, one-off credits
    $opsFeatures = \App\Models\Plan::addonFeatures();
    $isOps = fn ($a) => array_intersect($a->features ?? [], $opsFeatures) !== [];
    $addonGroups = [
        'operations' => [__('Operations'), __('The cashier, the kiosk, kitchen screens, the call center and stock. Priced per branch.'), $addons->filter(fn ($a) => $a->isFeaturePack() && $isOps($a))->values()],
        'growth' => [__('Modules on their own'), __('Every module can be bought by itself, on top of any plan — the free one included. Ordering only, the loyalty card only, offers only.'), $addons->filter(fn ($a) => $a->isFeaturePack() && !$isOps($a))->values()],
        'credits' => [__('Credits'), __('WhatsApp messages for order updates and campaigns. One message is one credit.'), $addons->filter(fn ($a) => $a->isCreditPack())->values()],
    ];
    // the first module page that sells each add-on
    $moduleFor = [];
    foreach (\App\Support\SiteModules::all() as $m) {
        foreach ($m['addons'] as $key) {
            $moduleFor[$key] ??= $m['slug'];
        }
    }
@endphp

@section('page')
<section data-hero class="lp-hero lp-glow">
    <div class="mx-auto max-w-3xl px-4 pt-10 pb-12 text-center sm:px-6 lg:pt-14 lg:pb-16">
        <p class="lp-eyebrow" data-reveal>{{ __('Pricing') }}</p>
        <h1 class="mt-4 text-[2rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.25]" data-reveal style="--d: 70ms">{{ __('Start free. Pay only when you need more.') }}</h1>
        <p class="lp-lead mx-auto mt-4 max-w-2xl" data-reveal style="--d: 140ms">{{ __('Prices in Egyptian pounds, paid by InstaPay or Vodafone Cash. No commission on your orders and no contract.') }}</p>
    </div>
</section>

<section class="py-12 lg:py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        @include('landing.partials.plans')
    </div>
</section>

{{-- full comparison --}}
<section id="compare" class="py-12 lg:py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        {{-- the full table is one tap away on every screen; landing.js opens it when the link points at #compare --}}
        <details class="lp-acc group" data-compare>
            <summary class="mx-auto flex min-h-16 max-w-3xl cursor-pointer list-none items-center justify-between gap-3 rounded-[24px] border border-navy-100 bg-[var(--lp-paper)] px-6 transition-colors duration-300 hover:border-navy-200">
                <span class="min-w-0">
                    <h2 class="text-lg font-black text-navy-900 sm:text-xl">{{ __('Compare the plans') }}</h2>
                    <span class="block text-sm text-navy-500">{{ __('Everything each plan includes, side by side.') }}</span>
                </span>
                <svg class="lp-chev size-6 shrink-0 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>
            </summary>
        <p class="mt-4 text-center text-sm text-navy-500 sm:hidden">{{ __('Swipe the table to see every plan.') }}</p>
        <div class="relative mt-6 overflow-x-auto rounded-2xl border border-navy-200 bg-white">
            <table class="lp-compare w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-navy-200">
                        <th class="w-40 p-3 text-start text-xs font-bold text-navy-500 sm:w-[30%] sm:p-4">{{ __('Plan') }}</th>
                        @foreach ($plans as $p)
                            <th @class(['p-3 text-center sm:p-4', 'bg-emerald-50' => (float) $p->monthly_price === 0.0, 'bg-navy-50' => $p->is_featured])>
                                <div class="font-black text-navy-900">{{ $p->displayName() }}</div>
                                <div class="text-xs font-semibold whitespace-nowrap text-navy-500">{{ (float) $p->monthly_price === 0.0 ? __('Free') : money($p->monthly_price) . ' ' . __('EGP') }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr class="grp"><td colspan="{{ $plans->count() + 1 }}" class="bg-navy-50 px-4 py-2 text-xs font-extrabold text-navy-700">{{ __('Limits') }}</td></tr>
                    @foreach (\App\Models\Plan::LIMITS as $lk => $_)
                        <tr class="border-t border-navy-50">
                            <td class="px-4 py-2.5 text-navy-700">{{ \App\Models\Plan::label($lk) }}</td>
                            @foreach ($plans as $p)
                                @php
                                    $v = $matrix[$p->id]['limits'][$lk];
                                @endphp
                                <td class="px-4 py-2.5 text-center font-bold">{{ $v === null ? __('Unlimited') : ($v === 0 ? '—' : $v) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    @foreach ($groups as $g => $gl)
                        @php
                            $keys = array_keys(array_filter(\App\Models\Plan::FEATURES, fn ($f) => $f[2] === $g));
                        @endphp
                        @continue(empty($keys))
                        <tr class="grp"><td colspan="{{ $plans->count() + 1 }}" class="bg-navy-50 px-4 py-2 text-xs font-extrabold text-navy-700">{{ app()->getLocale() === 'en' ? $gl[1] : $gl[0] }}</td></tr>
                        @foreach ($keys as $fk)
                            <tr class="border-t border-navy-50">
                                <td class="px-4 py-2.5 text-navy-700">{{ \App\Models\Plan::label($fk) }}</td>
                                @foreach ($plans as $p)
                                    <td class="px-4 py-2.5 text-center">
                                        @if (!empty($matrix[$p->id]['features'][$fk]))
                                            <span class="inline-grid size-6 place-items-center rounded-full bg-emerald-100 text-emerald-700"><x-landing.icon name="check" class="size-4" /></span><span class="sr-only">{{ __('Included') }}</span>
                                        @else
                                            <span class="text-navy-300" aria-hidden="true">—</span><span class="sr-only">{{ __('Not included') }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        </details>
    </div>
</section>

{{-- what it saves, from the visitor's own numbers --}}
<section id="calculator" class="lp-band py-16 lg:py-24">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <div class="mx-auto max-w-2xl text-center" data-reveal>
            <p class="lp-eyebrow">{{ __('Savings calculator') }}</p>
            <h2 class="lp-h2 mt-3">{{ __('Work out what EgMenu saves you') }}</h2>
            <p class="lp-lead mt-3">{{ __('Put in your own numbers: what printing menus costs you and what the delivery apps take. The total updates as you type.') }}</p>
        </div>
        <div class="mt-10">@include('landing.partials.savings-calculator')</div>
    </div>
</section>

{{-- add-ons --}}
@if ($addons->isNotEmpty())
    <section id="addons" class="py-20 lg:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="mx-auto max-w-2xl text-center" data-reveal>
                <h2 class="lp-h2">{{ __('Add-ons') }}</h2>
                <p class="lp-lead mt-3">{{ __('Start with the menu. When you grow, add only what you need, on top of your plan.') }}</p>
            </div>
            @foreach ($addonGroups as $groupKey => [$groupLabel, $groupLead, $packs])
                @continue($packs->isEmpty() && !($groupKey === 'operations' && $limitPacks->isNotEmpty()))
                <div id="addons-{{ $groupKey }}" class="mt-12 border-t border-navy-100 pt-8">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-6">
                        <h3 class="flex items-center gap-2 text-xl font-black">
                            {{ $groupLabel }}
                            @if ($groupKey === 'credits')<span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-sm font-bold text-emerald-800">{{ __('Paid once') }}</span>@endif
                        </h3>
                        <p class="text-sm text-navy-500">{{ $groupLead }}</p>
                    </div>
                    <div id="addons-{{ $groupKey }}-list" data-more class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($packs as $addon)
                            @php
                                $bundle = $addon->key === 'ops_bundle';
                                $credits = $addon->isCreditPack();
                                $minPlan = $addon->min_plan ? $planByCode->get($addon->min_plan) : null;
                                $slug = $moduleFor[$addon->key] ?? null;
                            @endphp
                            <div @class(['lp-lift flex flex-col rounded-[24px] border p-5', 'border-navy-800 bg-navy-800 text-white' => $bundle, 'border-navy-100 bg-white' => !$bundle]) @if ($loop->index >= 3) data-more-item @endif>
                                <div class="flex items-start justify-between gap-3">
                                    <h4 class="text-lg font-extrabold">{{ $addon->displayName() }}</h4>
                                    @if ($bundle)<span class="badge shrink-0 bg-brand-600 text-white">{{ __('Best value') }}</span>@endif
                                </div>
                                <p @class(['mt-1 flex-1 text-sm', 'text-white/70' => $bundle, 'text-navy-500' => !$bundle])>{{ tr($addon->description, $addon->description_en) }}</p>
                                @if ($credits)
                                    <p class="mt-4"><b class="text-2xl font-black"><bdi dir="ltr">{{ money($addon->monthly_price) }}</bdi></b> <span class="text-sm text-navy-500">{{ __('EGP') }} · {{ __('Paid once') }}</span></p>
                                @else
                                    <p class="mt-4"><b class="text-2xl font-black"><bdi dir="ltr">+{{ money($addon->monthly_price) }}</bdi></b> <span @class(['text-sm', 'text-white/70' => $bundle, 'text-navy-500' => !$bundle])>{{ __('EGP / month') }}</span></p>
                                @endif
                                <div class="mt-3 flex flex-wrap gap-1.5 text-xs font-bold">
                                    @if ($addon->per_branch)<span @class(['rounded-full px-2.5 py-1', 'bg-white/10' => $bundle, 'bg-navy-50 text-navy-700' => !$bundle])>{{ __('Per branch, :pct% off each extra branch', ['pct' => $extraBranchOff]) }}</span>@endif
                                    @if ($minPlan)<span @class(['rounded-full px-2.5 py-1', 'bg-white/10' => $bundle, 'bg-brand-50 text-brand-700' => !$bundle])>{{ __('With :plan or higher', ['plan' => $minPlan->displayName()]) }}</span>@endif
                                    @if ($credits)<span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">{{ __('Never expires') }}</span>@endif
                                </div>
                                @if ($slug && !$bundle)
                                    <a href="{{ route('features.show', $slug) }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-bold text-navy-600 hover:text-brand-700">{{ __('How it works') }} <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span></a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($packs->count() > 3)<x-landing.more :for="'addons-' . $groupKey . '-list'" />@endif
                    @if ($groupKey === 'operations' && $limitPacks->isNotEmpty())
                        <div class="mt-4 flex flex-wrap gap-3">
                            @foreach ($limitPacks as $addon)
                                <div class="flex min-h-12 items-center gap-3 rounded-xl border border-navy-200 bg-white px-4 text-sm">
                                    <b>{{ $addon->displayName() }}</b><span class="text-navy-500"><bdi dir="ltr">+{{ money($addon->monthly_price) }}</bdi> {{ __('EGP / month') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif

<section class="lp-band lp-glow mb-8 py-16 lg:py-20">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6" data-reveal>
        <h2 class="lp-h2">{{ __('Questions about paying?') }}</h2>
        <p class="lp-lead mt-3">{{ __('You pay from your dashboard by InstaPay or Vodafone Cash. Paying for 6 or 12 months gets a discount. There is no contract and you can stop whenever you want; your menu then stays on the free plan.') }}</p>
        <a href="{{ route('register') }}" class="btn-primary mt-6 min-h-12 rounded-full px-7 text-base">{{ __('Start free') }}</a>
    </div>
</section>
@endsection
