{{--
    The three plans, from the database: Free, Complete (featured) and Enterprise (contact sales, no price). The
    monthly / yearly switch changes Complete's price and the extra branch line (Plan::priceFor, cycle_discounts).
    Needs $plans, $matrix and $addons (App\Support\SiteModules::pricing()). Phones show each card's key lines;
    "What is included" opens the rest (always open on wide screens).
--}}
@php
    $free = $plans->first(fn ($p) => ! $p->isContactSales() && (float) $p->monthly_price === 0.0);
    $paid = $plans->reject(fn ($p) => $p->isContactSales() || (float) $p->monthly_price === 0.0)->values();
    $complete = $paid->firstWhere('is_featured', true) ?? $paid->first();
    $enterprise = $plans->first(fn ($p) => $p->isContactSales());
    $yearPct = (float) ($complete?->cycle_discounts['12'] ?? 0);
    $trial = \App\Services\TenantProvisioner::TRIAL_DAYS;
    $branchAddon = $addons->firstWhere('key', 'branches');
    $staffAddon = $addons->firstWhere('key', 'staff');
    $offer = $complete && $complete->code === \App\Billing\LaunchOffer::PLAN && \App\Billing\LaunchOffer::isRunning();
    $maxBranches = \App\Models\AddonPrice::MAX_SELF_SERVE_EXTRA_BRANCHES + 1;
    // "pay online ..., the yearly plan in installments with valU ..." only when that is live (App\Billing\OnlinePayments)
    $installmentsPromise = app(\App\Billing\OnlinePayments::class)->installmentsPromise();
    $chevron = '<svg class="lp-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>';
    $check = '<svg class="mt-1 size-4 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0z"/></svg>';

    // the modules Complete switches on, by group, as the marketing site names them
    $completeModules = [];
    if ($complete) {
        foreach (\App\Support\SiteModules::grouped() as $group => $mods) {
            foreach ($mods as $m) {
                if ($m['feature'] === null || ! empty($matrix[$complete->id]['features'][$m['feature']])) {
                    $completeModules[$group][] = $m['name'];
                }
            }
        }
    }
    $groupNames = \App\Support\SiteModules::groups();
@endphp

@if ($complete)
    <div class="flex flex-col items-center justify-between gap-4 text-center sm:flex-row sm:items-end sm:text-start">
        <div>
            <h2 class="text-2xl font-black">{{ __('Three plans, no surprises') }}</h2>
            <p class="mt-1 text-navy-500">{{ __('No setup fees, no commission. Cancel whenever you want.') }}</p>
        </div>
        <div class="inline-flex rounded-full border border-navy-200 bg-white p-1" role="group" aria-label="{{ __('Billing period') }}">
            <button type="button" data-cycle="1" aria-pressed="true" class="flex min-h-11 items-center gap-1.5 rounded-full px-4 text-sm font-bold text-navy-600 transition duration-300 aria-pressed:bg-navy-800 aria-pressed:text-white">{{ __('Monthly') }}</button>
            <button type="button" data-cycle="12" aria-pressed="false" class="flex min-h-11 items-center gap-1.5 rounded-full px-4 text-sm font-bold text-navy-600 transition duration-300 aria-pressed:bg-navy-800 aria-pressed:text-white">
                {{ __('Yearly') }}
                @if ($yearPct > 0)<span class="rounded-full bg-emerald-100 px-1.5 text-xs text-emerald-800" dir="ltr">−{{ money($yearPct) }}%</span>@endif
            </button>
        </div>
    </div>
@endif

