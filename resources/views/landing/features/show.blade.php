@extends('layouts.landing')
@section('title', $module['name'] . ' · EgMenu')
@section('description', $module['tagline'] . ' ' . $module['summary'])

@php
    $groups = \App\Support\SiteModules::groups();
    $pricing = ['plans' => $plans, 'addons' => $addons, 'matrix' => $matrix];
    $where = \App\Support\SiteModules::where($module, $pricing);
    $planByCode = $plans->keyBy('code');
    $extraBranchOff = (int) round((1 - \App\Models\AddonPrice::EXTRA_BRANCH_SHARE) * 100);
    $hasScene = view()->exists('landing.scenes.' . $module['slug']);
    $isAddon = $availability['plans']->isEmpty() && $availability['addons']->isNotEmpty();
@endphp

@section('page')
{{-- ============ HERO ============ --}}
<section data-hero class="border-b border-navy-100 bg-[var(--lp-paper)]">
    <div class="mx-auto max-w-7xl px-4 pt-6 pb-12 sm:px-6 lg:pt-10 lg:pb-16">
        <nav class="text-sm font-semibold text-navy-500" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('features') }}" class="hover:text-navy-900">{{ __('Features') }}</a>
            <span class="mx-1.5 text-navy-300" aria-hidden="true">/</span>
            <span>{{ $groups[$module['group']][0] }}</span>
        </nav>
        <div class="mt-6 grid items-center gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
            <div>
                <p class="flex items-center gap-2 text-base font-extrabold text-brand-700">
                    <x-landing.icon :name="$module['icon']" class="size-5" />{{ $module['name'] }}
                </p>
                <h1 class="mt-3 text-[2rem] leading-[1.3] font-black tracking-tight text-navy-900 sm:text-[2.6rem]">{{ $module['tagline'] }}</h1>
                <p class="lp-lead mt-4 max-w-lg">{{ $module['summary'] }}</p>
                @if ($where)
                    <p class="mt-5 inline-flex items-center gap-2 rounded-full border border-navy-200 bg-white px-3 py-1.5 text-sm font-bold text-navy-700">
                        <span @class(['size-2 rounded-full', 'bg-emerald-500' => $availability['everyPlan'], 'bg-brand-500' => !$availability['everyPlan']])></span>{{ $where }}
                    </p>
                @endif
                <div class="mt-7 flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="btn-primary min-h-12 px-6 text-base">{{ __('Start free') }}</a>
                    <a href="#how" class="btn-ghost min-h-12 px-5 text-base">{{ __('See how it works') }}</a>
                </div>
            </div>
            <div class="relative">
                <x-landing.shot :name="$module['shot']" :frame="$module['frame']" :url="$module['url']" :eager="true" :alt="__(':module in EgMenu (real screen)', ['module' => $module['name']])" />
                @if ($module['phone'])
                    <x-landing.shot :name="$module['phone']" frame="phone" :alt="__(':module on the guest’s phone (real screen)', ['module' => $module['name']])" sizes="160px"
                                    class="absolute -bottom-8 -end-2 hidden w-[150px] sm:block lg:-end-6 lg:w-[170px]" />
                @endif
            </div>
        </div>
    </div>
</section>

{{-- ============ WORKFLOW ============ --}}
@if ($hasScene)
    <section id="how" class="py-16 lg:py-24">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <p class="lp-eyebrow">{{ __('How it works') }}</p>
            <h2 class="lp-h2 mt-2">{{ __(':module, step by step', ['module' => $module['name']]) }}</h2>
            <x-landing.scene class="mt-8" :steps="$module['steps']" :label="__(':module, step by step', ['module' => $module['name']])">
                @include('landing.scenes.' . $module['slug'])
            </x-landing.scene>
        </div>
    </section>
@endif

{{-- ============ BENEFITS ============ --}}
<section class="border-t border-navy-100 py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <h2 class="lp-h2 max-w-2xl">{{ __('What you get') }}</h2>
        <div @class(['mt-10 grid gap-x-8 gap-y-10 sm:grid-cols-2', 'lg:grid-cols-4' => count($module['benefits']) === 4, 'lg:grid-cols-3' => count($module['benefits']) !== 4])>
            @foreach ($module['benefits'] as [$icon, $title, $text])
                <div class="border-t-2 border-navy-900 pt-5" data-reveal style="--d: {{ $loop->index * 70 }}ms">
                    <x-landing.icon :name="$icon" class="size-6 text-brand-600" />
                    <h3 class="mt-3 text-lg font-extrabold">{{ $title }}</h3>
                    <p class="mt-1.5 leading-relaxed text-navy-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ MORE SCREENS ============ --}}
