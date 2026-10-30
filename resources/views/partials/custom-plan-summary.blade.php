{{--
    The live total of «صمم باقتك» (App\Billing\CustomPlan::quote): the monthly price, the minimum when the modules come
    to less, what each cycle costs, the modules added because another needs them, and the Complete plan when it costs
    about the same. Rendered on the page and again by the JSON endpoint on every tick. Needs $quote and $mode
    ('public' | 'dashboard').
--}}
@php
    $names = fn (string $slug) => \App\Support\SiteModules::find($slug)['name'] ?? $slug;
    $complete = $quote['complete'];
    $diff = $complete ? round($quote['monthly'] - $complete['total']) : 0;
@endphp
<div data-custom-summary data-custom-monthly="{{ $quote['monthly'] }}">
    <div class="text-sm font-semibold text-navy-500">{{ __('Your plan') }}</div>
    <div class="mt-1"><b class="text-3xl font-black"><bdi dir="ltr">{{ money($quote['monthly']) }}</bdi></b> <span class="text-sm text-navy-500">{{ __('EGP / month') }}</span></div>
    @if ($quote['packs']->isEmpty())
        <p class="mt-2 text-sm text-navy-600" data-custom-empty>{{ __('Tick the modules you work with: the menu is always in.') }}</p>
    @endif
    @if ($quote['topUp'] > 0)
        <p class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900" data-custom-minimum>
            {{ __('A plan of your own costs at least :min EGP a month; your modules come to :sum EGP.', ['min' => money(\App\Billing\PricingRules::customMinimum()), 'sum' => money($quote['modules'])]) }}
        </p>
    @endif

    <ul class="mt-3 space-y-1 text-sm" data-custom-cycles>
        @foreach ($quote['cycles'] as $months => $total)
            @php($pct = (float) ($quote['discounts'][(string) $months] ?? 0))
            <li class="flex items-center justify-between gap-3">
                <span>{{ cycle_label($months) }}</span>
                <span><b><bdi dir="ltr">{{ money($total) }}</bdi></b> {{ __('EGP') }}@if ($pct > 0) <span class="text-xs font-bold text-emerald-700">-{{ money($pct) }}%</span>@endif</span>
            </li>
        @endforeach
    </ul>

    @foreach ($quote['added'] as $added => $because)
        <p class="mt-2 text-xs font-semibold text-navy-600" data-custom-added="{{ $added }}">{{ __(':module was added: :needs needs it.', ['module' => $names($added), 'needs' => $names($because)]) }}</p>
    @endforeach

    @if ($quote['nudge'])
        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-emerald-900" data-custom-nudge>
            <p class="font-extrabold">{{ $diff >= 0
                ? __('The Complete plan saves you :amount EGP a month, and it has everything.', ['amount' => money($diff)])
                : __('For :amount EGP more a month, the Complete plan has everything.', ['amount' => money(-$diff)]) }}</p>
            @if ($complete['addons']->isNotEmpty())
                <p class="mt-1 text-xs">{{ __('With :addons on top, for what you picked.', ['addons' => $complete['addons']->map->displayName()->join('، ')]) }}</p>
            @endif
            @if ($mode === 'dashboard')
                <form method="post" action="{{ route('dash.billing.checkout') }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="plan_id" value="{{ $complete['plan']->id }}">
                    <input type="hidden" name="months" value="1">
                    <button class="btn-primary min-h-10 w-full" data-custom-pick-complete>{{ __('Choose :plan', ['plan' => $complete['plan']->displayName()]) }}</button>
                </form>
            @else
                <a href="{{ route('register') }}" class="btn-primary mt-2 inline-flex min-h-10 w-full items-center justify-center rounded-full" data-custom-pick-complete>{{ __('Start free with :plan', ['plan' => $complete['plan']->displayName()]) }}</a>
            @endif
        </div>
    @endif
</div>