<div class="mt-6 grid items-start gap-4 lg:grid-cols-3" data-plans-lineup>
    {{-- Free --}}
    @if ($free)
        @php
            $fe = $matrix[$free->id];
            $freeLines = [__('Your menu online with QR codes'), __('Arabic and English')];
            foreach (array_filter($fe['limits'], fn ($v) => $v !== 0) as $key => $v) {
                $freeLines[] = \App\Models\Plan::label($key) . ': ' . ($v === null ? __('Unlimited') : $v);
            }
            foreach (array_keys(array_filter($fe['features'])) as $key) {
                $freeLines[] = \App\Models\Plan::label($key);
            }
        @endphp
        <div class="lp-lift flex flex-col rounded-[24px] border-2 border-emerald-600 bg-white p-5 sm:p-6" data-plan="{{ $free->code }}" data-reveal>
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-2xl font-black">{{ $free->displayName() }}</h3>
                <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-800">{{ __('Free forever, no card') }}</span>
            </div>
            <p class="mt-1 text-sm text-navy-500 lg:min-h-10">{{ tr($free->tagline, $free->tagline_en) }}</p>
            <p class="mt-4 flex items-end gap-1.5"><span class="text-4xl font-black tracking-tight">0</span><span class="mb-1 text-sm font-semibold text-navy-500">{{ __('EGP / month') }}</span></p>
            <p class="mt-2 text-xs text-navy-500 lg:min-h-10">{{ __('For as long as you want') }}</p>
            <ul class="mt-3 space-y-2 border-t border-navy-100 pt-4 text-sm">
                @foreach ($freeLines as $line)
                    <li class="flex items-start gap-2">{!! $check !!}<span>{{ $line }}</span></li>
                @endforeach
                @if (empty($fe['features']['hide_badge']))
                    <li class="flex items-start gap-2 text-navy-500"><x-landing.icon name="flag" class="mt-1 size-4 shrink-0" /><span>{{ __('A small egmenu badge on the menu') }}</span></li>
                @endif
            </ul>
            <a href="{{ route('register') }}" class="btn mt-6 min-h-12 w-full rounded-full bg-emerald-600 text-white hover:bg-emerald-700">{{ __('Start free') }}</a>
        </div>
    @endif

    {{-- Complete --}}
    @if ($complete)
        @php
            $ce = $matrix[$complete->id];
            $users = $ce['limits']['staff'];
            $branches = $ce['limits']['branches'];
        @endphp
        <div class="lp-lift relative flex flex-col rounded-[24px] border border-navy-800 bg-white p-5 ring-2 ring-navy-800 sm:p-6"
             data-plan="{{ $complete->code }}" data-plan-price data-show-total data-monthly="{{ (float) $complete->monthly_price }}" data-discounts="{{ json_encode((object) ($complete->cycle_discounts ?? [])) }}" data-reveal style="--d: 70ms">
            <span class="badge absolute -top-3 start-6 bg-navy-800 px-3 py-1 text-white">{{ __('Most popular') }}</span>
            <h3 class="text-2xl font-black">{{ $complete->displayName() }}</h3>
            <p class="mt-1 text-sm text-navy-500 lg:min-h-10">{{ tr($complete->tagline, $complete->tagline_en) }}</p>
            <div class="mt-4 flex items-end gap-1.5">
                @if ($complete->showsLaunchDiscount())<span class="mb-1 text-navy-400 line-through" data-orig data-orig-month="{{ (float) $complete->original_price }}">{{ money($complete->original_price) }}</span>@endif
                <span class="text-4xl font-black tracking-tight" data-price>{{ money($complete->monthly_price) }}</span>
                <span class="mb-1 text-sm font-semibold text-navy-500" data-unit data-unit-month="{{ __('EGP / month') }}" data-unit-cycle="{{ __('EGP / year') }}">{{ __('EGP / month') }}</span>
            </div>
            @if ($complete->showsLaunchDiscount())
                {{-- launch discount: the regular price is the crossed-out one; the share is recomputed for each payment period --}}
                <span class="mt-2 inline-flex w-fit rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-800" data-orig-pct data-label="{{ __(':pct% off for the launch period') }}">{{ __(':pct% off for the launch period', ['pct' => (int) round((1 - $complete->monthly_price / $complete->original_price) * 100)]) }}</span>
                @if ($offerEnds = \App\Models\Plan::launchOfferEndsAt())
                    <span class="mt-1 block text-xs font-bold text-navy-600" data-offer-ends>{{ __('Offer valid until :date', ['date' => $offerEnds->format('d/m/Y')]) }}</span>
                @endif
            @endif
            <p class="mt-2 text-xs text-navy-500 lg:min-h-10" data-billed>{{ __('Billed monthly') }}</p>
            <p class="text-xs text-navy-500" data-monthly-only data-year-price="{{ money($complete->priceFor(12)) }}">{{ __('Or :total EGP for a whole year paid at once.', ['total' => money($complete->priceFor(12))]) }}</p>
            @if ($installmentsPromise)
                <p class="mt-1 text-xs font-bold text-emerald-800" data-installments-promise>{{ $installmentsPromise }}</p>
            @endif

            <ul class="mt-3 space-y-2 border-t border-navy-100 pt-4 text-sm">
                <li class="flex items-start gap-2 font-bold">{!! $check !!}<span>{{ $branches === 1 ? __('Includes one branch and :users users', ['users' => $users ?? '∞']) : __('Includes :branches branches and :users users', ['branches' => $branches ?? '∞', 'users' => $users ?? '∞']) }}</span></li>
                @if ($branchAddon)
                    <li class="flex items-start gap-2">{!! $check !!}<span data-cycle-text data-monthly="{{ (float) $branchAddon->monthly_price }}" data-discounts="{{ json_encode((object) ($complete->cycle_discounts ?? [])) }}"
                        data-t1="{{ __('Each extra branch: :total EGP / month', ['total' => ':total']) }}" data-tn="{{ __('Each extra branch: :total EGP / year', ['total' => ':total']) }}">{{ __('Each extra branch: :total EGP / month', ['total' => money($branchAddon->monthly_price)]) }}</span></li>
                @endif
                @if ($staffAddon)
                    <li class="flex items-start gap-2">{!! $check !!}<span>{{ __('More users: :count for :price EGP / month', ['count' => max(1, (int) $staffAddon->credits), 'price' => money($staffAddon->monthly_price)]) }}</span></li>
                @endif
                <li class="flex items-start gap-2">{!! $check !!}<span>{{ __('Unlimited items and tables') }}</span></li>
            </ul>
            @if ($offer)
                <p class="mt-4 rounded-2xl bg-emerald-50 px-3 py-2.5 text-sm font-bold text-emerald-800" data-launch-offer><x-landing.icon name="gift" class="me-1 inline size-4 align-[-3px]" />{{ __('For any new Complete subscription, the second branch is free for the first 12 months.') }}</p>
            @endif

            <details class="lp-acc mt-3 text-sm" data-open-wide>
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between border-t border-navy-100 font-bold text-navy-800 lg:hidden">{{ __('What is included') }}{!! $chevron !!}</summary>
                <div class="space-y-3 pt-3" data-complete-modules>
                    @foreach ($completeModules as $group => $names)
                        <div>
                            <div class="text-xs font-extrabold text-navy-500">{{ $groupNames[$group][0] ?? $group }}</div>
                            <p class="mt-0.5 text-navy-800">{{ implode(' · ', $names) }}</p>
                        </div>
                    @endforeach
                </div>
            </details>
            <a href="{{ route('register') }}" class="btn-navy mt-6 min-h-12 w-full rounded-full">{{ __('Try it free for :days days', ['days' => $trial]) }}</a>
            @if ($enterprise)
                <a href="{{ $enterprise->contactUrl() }}" target="_blank" rel="noopener" class="mt-3 text-center text-xs font-bold text-navy-600 hover:text-brand-700" data-branch-cap>{{ __('More than :count branches? Contact us', ['count' => $maxBranches]) }}</a>
            @endif
        </div>
    @endif

    {{-- Enterprise: no public price --}}
    @if ($enterprise)
        <div class="lp-lift flex flex-col rounded-[24px] border border-navy-100 bg-[var(--lp-paper)] p-5 sm:p-6" data-plan="{{ $enterprise->code }}" data-contact-sales data-reveal style="--d: 140ms">
            <h3 class="text-2xl font-black">{{ $enterprise->displayName() }}</h3>
            <p class="mt-1 text-sm text-navy-500 lg:min-h-10">{{ tr($enterprise->tagline, $enterprise->tagline_en) }}</p>
            <p class="mt-4 text-3xl font-black tracking-tight">{{ __('By agreement') }}</p>
            <p class="mt-2 text-xs text-navy-500 lg:min-h-10">{{ __('Priced for your branches and screens') }}</p>
            <p class="mt-3 border-t border-navy-100 pt-4 text-sm font-bold text-navy-900">{{ __('Everything in Complete, plus:') }}</p>
            <div class="mt-2">@include('partials.enterprise-includes')</div>
            <a href="{{ $enterprise->contactUrl() }}" target="_blank" rel="noopener" class="btn-ghost mt-6 min-h-12 w-full rounded-full" data-contact-sales-link>{{ __('Contact us') }}</a>
        </div>
    @endif
</div>
