@extends('layouts.landing')
@section('title', $heading . ' | EgMenu')
@section('description', $description)

@section('page')
<section class="lp-hero lp-glow">
    <div class="mx-auto max-w-7xl px-4 pt-8 pb-10 sm:px-6 lg:pt-12 lg:pb-12">
        <nav class="text-sm font-semibold text-navy-500" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('directory') }}" class="hover:text-navy-900">{{ __('Restaurant directory') }}</a>
            @if ($city)
                <span aria-hidden="true"> / </span><a href="{{ route('directory.city', $city) }}" class="hover:text-navy-900">{{ \App\Support\Cities::name($city) }}</a>
            @endif
            @if ($cuisine)
                <span aria-hidden="true"> / </span><span>{{ \App\Support\Cuisines::name($cuisine) }}</span>
            @endif
        </nav>
        <h1 class="mt-3 text-[2rem] leading-[1.3] font-black tracking-tight sm:text-4xl">{{ $heading }}</h1>
        <p class="lp-lead mt-3 max-w-2xl">{{ __('Real menus with up-to-date prices, from the places themselves.') }}</p>

        @unless ($city)
            <form method="get" action="{{ route('directory') }}" class="mt-6 flex max-w-xl gap-2" role="search">
                <label for="q" class="sr-only">{{ __('Search by name or area') }}</label>
                <input id="q" name="q" type="search" value="{{ $search }}" maxlength="60" class="field min-h-12 flex-1" placeholder="{{ __('Search by name or area') }}">
                <button class="btn-primary min-h-12 rounded-full px-5">{{ __('Search') }}</button>
            </form>
        @endunless

        @if ($cities)
            <ul class="mt-6 flex flex-wrap gap-2" aria-label="{{ __('Cities') }}">
                @foreach ($cities as $slug => $count)
                    <li>
                        <a href="{{ route('directory.city', $slug) }}" @class(['flex min-h-10 items-center gap-1.5 rounded-full border px-4 text-sm font-bold', 'border-brand-500 bg-brand-50 text-brand-700' => $city === $slug, 'border-navy-100 bg-white text-navy-700 hover:border-navy-300' => $city !== $slug])>
                            {{ \App\Support\Cities::name($slug) }} <span class="text-xs font-semibold text-navy-400">{{ $count }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($city && $cuisines)
            <ul class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('What they serve') }}">
                @foreach ($cuisines as $key => $count)
                    <li>
                        <a href="{{ $cuisine === $key ? route('directory.city', $city) : route('directory.cuisine', [$city, $key]) }}" @class(['flex min-h-9 items-center rounded-full px-3 text-sm font-semibold', 'bg-navy-900 text-white' => $cuisine === $key, 'bg-navy-50 text-navy-700 hover:bg-navy-100' => $cuisine !== $key])>
                            {{ \App\Support\Cuisines::name($key) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

<section class="py-12 lg:py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        @if ($cards)
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($cards as $c)
                    <li class="relative" @if ($c['featured']) data-featured @endif>
                        <a href="{{ $c['url'] }}" @class(['lp-lift flex h-full flex-col rounded-[24px] border bg-white p-4', 'border-amber-300 ring-1 ring-amber-200 hover:border-amber-400' => $c['featured'], 'border-navy-100 hover:border-navy-300' => !$c['featured'], 'pb-16' => $c['online']]) data-place="{{ $c['slug'] }}">
                            @if ($c['featured'])
                                <span class="absolute -top-2.5 end-4 rounded-full bg-amber-400 px-2.5 py-0.5 text-xs font-extrabold text-amber-950">★ {{ __('Featured') }}</span>
                            @endif
                            <div class="flex items-center gap-3">
                                @if ($c['logo'])
                                    <img src="{{ $c['logo'] }}" alt="" loading="lazy" class="size-14 shrink-0 rounded-xl border border-navy-100 object-contain p-0.5">
                                @else
                                    <span class="grid size-14 shrink-0 place-items-center rounded-xl bg-navy-50 text-xl font-black text-navy-400" aria-hidden="true">{{ mb_substr($c['name'], 0, 1) }}</span>
                                @endif
                                <div class="min-w-0">
                                    <h2 class="truncate text-lg font-extrabold">{{ $c['name'] }}</h2>
                                    <p class="truncate text-sm text-navy-500">{{ $c['type'] }}@if ($c['area'] || $c['city']) · {{ implode(app()->getLocale() === 'ar' ? '، ' : ', ', array_filter([$c['area'], $c['city']])) }}@endif</p>
                                </div>
                            </div>
                            @if ($c['description'])
                                <p class="mt-3 line-clamp-2 text-sm text-navy-600">{{ $c['description'] }}</p>
                            @endif
                            @if ($c['cuisines'])
                                <p class="mt-2 text-xs font-semibold text-navy-500">{{ implode(' · ', $c['cuisines']) }}</p>
                            @endif
                            <div class="mt-auto flex flex-wrap gap-1.5 pt-3 text-xs font-bold">
                                @if ($c['rating'])
                                    <span class="rounded-full bg-amber-50 px-2.5 py-1 text-amber-800">★ {{ number_format($c['rating']['avg'], 1) }} ({{ $c['rating']['count'] }})</span>
                                @endif
                                @if ($c['table'])
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">{{ __('Order from the table') }}</span>
                                @endif
                                @if ($c['delivery'])
                                    <span class="rounded-full bg-sky-50 px-2.5 py-1 text-sky-800">{{ __('Delivery') }}</span>
                                @endif
                            </div>
                        </a>
                        @if ($c['online'])
                            {{-- outside the card link (no nested links): straight to the menu to order pickup or delivery --}}
                            <a href="{{ $c['menu_url'] }}" class="absolute start-4 bottom-4 inline-flex min-h-9 items-center gap-1 rounded-full bg-brand-600 px-3.5 text-sm font-bold text-white hover:bg-brand-700" data-order-online>
                                {{ __('Order online') }} <span aria-hidden="true" class="inline-block rtl:rotate-180">→</span>
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-8">{{ $places->links() }}</div>
        @else
            <div class="mx-auto max-w-lg rounded-3xl border border-dashed border-navy-200 p-8 text-center" data-empty>
                <p class="text-lg font-extrabold">
                    @if ($search !== '')
                        {{ __('No place matches “:q”.', ['q' => $search]) }}
                    @elseif ($city)
                        {{ __('No places listed here yet.') }}
                    @else
                        {{ __('The directory is just starting; no places are listed yet.') }}
                    @endif
                </p>
                <p class="mt-2 text-navy-500">{{ __('Own a cafe or restaurant here? Put your menu online for free and it shows up in this directory.') }}</p>
                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    <a href="{{ route('register') }}" class="btn-primary min-h-11">{{ __('Start free') }}</a>
                    @if ($city || $search !== '')<a href="{{ route('directory') }}" class="btn-ghost min-h-11">{{ __('All places') }}</a>@endif
                </div>
            </div>
        @endif
    </div>
</section>
@endsection
