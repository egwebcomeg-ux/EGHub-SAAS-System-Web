{{--
    «صمم باقتك»: tick modules, see the plan's price (App\Billing\CustomPlan). The same builder on the pricing page
    ($mode 'public': the button starts a free trial carrying the pick) and on the billing page ($mode 'dashboard': it
    subscribes, or changes the modules of a plan already built). Each tick asks $endpoint for the new total, which
    comes back rendered (partials.custom-plan-summary) with the modules it brought in ticked.
    Needs $catalog, $quote, $selected (slugs), $endpoint, $mode; dashboard: $current (on this plan, paid now).
--}}
@php
    $groups = \App\Support\SiteModules::groups();
    $byGroup = collect($catalog)->groupBy(fn (array $row) => $row['module']['group']);
    $markupPct = (int) round(\App\Billing\CustomPlan::OPERATIONS_MARKUP * 100);
    $dashboard = $mode === 'dashboard';
@endphp
<div class="grid gap-6 lg:grid-cols-3" data-custom-plan data-endpoint="{{ $endpoint }}">
    {{-- the pricing page is cached, so its builder never posts (no CSRF token): its button is a link --}}
    <form @if ($dashboard) method="post" action="{{ route('dash.billing.custom') }}" @else onsubmit="return false" @endif id="custom-plan-form" data-custom-form class="space-y-5 lg:col-span-2">
        @if ($dashboard)@csrf @endif
        {{-- on a phone the total sits below the long list: keep it in sight while ticking --}}
        <a href="#custom-plan-summary" class="sticky top-20 z-10 flex items-center justify-between rounded-xl border border-brand-200 bg-white/95 px-4 py-2 text-sm shadow-sm backdrop-blur lg:hidden" data-custom-mobile-total>
            <span class="font-bold text-navy-800">{{ __('Your plan') }}: <bdi dir="ltr" class="font-extrabold text-navy-900" data-custom-mobile-amount>{{ money($quote['monthly']) }}</bdi> {{ __('EGP / month') }}</span>
            <span class="text-brand-700">{{ __('Details') }} ↓</span>
        </a>
        @foreach ($groups as $group => [$groupLabel])
            @continue(! $byGroup->has($group))
            <fieldset>
                <legend class="mb-2 font-extrabold">{{ $groupLabel }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($byGroup[$group] as $row)
                        @php
                            $module = $row['module'];
                            $sold = $row['packs'] !== null;
                        @endphp
                        <label @class(['flex min-h-12 items-start gap-3 rounded-xl border p-3 text-sm', 'border-navy-100 bg-white has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50' => ! $row['included'] && $sold, 'border-dashed border-navy-200 bg-navy-50 text-navy-500' => $row['included'] || ! $sold]) data-custom-module="{{ $module['slug'] }}">
                            @if ($row['included'])
                                <input type="checkbox" checked disabled class="mt-1">
                            @elseif ($sold)
                                <input type="checkbox" name="modules[]" value="{{ $module['slug'] }}" class="mt-1" @checked(in_array($module['slug'], $selected, true))>
                            @else
                                <input type="checkbox" disabled class="mt-1">
                            @endif
                            <span class="min-w-0 flex-1">
                                <span class="block font-bold text-navy-900">{{ $module['name'] }}</span>
                                @if ($row['included'])
                                    <span class="block text-xs">{{ __('Always in your plan') }}</span>
                                @elseif (! $sold)
                                    <span class="block text-xs">{{ __('In the Complete plan only') }}</span>
                                @else
                                    <span class="block text-xs text-navy-500"><bdi dir="ltr">+{{ money($row['price']) }}</bdi> {{ __('EGP / month') }}@if ($row['markup']) · {{ __(':pct% more on a custom plan', ['pct' => $markupPct]) }}@endif</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
        @if ($dashboard)
            @error('custom')<p class="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
        @endif
    </form>

    <aside class="scroll-mt-24 lg:sticky lg:top-4 lg:self-start" id="custom-plan-summary">
        <div class="rounded-[24px] border border-navy-100 bg-white p-5 shadow-sm">
            <div data-custom-summary-box>@include('partials.custom-plan-summary', ['quote' => $quote, 'mode' => $mode])</div>
            @if ($dashboard)
                <div class="mt-4 grid grid-cols-3 gap-2" role="radiogroup" aria-label="{{ __('Pay for') }}">
                    @foreach (\App\Models\Plan::CYCLES as $m)
                        <label class="cursor-pointer rounded-xl border border-navy-100 p-2 text-center text-xs font-bold has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="months" value="{{ $m }}" form="custom-plan-form" class="sr-only" @checked($m === 1)>{{ cycle_label($m) }}
                        </label>
                    @endforeach
                </div>
                <button form="custom-plan-form" class="btn-primary mt-3 w-full" data-custom-submit>{{ $current ? __('Save my modules') : __('Subscribe to my plan') }}</button>
                @if ($current)
                    <p class="mt-2 text-xs text-navy-500">{{ __('A module you add is billed until your period ends; one you remove stops now, and your next renewal is priced without it.') }}</p>
                @endif
            @else
                <a href="{{ route('register', array_filter(['modules' => implode(',', $quote['slugs'])])) }}" class="btn-primary mt-4 inline-flex min-h-12 w-full items-center justify-center rounded-full" data-custom-register>{{ __('Try it free for :days days', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</a>
                <p class="mt-2 text-center text-xs text-navy-500">{{ __('The trial has everything; you choose your modules when you subscribe.') }}</p>
            @endif
        </div>
    </aside>
</div>

<script>
/* «صمم باقتك»: every tick asks the server for the new total (rendered) and ticks the modules it brought in. */
(function () {
    'use strict';
    var root = document.querySelector('[data-custom-plan]');
    if (!root) return;
    var form = root.querySelector('[data-custom-form]');
    var box = root.querySelector('[data-custom-summary-box]');
    var cta = root.querySelector('[data-custom-register]');
    var amount = root.querySelector('[data-custom-mobile-amount]');
    var timer = null;
    var seq = 0;
    var picked = function () {
        return Array.prototype.map.call(form.querySelectorAll('input[name="modules[]"]:checked'), function (i) { return i.value; });
    };
    var refresh = function () {
        var mine = ++seq;
        var query = picked().map(function (s) { return 'modules[]=' + encodeURIComponent(s); }).join('&');
        fetch(root.getAttribute('data-endpoint') + '?' + query, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || mine !== seq) return;
                box.innerHTML = d.html;
                if (amount && d.monthly != null) amount.textContent = Number(d.monthly).toLocaleString('en-US', { maximumFractionDigits: 2 });
                (d.slugs || []).forEach(function (s) {
                    var input = form.querySelector('input[name="modules[]"][value="' + s + '"]');
                    if (input) input.checked = true;
                });
                if (cta && d.register) cta.setAttribute('href', d.register);
            })
            .catch(function () {});
    };
    form.addEventListener('change', function (e) {
        if (e.target && e.target.name !== 'modules[]') return;
        clearTimeout(timer);
        timer = setTimeout(refresh, 150);
    });
})();
</script>
