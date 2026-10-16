@extends('layouts.base')
@section('title', $profile->title())
@section('body_class', 'bg-navy-50 text-navy-900')

@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $name = $profile->name();
    $currency = tr($settings['currency'], $settings['currency_en']);
    $cuisines = $profile->cuisines();
    $place = $profile->place();
    $description = trim(tr($settings['short_description'], $settings['short_description_en'])) ?: trim(tr($settings['tagline'], $settings['tagline_en']));
@endphp

@push('head')
@include('menu.partials.seo-head', ['profile' => $profile, 'schemas' => $schemas])
<meta name="theme-color" content="{{ $color('color_secondary', '#1E2D4F') }}">
@include('menu.partials.pixels')
<style>
    :root { --menu-primary: {{ $color('color_primary', '#E8642C') }}; --menu-secondary: {{ $color('color_secondary', '#1E2D4F') }}; }
    .m-primary { background: var(--menu-primary); color: #fff; }
    .m-primary-text { color: var(--menu-primary); }
    .m-secondary { background: var(--menu-secondary); color: #fff; }
</style>
@endpush

@section('body')
<header class="m-secondary">
    @if ($settings['cover'])
        <img src="/storage/{{ $settings['cover'] }}" alt="{{ $name }}" class="h-44 w-full object-cover sm:h-64">
    @endif
    <div class="mx-auto flex max-w-3xl items-center gap-4 px-4 py-5">
        @if ($settings['logo'])
            <img src="/storage/{{ $settings['logo'] }}" alt="{{ __('Logo of :name', ['name' => $name]) }}" class="size-20 shrink-0 rounded-2xl bg-white object-contain p-1 {{ $settings['cover'] ? '-mt-12 shadow-lg' : '' }}">
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-black sm:text-3xl">{{ $name }}</h1>
            <p class="mt-1 text-sm text-white/80">{{ $profile->typeLabel() }}@if ($place) · {{ $place }}@endif</p>
        </div>
        @include('menu.partials.lang-switch', ['class' => 'self-start'])
    </div>
</header>

<main class="mx-auto max-w-3xl space-y-5 px-4 py-6">
    <section class="rounded-3xl bg-white p-5 shadow-sm">
        @if ($description)
            <p class="text-lg leading-relaxed">{{ $description }}</p>
        @endif
        @if ($cuisines)
            <ul class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('What they serve') }}">
                @foreach ($cuisines as $c)
                    <li class="rounded-full bg-navy-50 px-3 py-1 text-sm font-semibold">{{ $c }}</li>
                @endforeach
            </ul>
        @endif
        <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
            @if ($rating)
                <span class="rounded-full bg-amber-50 px-3 py-1 font-bold text-amber-800">★ {{ number_format($rating['avg'], 1) }} <span class="font-normal">({{ __(':n ratings', ['n' => $rating['count']]) }})</span></span>
            @endif
            @if ($ordering['table'])
                <span class="rounded-full bg-emerald-50 px-3 py-1 font-bold text-emerald-800">{{ __('Order from the table') }}</span>
            @endif
            @if ($ordering['delivery'])
                <span class="rounded-full bg-sky-50 px-3 py-1 font-bold text-sky-800">{{ __('Delivery') }}</span>
            @endif
            @if ($ordering['takeaway'])
                <span class="rounded-full bg-navy-50 px-3 py-1 font-bold text-navy-700">{{ __('Takeaway') }}</span>
            @endif
        </div>
        <a href="{{ route('menu.show') }}" class="m-primary mt-5 flex min-h-14 items-center justify-center rounded-2xl text-lg font-extrabold shadow-md">{{ __('Open the menu') }} @if ($itemsCount)<span class="ms-2 text-sm font-semibold opacity-80">({{ __(':n items', ['n' => $itemsCount]) }})</span>@endif</a>
        @if (\App\Services\Reservations\ReservationService::enabled())
            <a href="{{ route('menu.reserve') }}" class="mt-3 flex min-h-12 items-center justify-center rounded-2xl border-2 border-navy-100 text-base font-extrabold">{{ __('Book a table') }}</a>
        @endif
    </section>

    @if ($featured->isNotEmpty())
        <section>
            <h2 class="mb-3 text-xl font-extrabold">{{ __('From the menu') }}</h2>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($featured as $item)
                    <li class="overflow-hidden rounded-2xl bg-white shadow-sm">
                        @if ($item->image)
                            <img src="/storage/{{ $item->image }}" alt="{{ $item->displayName() }}" loading="lazy" class="aspect-square w-full object-cover">
                        @endif
                        <div class="p-3">
                            <h3 class="line-clamp-2 text-sm font-bold">{{ $item->displayName() }}</h3>
                            <p class="m-primary-text mt-1 text-sm font-extrabold">{{ money($item->price) }} {{ $currency }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($branches->isNotEmpty())
        <section>
            <h2 class="mb-3 text-xl font-extrabold">{{ $branches->count() > 1 ? __('Branches') : __('Address & hours') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($branches as $b)
                    @php
                        $wa = $profile->whatsapp($b);
                        $map = $profile->mapUrl($b);
                    @endphp
                    <article class="rounded-2xl bg-white p-4 shadow-sm">
                        @if ($branches->count() > 1)<h3 class="mb-2 font-extrabold">{{ tr($b->name, $b->name_en) }}</h3>@endif
                        <dl class="space-y-1.5 text-sm">
                            @if ($b->address)<div class="flex gap-2"><dt class="w-20 shrink-0 text-navy-500">{{ __('Address') }}</dt><dd>{{ $b->address }}</dd></div>@endif
                            @if ($b->working_hours)<div class="flex gap-2"><dt class="w-20 shrink-0 text-navy-500">{{ __('Hours') }}</dt><dd>{{ $b->working_hours }}</dd></div>@endif
                            @if ($b->phone)<div class="flex gap-2"><dt class="w-20 shrink-0 text-navy-500">{{ __('Phone') }}</dt><dd><a href="tel:{{ $b->phone }}" dir="ltr" class="font-semibold">{{ $b->phone }}</a></dd></div>@endif
                        </dl>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($wa)<a href="https://wa.me/{{ whatsapp_number($wa) }}" target="_blank" rel="noopener" class="btn-ghost min-h-10 px-3 text-sm">{{ __('WhatsApp') }}</a>@endif
                            @if ($map)<a href="{{ $map }}" target="_blank" rel="noopener" class="btn-ghost min-h-10 px-3 text-sm">{{ __('Open in Google Maps') }}</a>@endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <footer class="space-y-3 pt-4 text-center text-sm text-navy-500">
        <div class="flex justify-center gap-4">
            @foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'] as $key => $label)
                @if ($settings[$key])<a href="{{ $settings[$key] }}" target="_blank" rel="noopener" class="font-semibold">{{ $label }}</a>@endif
            @endforeach
        </div>
        <a href="{{ platform_url(null, 'restaurants') }}" class="inline-flex items-center gap-1 text-xs">{{ __('More places on') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" /></a>
    </footer>
</main>
@endsection
