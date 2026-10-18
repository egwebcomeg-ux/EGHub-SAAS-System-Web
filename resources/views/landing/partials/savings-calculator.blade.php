{{--
    Savings calculator on /pricing. Every figure comes from what the visitor types; the plan prices and the
    online-ordering add-on come from the database (public plans + AddonPrice online_pack), read the way
    App\Support\SiteModules::pricing() reads them. Needs $plans, $addons and $matrix from that method.
--}}
@php
    $calcOnlinePack = $addons->firstWhere('key', 'online_pack');
    $calcPlans = $plans->map(fn ($p) => [
        'code' => $p->code,
        'monthly' => (float) $p->monthly_price,
        'online' => (bool) ($matrix[$p->id]['features']['online_ordering'] ?? false),
    ])->values();
    $calcDefault = ($plans->firstWhere('is_featured', true) ?? $plans->last())?->code;
    $calcL10n = [
        'needsOnline' => __('Needs online ordering on your link'),
    ];
    // [key, label, hint, min, max, step, default, unit]
    $calcInputs = [
        'menus' => [
            ['tables', __('Tables'), null, 1, 100, 1, 15, null],
            ['copies', __('Menus per table'), __('Copies you print for each table'), 1, 6, 1, 2, null],
            ['cost', __('Printing one paper menu'), null, 5, 500, 5, 60, __('EGP')],
            ['changes', __('Price changes a year'), __('Each change means printing again'), 0, 12, 1, 3, null],
        ],
        'delivery' => [
            ['sales', __('Monthly sales through delivery apps'), null, 0, 500000, 1000, 30000, __('EGP')],
            ['commission', __('The app’s commission'), null, 0, 40, 1, 25, '%'],
            ['moved', __('Orders you move to your own link'), __('Guests who order from your menu link instead'), 0, 100, 5, 30, '%'],
        ],
    ];
