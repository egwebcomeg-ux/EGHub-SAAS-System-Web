{{--
    Plan cards, from the database. The plan priced 0 is the free one and gets its own wide card first; the
    monthly / 6 / 12 months switch only changes the paid cards. Needs $plans and $matrix.
    Phones show each card's key lines; "What is included" opens the rest (always open on wide screens).
--}}
@php
    $free = $plans->first(fn ($p) => (float) $p->monthly_price === 0.0);
    $paid = $plans->reject(fn ($p) => (float) $p->monthly_price === 0.0)->values();
    $cycles = collect(\App\Models\Plan::CYCLES)->mapWithKeys(fn ($m) => [$m => (float) ($paid->max(fn ($p) => (float) ($p->cycle_discounts[(string) $m] ?? 0)) ?? 0)]);
    $trial = \App\Services\TenantProvisioner::TRIAL_DAYS;
    $paidAdds = ['qr_ordering', 'online_ordering', 'reservations', 'loyalty_stamps', 'offers', 'whatsapp_notifications', 'printed_menu', 'menu_themes', 'daily_reports', 'custom_domain'];
    $chevron = '<svg class="lp-chev size-5 text-navy-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"/></svg>';
@endphp

@if ($free)
    @php
        $fe = $matrix[$free->id];
        $freeLimits = array_filter($fe['limits'], fn ($v) => $v !== 0);
        $missing = array_values(array_filter($paidAdds, fn ($k) => empty($fe['features'][$k])));
        // [text, muted]: the first three show on every screen, the rest under "What is included" on phones
        $freeLines = [[__('Your menu online with QR codes'), false], [__('Arabic and English'), false]];
        foreach ($freeLimits as $key => $v) {
            $freeLines[] = [\App\Models\Plan::label($key) . ': ' . ($v === null ? __('Unlimited') : $v), false];
        }
        foreach (array_keys(array_filter($fe['features'])) as $key) {
            $freeLines[] = [\App\Models\Plan::label($key), false];
        }
        if (empty($fe['features']['hide_badge'])) {
            $freeLines[] = [__('A small egmenu badge on the menu'), true];
        }
        $freeKey = array_slice($freeLines, 0, 3);
        $freeRest = array_slice($freeLines, 3);
    @endphp
    <div class="overflow-hidden rounded-[28px] border-2 border-emerald-600 bg-white" data-reveal>
        <div class="grid lg:grid-cols-[1.15fr_1fr]">
            <div class="p-6 sm:p-8">
                <div class="flex flex-wrap items-center gap-3">
                    <h3 class="text-2xl font-black">{{ $free->displayName() }}</h3>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-bold text-emerald-800">{{ __('Free forever, no card') }}</span>
                </div>
                <p class="mt-2 text-navy-600">{{ tr($free->tagline, $free->tagline_en) }}</p>
                <p class="mt-4 flex items-end gap-2"><span class="text-5xl font-black tracking-tight">0</span><span class="mb-1.5 font-bold text-navy-500">{{ __('EGP / month') }}</span></p>
                <ul class="mt-5 grid gap-2.5 sm:grid-cols-2">
                    @foreach ($freeKey as [$line, $muted])
                        <li @class(['flex items-start gap-2', 'text-navy-500' => $muted])><x-landing.icon :name="$muted ? 'flag' : 'check'" @class(['mt-1 size-4 shrink-0', 'text-emerald-600' => !$muted]) />{{ $line }}</li>
                    @endforeach
                </ul>
                @if ($freeRest)
                    <details class="lp-acc mt-2.5" data-open-wide>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between border-t border-navy-100 font-bold text-navy-800 lg:hidden">{{ __('What is included') }}{!! $chevron !!}</summary>
                        <ul class="grid gap-2.5 pt-2 sm:grid-cols-2 lg:pt-0">
                            @foreach ($freeRest as [$line, $muted])
                                <li @class(['flex items-start gap-2', 'text-navy-500' => $muted])><x-landing.icon :name="$muted ? 'flag' : 'check'" @class(['mt-1 size-4 shrink-0', 'text-emerald-600' => !$muted]) />{{ $line }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                <a href="{{ route('register') }}" class="btn mt-6 min-h-13 w-full rounded-full bg-emerald-600 px-6 text-base text-white hover:bg-emerald-700 sm:w-auto">{{ __('Start free — no card, no commitment') }}</a>
            </div>
            @if ($missing)
                <div class="border-t border-emerald-100 bg-emerald-50/60 px-6 py-2 sm:px-8 lg:border-t-0 lg:border-s lg:py-8">
                    <details class="lp-acc" data-open-wide>
                        <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 font-extrabold lg:hidden">{{ __('When you need more, the paid plans add:') }}{!! $chevron !!}</summary>
                        <h4 class="hidden font-extrabold lg:block">{{ __('When you need more, the paid plans add:') }}</h4>
                        <ul class="mt-2 space-y-2.5 text-navy-700 lg:mt-4">
                            @foreach ($missing as $key)
                                <li class="flex items-start gap-2"><span class="mt-2 size-1.5 shrink-0 rounded-full bg-brand-600"></span>{{ \App\Models\Plan::label($key) }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-5 pb-4 text-sm text-navy-500 lg:pb-0">{{ __('Every new account also gets :days days of Pro, so you can try them before you pay anything.', ['days' => $trial]) }}</p>
                    </details>
                </div>
            @endif
        </div>
    </div>
@endif

<div class="mt-14 flex flex-col items-center justify-between gap-4 text-center sm:flex-row sm:items-end sm:text-start">
    <div>
        <h3 class="text-2xl font-black">{{ __('Paid plans') }}</h3>
        <p class="mt-1 text-navy-500">{{ __('No setup fees, no commission. Cancel whenever you want.') }}</p>
    </div>
    <div class="inline-flex rounded-full border border-navy-200 bg-white p-1" role="group" aria-label="{{ __('Billing period') }}">
        @foreach ($cycles as $m => $pct)
            <button type="button" data-cycle="{{ $m }}" aria-pressed="{{ $m === 1 ? 'true' : 'false' }}" class="flex min-h-11 items-center gap-1.5 rounded-full px-3 text-sm font-bold text-navy-600 transition duration-300 aria-pressed:bg-navy-800 aria-pressed:text-white sm:px-4">
                {{ $m === 1 ? __('Monthly') : cycle_label($m) }}
                @if ($pct > 0)<span class="rounded-full bg-emerald-100 px-1.5 text-xs text-emerald-800" dir="ltr">−{{ money($pct) }}%</span>@endif
            </button>
        @endforeach
    </div>
</div>

<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($paid as $p)
        <div @class(['lp-lift relative flex flex-col rounded-[24px] border bg-white p-5 sm:p-6', 'border-navy-800 ring-1 ring-navy-800' => $p->is_featured, 'border-navy-100' => !$p->is_featured])
             data-plan-price data-monthly="{{ (float) $p->monthly_price }}" data-discounts="{{ json_encode((object) ($p->cycle_discounts ?? [])) }}" data-reveal style="--d: {{ $loop->index * 70 }}ms">
            @if ($p->is_featured)<span class="badge absolute -top-3 start-6 bg-navy-800 px-3 py-1 text-white">{{ __('Most popular') }}</span>@endif
            <div class="flex items-start justify-between gap-3 sm:block">
                <div class="min-w-0">
                    <h4 class="text-xl font-black">{{ $p->displayName() }}</h4>
                    <p class="mt-1 text-sm text-navy-500 sm:min-h-12">{{ tr($p->tagline, $p->tagline_en) }}</p>
                </div>
                <div class="shrink-0 text-end sm:mt-4 sm:text-start">
                    <div class="flex items-end justify-end gap-1.5 sm:justify-start">
                        @if ($p->original_price)<span class="mb-1 text-navy-400 line-through">{{ money($p->original_price) }}</span>@endif
                        <span class="text-4xl font-black tracking-tight" data-price>{{ money($p->monthly_price) }}</span>
                    </div>
                    <span class="text-sm font-semibold text-navy-500">{{ __('EGP / month') }}</span>
                </div>
            </div>
            <p class="mt-2 text-xs text-navy-500 sm:min-h-10" data-billed>{{ __('Billed monthly') }}</p>
            <details class="lp-acc mt-3 flex-1 text-sm" data-open-wide>
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between border-t border-navy-100 font-bold text-navy-800 lg:hidden">{{ __('What is included') }}{!! $chevron !!}</summary>
                <div class="pt-2">@include('partials.plan-features', ['plan' => $p])</div>
            </details>
            <a href="{{ route('register') }}" class="{{ $p->is_featured ? 'btn-navy' : 'btn-ghost' }} mt-5 min-h-12 w-full rounded-full">{{ __('Start free') }}</a>
        </div>
    @endforeach
</div>