@foreach ($module['gallery'] ?? [] as [$shot, $frame, $url, $caption])
    <section class="border-t border-navy-100 py-14 lg:py-16">
        <figure class="mx-auto grid max-w-7xl items-center gap-6 px-4 sm:px-6 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] lg:gap-12">
            <figcaption class="text-lg leading-relaxed text-navy-700"><x-landing.icon name="layers" class="mb-3 size-6 text-brand-600" />{{ $caption }}</figcaption>
            <x-landing.shot :name="$shot" :frame="$frame" :url="$url" :alt="$caption" data-reveal />
        </figure>
    </section>
@endforeach

{{-- ============ WHO + WHERE ============ --}}
<section class="bg-[var(--lp-paper)] py-16 lg:py-20">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:gap-14">
        <div>
            <h2 class="lp-h2">{{ __('Who is it for') }}</h2>
            <div class="mt-8 space-y-4">
                @foreach (['cafe' => ['cup', __('In a cafe like Rosto')], 'restaurant' => ['plate', __('In a restaurant like Sofra')]] as $type => [$icon, $label])
                    <div class="flex gap-4 rounded-2xl border border-navy-100 bg-white p-5">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-navy-900 text-white"><x-landing.icon :name="$icon" class="size-5" /></span>
                        <div>
                            <h3 class="font-extrabold">{{ $label }}
                                @if (in_array($type, $module['for'], true))<span class="ms-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700">{{ __('A great fit') }}</span>@endif
                            </h3>
                            <p class="mt-1 leading-relaxed text-navy-600">{{ $module['who'][$type] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div id="price">
            <h2 class="lp-h2">{{ __('Where you get it') }}</h2>
            <div class="mt-8 overflow-hidden rounded-2xl border border-navy-200 bg-white">
                @if ($isAddon)
                    @foreach ($availability['addons'] as $addon)
                        @php
                            $minPlan = $addon->min_plan ? $planByCode->get($addon->min_plan) : null;
                        @endphp
                        <div @class(['p-5', 'border-t border-navy-100' => !$loop->first])>
                            <div class="flex items-baseline justify-between gap-4">
                                <h3 class="font-extrabold">{{ $addon->displayName() }}</h3>
                                <p class="shrink-0 text-navy-500"><b class="text-2xl font-black text-navy-900"><bdi dir="ltr">+{{ money($addon->monthly_price) }}</bdi></b> {{ __('EGP / month') }}</p>
                            </div>
                            <p class="mt-1 text-sm text-navy-500">{{ tr($addon->description, $addon->description_en) }}</p>
                            <div class="mt-3 flex flex-wrap gap-1.5 text-xs font-bold">
                                @if ($addon->per_branch)<span class="rounded-full bg-navy-50 px-2.5 py-1 text-navy-700">{{ __('Per branch, :pct% off each extra branch', ['pct' => $extraBranchOff]) }}</span>@endif
                                @if ($minPlan)<span class="rounded-full bg-brand-50 px-2.5 py-1 text-brand-700">{{ __('With :plan or higher', ['plan' => $minPlan->displayName()]) }}</span>@endif
                            </div>
                        </div>
                    @endforeach
                @else
                    <ul>
                        @foreach ($plans as $p)
                            @php
                                $has = $availability['plans']->contains('id', $p->id);
                            @endphp
                            <li @class(['flex min-h-14 items-center justify-between gap-3 px-5', 'border-t border-navy-100' => !$loop->first, 'bg-emerald-50/50' => $has])>
                                <span class="flex items-center gap-3">
                                    @if ($has)
                                        <span class="grid size-6 place-items-center rounded-full bg-emerald-600 text-white"><x-landing.icon name="check" class="size-4" /></span><span class="sr-only">{{ __('Included') }}</span>
                                    @else
                                        <span class="grid size-6 place-items-center rounded-full bg-navy-100 text-navy-400"><x-landing.icon name="x" class="size-3.5" /></span><span class="sr-only">{{ __('Not included') }}</span>
                                    @endif
                                    <b @class(['text-navy-900' => $has, 'text-navy-400' => !$has])>{{ $p->displayName() }}</b>
                                </span>
                                <span class="text-sm text-navy-500">
                                    @if ((float) $p->monthly_price === 0.0)
                                        <b class="text-emerald-700">{{ __('Free') }}</b>
                                    @else
                                        <b class="text-navy-900">{{ money($p->monthly_price) }}</b> {{ __('EGP / month') }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @foreach ($availability['addons'] as $addon)
                        @php
                            $minPlan = $addon->min_plan ? $planByCode->get($addon->min_plan) : null;
                        @endphp
                        <div class="border-t border-dashed border-navy-200 bg-navy-50/60 px-5 py-4 text-sm">
                            <b>{{ $minPlan ? __('Or as an add-on with :plan or higher:', ['plan' => $minPlan->displayName()]) : __('Or as an add-on on any plan:') }}</b> {{ $addon->displayName() }} · <b><bdi dir="ltr">+{{ money($addon->monthly_price) }}</bdi></b> {{ __('EGP / month') }}
                        </div>
                    @endforeach
                @endif
                @if ($availability['credits']->isNotEmpty())
                    <div class="border-t border-navy-100 px-5 py-4">
                        <h3 class="font-extrabold">{{ __('WhatsApp message credits') }}</h3>
                        <p class="mt-1 text-sm text-navy-500">{{ __('Every message uses one credit. Credits are paid once and never expire.') }}</p>
                        <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($availability['credits'] as $pack)
                                <li class="rounded-xl border border-navy-200 px-3 py-2.5">
                                    <span class="block text-sm font-bold">{{ $pack->displayName() }}</span>
                                    <span class="text-sm text-navy-500"><b class="text-lg font-black text-navy-900"><bdi dir="ltr">{{ money($pack->monthly_price) }}</bdi></b> {{ __('EGP') }} · {{ __('Paid once') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-navy-100 bg-navy-900 px-5 py-4 text-white">
                    <span class="text-sm text-white/75">{{ __('Every account starts free, with :days days of Pro to try everything.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</span>
                    <a href="{{ route('register') }}" class="btn-primary min-h-11">{{ __('Start free') }}</a>
                </div>
            </div>
            <a href="{{ route('pricing') }}" class="mt-3 inline-flex min-h-11 items-center text-sm font-bold text-navy-700 underline decoration-navy-300 underline-offset-4 hover:text-navy-900">{{ __('Compare all plans and add-ons') }}</a>
        </div>
    </div>
</section>

{{-- ============ FAQ ============ --}}
<section class="py-16 lg:py-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6">
        <h2 class="lp-h2">{{ __('Questions about :module', ['module' => $module['name']]) }}</h2>
        <div class="mt-8 divide-y divide-navy-100 border-y border-navy-100">
            @foreach ($module['faq'] as [$q, $a])
                <details class="lp-faq group" @if ($loop->first) open @endif>
                    <summary class="flex min-h-15 cursor-pointer list-none items-center justify-between gap-4 py-4 text-[1.05rem] font-extrabold">
                        {{ $q }}
                        <span class="lp-faq-icon grid size-8 shrink-0 place-items-center rounded-full border border-navy-200 text-lg leading-none text-navy-700">+</span>
                    </summary>
                    <p class="pb-5 leading-relaxed text-navy-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ============ RELATED + PREV/NEXT ============ --}}
<section class="border-t border-navy-100 bg-[var(--lp-paper)] py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        <h2 class="text-2xl font-black">{{ __('Works well with') }}</h2>
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($related as $r)
                <x-landing.module-card :m="$r" :note="\App\Support\SiteModules::where($r, $pricing)" />
            @endforeach
        </div>
        <nav class="mt-12 grid gap-3 border-t border-navy-200 pt-6 sm:grid-cols-2" aria-label="{{ __('More features') }}">
            <a href="{{ route('features.show', $previous['slug']) }}" class="flex min-h-16 flex-col justify-center rounded-xl px-1 hover:text-brand-700">
                <span class="text-xs font-bold text-navy-500"><span aria-hidden="true" class="inline-block ltr:rotate-180">→</span> {{ __('Previous') }}</span>
                <span class="text-lg font-extrabold">{{ $previous['name'] }}</span>
            </a>
            <a href="{{ route('features.show', $next['slug']) }}" class="flex min-h-16 flex-col justify-center rounded-xl px-1 text-end hover:text-brand-700">
                <span class="text-xs font-bold text-navy-500">{{ __('Next') }} <span aria-hidden="true" class="inline-block rtl:rotate-180">→</span></span>
                <span class="text-lg font-extrabold">{{ $next['name'] }}</span>
            </a>
        </nav>
    </div>
</section>
@endsection