@endphp
<div data-savings-calculator data-plans="{{ json_encode($calcPlans) }}" data-online-pack="{{ $calcOnlinePack ? (float) $calcOnlinePack->monthly_price : '' }}"
     data-l10n="{{ json_encode($calcL10n, JSON_UNESCAPED_UNICODE) }}" class="grid gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:gap-8">
    <div class="min-w-0 space-y-5">
        @foreach (['menus' => [__('Paper menus'), 'printer'], 'delivery' => [__('Delivery apps'), 'scooter']] as $group => [$groupLabel, $groupIcon])
            <fieldset class="min-w-0 rounded-[24px] border border-navy-100 bg-white p-5 sm:p-6">
                <legend class="sr-only">{{ $groupLabel }}</legend>
                <p class="flex items-center gap-2 text-lg font-black" aria-hidden="true"><x-landing.icon :name="$groupIcon" class="size-5 text-brand-600" />{{ $groupLabel }}</p>
                <div class="mt-4 grid gap-x-6 gap-y-5 sm:grid-cols-2">
                    @foreach ($calcInputs[$group] as [$key, $label, $hint, $min, $max, $step, $default, $unit])
                        <div class="min-w-0">
                            <div class="flex items-end justify-between gap-3">
                                <label for="calc-{{ $key }}" class="text-sm font-bold text-navy-800">{{ $label }}</label>
                                <span class="flex shrink-0 items-center gap-1.5">
                                    <input id="calc-{{ $key }}" type="number" inputmode="numeric" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" value="{{ $default }}" data-calc-input="{{ $key }}" dir="ltr"
                                           @if ($hint) aria-describedby="calc-{{ $key }}-hint" @endif
                                           class="field h-10 min-h-10 w-24 px-2 text-center font-bold tabular-nums">
                                    <span class="w-7 text-xs font-bold text-navy-500">{{ $unit }}</span>
                                </span>
                            </div>
                            <input type="range" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" value="{{ $default }}" data-calc-range="{{ $key }}" aria-hidden="true" tabindex="-1"
                                   class="mt-2 block h-6 w-full cursor-pointer accent-brand-600">
                            @if ($hint)<p id="calc-{{ $key }}-hint" class="text-xs text-navy-500">{{ $hint }}</p>@endif
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <div class="rounded-[24px] border border-navy-100 bg-white p-5 sm:p-6">
            <label for="calc-plan" class="flex items-center gap-2 text-lg font-black"><x-landing.icon name="layers" class="size-5 text-brand-600" />{{ __('The plan you would take') }}</label>
            <select id="calc-plan" data-calc-plan class="field mt-3 h-12 font-bold">
                @foreach ($plans as $p)
                    <option value="{{ $p->code }}" @selected($p->code === $calcDefault)>{{ $p->displayName() }} · {{ (float) $p->monthly_price === 0.0 ? __('Free') : money($p->monthly_price) . ' ' . __('EGP / month') }}</option>
                @endforeach
            </select>
            @if ($calcOnlinePack)
                <label data-calc-addon-row hidden class="mt-3 flex min-h-11 cursor-pointer items-start gap-3 rounded-xl bg-navy-50 px-3 py-2.5 text-sm">
                    <input type="checkbox" checked data-calc-addon class="mt-0.5 size-5 shrink-0 accent-brand-600">
                    <span>{{ __('Add the online ordering add-on') }} <b class="whitespace-nowrap"><bdi dir="ltr">+{{ money($calcOnlinePack->monthly_price) }}</bdi> {{ __('EGP / month') }}</b>
                        <span class="block text-xs text-navy-500">{{ __('This plan has no online ordering of its own. Without it, guests cannot order from your link.') }}</span></span>
                </label>
            @endif
        </div>

        {{-- phones: the yearly total rides along at the bottom while the numbers are typed (aria-hidden: the full result below is the live region) --}}
        <div class="sticky bottom-3 z-20 lg:hidden" aria-hidden="true" data-calc-mini>
            <a href="#calc-result" tabindex="-1" class="flex min-h-14 items-center justify-between gap-3 rounded-2xl bg-navy-800 px-4 py-2.5 text-white shadow-[0_18px_40px_-16px_rgba(10,37,64,.7)]">
                <span class="min-w-0 text-sm font-bold"><span class="block text-xs font-semibold text-white/60">{{ __('Over one year') }}</span><span data-calc-mini-label>{{ __('What stays with you') }}</span></span>
                <span class="shrink-0"><b class="text-2xl font-black tracking-tight"><bdi dir="ltr" data-calc-mini-net>0</bdi></b> <span class="text-sm font-bold text-white/70">{{ __('EGP') }}</span></span>
            </a>
        </div>
    </div>

    <div id="calc-result" class="min-w-0 scroll-mt-24 lg:sticky lg:top-24 lg:self-start">
        <div class="rounded-[28px] bg-navy-800 p-6 text-white sm:p-7" aria-live="polite">
            <p class="text-sm font-bold text-white/70">{{ __('Over one year') }}</p>
            <dl class="mt-4 space-y-4 text-[0.95rem]">
                <div class="flex items-start justify-between gap-4 border-b border-white/10 pb-4">
                    <dt class="min-w-0">{{ __('Reprints you no longer pay for') }}<span class="block text-xs text-white/60"><bdi dir="ltr" data-calc-explain="reprint"></bdi></span></dt>
                    <dd class="shrink-0 text-lg font-black text-emerald-300"><bdi dir="ltr" data-calc-out="reprint">0</bdi></dd>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-white/10 pb-4">
                    <dt class="min-w-0">{{ __('Commission you keep') }}<span class="block text-xs text-white/60"><bdi dir="ltr" data-calc-explain="commission"></bdi></span></dt>
                    <dd class="shrink-0 text-lg font-black text-emerald-300"><bdi dir="ltr" data-calc-out="commission">0</bdi></dd>
                </div>
                <div class="flex items-start justify-between gap-4 border-b border-white/10 pb-4">
                    <dt class="min-w-0">{{ __('EgMenu for the year') }}<span class="block text-xs text-white/60"><bdi dir="ltr" data-calc-explain="plan"></bdi></span></dt>
                    <dd class="shrink-0 text-lg font-black text-brand-200"><bdi dir="ltr" data-calc-out="plan">0</bdi></dd>
                </div>
            </dl>
            <div class="mt-5 flex items-end justify-between gap-4">
                <p class="font-bold" data-calc-net-label data-positive="{{ __('What stays with you') }}" data-negative="{{ __('What it costs you more') }}">{{ __('What stays with you') }}</p>
                <p class="text-end"><b class="text-[2.1rem] leading-none font-black tracking-tight" data-calc-out-wrap><bdi dir="ltr" data-calc-out="net">0</bdi></b> <span class="text-sm font-bold text-white/70">{{ __('EGP') }}</span></p>
            </div>
            <p class="mt-5 rounded-xl bg-white/5 px-3 py-2.5 text-xs leading-relaxed text-white/70">{{ __('Worked out only from the numbers you typed. Plan prices are the monthly prices on this page.') }}</p>
            <a href="{{ route('register') }}" class="btn-primary mt-5 min-h-12 w-full rounded-full text-base">{{ __('Start free') }}</a>
        </div>
    </div>
