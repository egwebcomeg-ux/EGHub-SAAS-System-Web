@extends('layouts.landing')
@section('title', __('EgHub apps') . ' · EgHub')
@section('share_card', 'apps')
@section('description', __('The EgHub apps for Android: the TV screens player, and early versions of the cashier, owner, waiter, driver and kiosk apps to download today.'))

@section('page')
<section data-hero class="lp-hero lp-glow">
    <div class="mx-auto max-w-7xl px-4 pt-10 pb-10 text-center sm:px-6 lg:pt-14 lg:pb-14">
        <p class="lp-eyebrow" data-reveal>{{ __('EgHub apps') }}</p>
        <h1 class="mx-auto mt-4 max-w-3xl text-[2rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.25]" data-reveal style="--d: 70ms">{{ __('EgHub on your devices') }}</h1>
        <p class="lp-lead mx-auto mt-4 max-w-2xl" data-reveal style="--d: 140ms">{{ __('Everything already works in the browser of any phone, tablet or computer. The apps add what a browser cannot do: printing by itself, notifications with the app closed, the location with the screen off.') }}</p>
    </div>
</section>

<section class="py-16 lg:py-24">
    <div class="mx-auto grid max-w-7xl gap-4 px-4 sm:px-6 md:grid-cols-2 lg:grid-cols-3 lg:gap-5">
        @foreach ($apps as $app)
            @php $available = in_array($app['status'], [\App\Support\EgHubApps::AVAILABLE, \App\Support\EgHubApps::BETA], true); $beta = $app['status'] === \App\Support\EgHubApps::BETA; @endphp
            <article id="{{ $app['key'] }}" class="lp-lift flex scroll-mt-24 flex-col rounded-[28px] border border-navy-100 bg-white p-6 sm:p-7" data-app="{{ $app['key'] }}" data-status="{{ $app['status'] }}" data-reveal style="--d: {{ ($loop->index % 3) * 70 }}ms">
                <div class="flex items-start gap-4">
                    <span class="lp-idea-icon size-12!"><x-landing.icon :name="$app['icon']" class="size-6" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-extrabold text-navy-900">{{ $app['name'] }}</h2>
                            <span @class(['rounded-full px-2.5 py-0.5 text-xs font-extrabold', 'bg-emerald-50 text-emerald-700' => $available && ! $beta, 'bg-sky-50 text-sky-800' => $beta, 'bg-amber-50 text-amber-800' => ! $available]) data-app-status>{{ \App\Support\EgHubApps::statusLabel($app['status']) }}</span>
                        </div>
                        <p class="mt-0.5 text-sm text-navy-500">{{ $app['for'] }}</p>
                    </div>
                </div>
                <p class="mt-4 text-navy-700">{{ $app['summary'] }}</p>
                <ul class="mt-4 space-y-2 text-sm text-navy-700">
                    @foreach ($app['features'] as $line)
                        <li class="flex gap-2"><x-landing.icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-600" />{{ $line }}</li>
                    @endforeach
                </ul>
                <p class="mt-4 rounded-xl bg-navy-50 p-3 text-sm text-navy-600">{{ $app['today'] }}</p>
                <div class="mt-auto flex flex-wrap items-center gap-2 pt-5">
                    @if ($available && $app['download'])
                        <a href="{{ $app['download'] }}" class="btn-primary min-h-11" download data-app-download>{{ __('Download for Android') }}</a>
                        @if ($beta)<span class="text-xs font-semibold text-navy-500" data-app-beta>{{ __('Early version, not on Google Play yet') }}</span>@endif
                    @else
                        <span class="inline-flex min-h-11 items-center rounded-xl border border-dashed border-navy-200 px-4 text-sm font-bold text-navy-500">{{ __('Not on the app stores yet') }}</span>
                    @endif
                    @if ($app['module'] && isset($modules[$app['module']]))
                        <a href="{{ route('features.show', $app['module']) }}" class="inline-flex min-h-11 items-center px-2 text-sm font-extrabold text-brand-700 hover:text-brand-600">{{ $modules[$app['module']]['name'] }} <span aria-hidden="true" class="ms-1 rtl:inline-block rtl:rotate-180">→</span></a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    <p class="mx-auto mt-10 max-w-2xl px-4 text-center text-sm text-navy-500">{{ __('An early version works and is still being tried on more devices; it moves to Google Play next. Download EgHub apps only from this page.') }}</p>
</section>
@endsection