</div>

<script>
/* Savings calculator: number field and slider stay in sync, results recalculate on every change. Nothing is sent anywhere. */
(function () {
    'use strict';
    var root = document.querySelector('[data-savings-calculator]');
    if (!root) return;
    var plans = JSON.parse(root.getAttribute('data-plans') || '[]');
    var packAttr = root.getAttribute('data-online-pack');
    var L = JSON.parse(root.getAttribute('data-l10n') || '{}');
    var pack = packAttr === '' ? null : parseFloat(packAttr);
    var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
    var planSelect = root.querySelector('[data-calc-plan]');
    var addonRow = root.querySelector('[data-calc-addon-row]');
    var addonBox = root.querySelector('[data-calc-addon]');
    var netLabel = root.querySelector('[data-calc-net-label]');
    var miniNet = root.querySelector('[data-calc-mini-net]');
    var miniLabel = root.querySelector('[data-calc-mini-label]');

    function field(key) { return root.querySelector('[data-calc-input="' + key + '"]'); }
    function range(key) { return root.querySelector('[data-calc-range="' + key + '"]'); }
    function value(key) {
        var el = field(key), n = parseFloat(el.value);
        if (isNaN(n)) n = 0;
        return Math.min(Math.max(n, parseFloat(el.min)), parseFloat(el.max));
    }
    function out(key, text) { root.querySelector('[data-calc-out="' + key + '"]').textContent = text; }
    function explain(key, text) { root.querySelector('[data-calc-explain="' + key + '"]').textContent = text; }

    function calc() {
        var tables = value('tables'), copies = value('copies'), cost = value('cost'), changes = value('changes');
        var sales = value('sales'), commission = value('commission'), moved = value('moved');
        var plan = plans.filter(function (p) { return p.code === planSelect.value; })[0] || plans[0] || { monthly: 0, online: false };

        var needsPack = !plan.online && pack !== null;
        if (addonRow) addonRow.hidden = !needsPack;
        var withPack = needsPack && addonBox && addonBox.checked;
        var canOrderOnline = plan.online || withPack;

        var reprint = tables * copies * cost * changes;
        var kept = canOrderOnline ? sales * 12 * (moved / 100) * (commission / 100) : 0;
        var yearly = (plan.monthly + (withPack ? pack : 0)) * 12;
        var net = reprint + kept - yearly;

        out('reprint', '+' + fmt(reprint));
        out('commission', '+' + fmt(kept));
        out('plan', yearly > 0 ? '−' + fmt(yearly) : '0');
        out('net', (net < 0 ? '−' : '') + fmt(Math.abs(net)));
        explain('reprint', fmt(tables) + ' × ' + fmt(copies) + ' × ' + fmt(cost) + ' × ' + fmt(changes));
        explain('commission', canOrderOnline ? fmt(sales) + ' × 12 × ' + fmt(moved) + '% × ' + fmt(commission) + '%' : L.needsOnline);
        explain('plan', fmt(plan.monthly + (withPack ? pack : 0)) + ' × 12');
        netLabel.textContent = netLabel.getAttribute(net < 0 ? 'data-negative' : 'data-positive');
        if (miniNet) miniNet.textContent = (net < 0 ? '−' : '') + fmt(Math.abs(net));
        if (miniLabel) miniLabel.textContent = netLabel.textContent;
    }

    root.querySelectorAll('[data-calc-input]').forEach(function (el) {
        var key = el.getAttribute('data-calc-input'), slider = range(key);
        el.addEventListener('input', function () { if (el.value !== '') slider.value = value(key); calc(); });
        el.addEventListener('change', function () { el.value = value(key); slider.value = el.value; calc(); });
        slider.addEventListener('input', function () { el.value = slider.value; calc(); });
    });
    planSelect.addEventListener('change', calc);
    if (addonBox) addonBox.addEventListener('change', calc);
    calc();
})();
</script>
