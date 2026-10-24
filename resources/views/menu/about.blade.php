@extends('layouts.base')
@section('title', $profile->title())
@section('body_class', 'm-surface-2 m-text')

{{--
    The place's one page (/about, and /links shows it too): the links as big buttons on top, then the place itself —
    description, what they serve, rating, from the menu, branches with address, hours, phones and the map.
--}}
@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $name = $profile->name();
    $currency = tr($settings['currency'], $settings['currency_en']);
    $cuisines = $profile->cuisines();
    $place = $profile->place();
    $description = trim(tr($settings['short_description'], $settings['short_description_en'])) ?: trim(tr($settings['tagline'], $settings['tagline_en']));
    $socials = array_filter(['instagram' => $settings['instagram'], 'facebook' => $settings['facebook'], 'tiktok' => $settings['tiktok']]);
    $socialLabels = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'];
    $socialIcons = ['instagram' => '📸', 'facebook' => '👍', 'tiktok' => '🎵'];
    $withHours = $branches->filter(fn ($b) => trim((string) $b->working_hours) !== '');
@endphp

@push('head')
@include('menu.partials.seo-head', ['profile' => $profile, 'schemas' => $schemas])
@include('menu.partials.pixels')
{{-- theme-color, manifest and the home-screen icon come from partials.pwa-head (layouts.base) --}}
@if ($fontCss = \App\Support\MenuFonts::cssUrl($settings['font']))
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="{{ $fontCss }}">
@endif
@include('menu.partials.theme')
<style>
    body { font-family: '{{ $settings['font'] }}', 'Cairo', system-ui, sans-serif; }
    .l-btn { display: flex; align-items: center; gap: .75rem; min-height: 3.5rem; padding: 0 1.25rem; border-radius: 1.25rem; font-weight: 800; font-size: 1.05rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
    .l-btn.m-card { border: 1px solid var(--menu-border); background: var(--menu-surface); color: var(--menu-text); }
    .l-btn > span:first-child { font-size: 1.4rem; width: 2rem; text-align: center; }
    .faq-item { border: 1px solid var(--menu-border); color: var(--menu-text); }
    .faq-item[open] { box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .faq-answer { color: var(--menu-muted); }
    .faq-answer a { color: var(--menu-accent, currentColor); font-weight: 700; text-decoration: underline; text-underline-offset: 2px; overflow-wrap: anywhere; }
</style>
@endpush

@section('body')
<header class="m-secondary">
    @if ($settings['cover'])
        <img src="/storage/{{ $settings['cover'] }}" alt="{{ $name }}" class="h-40 w-full object-cover sm:h-60">
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
    {{-- the links: what a guest scanning the QR came for, one tap each --}}
    <nav class="grid gap-3 sm:grid-cols-2" aria-label="{{ __('Links of :name', ['name' => $name]) }}" data-links>
        <a href="{{ route('menu.show') }}" class="l-btn m-primary sm:col-span-2" data-link="menu"><span aria-hidden="true">📖</span>
            <span>{{ __('Open the menu') }} @if ($itemsCount)<span class="ms-1 text-sm font-semibold opacity-80">({{ __(':n items', ['n' => $itemsCount]) }})</span>@endif</span></a>
        @if ($online)
            <a href="{{ route('menu.show') }}#order" class="l-btn m-card" data-link="order"><span aria-hidden="true">🛵</span><span>{{ __('Order online') }}</span></a>
        @endif
        @if ($reservations)
            <a href="{{ route('menu.reserve') }}" class="l-btn m-card" data-link="reserve"><span aria-hidden="true">🪑</span><span>{{ __('Book a table') }}</span></a>
        @endif
        @if ($whatsapp)
            <a href="https://wa.me/{{ whatsapp_number($whatsapp) }}" target="_blank" rel="noopener" class="l-btn m-card" data-link="whatsapp"><span aria-hidden="true">💬</span><span>{{ __('WhatsApp') }}</span></a>
        @endif
        @foreach ($socials as $key => $url)
            <a href="{{ $url }}" target="_blank" rel="noopener" class="l-btn m-card" data-link="{{ $key }}"><span aria-hidden="true">{{ $socialIcons[$key] }}</span><span>{{ $socialLabels[$key] }}</span></a>
        @endforeach
    </nav>

    @if ($description || $cuisines || $rating || in_array(true, $ordering, true))
        <section class="rounded-3xl m-surface p-5 shadow-sm" data-about>
            @if ($description)
                <p class="text-lg leading-relaxed">{{ $description }}</p>
            @endif
            @if ($cuisines)
                <ul class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('What they serve') }}">
                    @foreach ($cuisines as $c)
                        <li class="rounded-full m-surface-2 px-3 py-1 text-sm font-semibold">{{ $c }}</li>
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
                    <span class="rounded-full m-surface-2 px-3 py-1 font-bold m-muted">{{ __('Takeaway') }}</span>
                @endif
            </div>
        </section>
    @endif

    @if ($featured->isNotEmpty())
        <section>
            <h2 class="mb-3 text-xl font-extrabold">{{ __('From the menu') }}</h2>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($featured as $item)
                    <li class="overflow-hidden rounded-2xl m-surface shadow-sm">
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
        <section @if ($withHours->isNotEmpty()) data-hours @endif>
            <h2 class="mb-3 text-xl font-extrabold">{{ $branches->count() > 1 ? __('Branches') : __('Address & hours') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($branches as $b)
                    @php
                        $wa = $profile->whatsapp($b);
                        $open = \App\Support\OpeningHours::isOpenAt($b->working_hours, now());
                    @endphp
                    <article class="rounded-2xl m-surface p-4 shadow-sm" data-branch-card="{{ $b->id }}">
                        @if ($branches->count() > 1)<h3 class="mb-2 font-extrabold">{{ tr($b->name, $b->name_en) }}</h3>@endif
                        <dl class="space-y-1.5 text-sm">
                            @if ($b->address)<div class="flex gap-2"><dt class="w-20 shrink-0 m-muted">{{ __('Address') }}</dt><dd>{{ $b->address }}</dd></div>@endif
                            @if ($b->working_hours)<div class="flex gap-2"><dt class="w-20 shrink-0 m-muted">{{ __('Opening hours') }}</dt><dd>{{ $b->working_hours }}
                                @if ($open !== null)<span @class(['badge ms-1', 'bg-emerald-100 text-emerald-800' => $open, 'm-surface-2 m-muted' => ! $open])>{{ $open ? __('Open now') : __('Closed now') }}</span>@endif
                            </dd></div>@endif
                            @if ($b->phone)<div class="flex gap-2"><dt class="w-20 shrink-0 m-muted">{{ __('Phone') }}</dt><dd><a href="tel:{{ $b->phone }}" dir="ltr" class="font-semibold">{{ $b->phone }}</a></dd></div>@endif
                        </dl>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($maps[$b->id])<a href="{{ $maps[$b->id] }}" target="_blank" rel="noopener" class="btn-ghost min-h-10 px-3 text-sm" data-link="map">📍 {{ __('Location on the map') }}</a>@endif
                            @if ($wa && $branches->count() > 1)<a href="https://wa.me/{{ whatsapp_number($wa) }}" target="_blank" rel="noopener" class="btn-ghost min-h-10 px-3 text-sm">💬 {{ __('WhatsApp') }}</a>@endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if ($faq)
        {{-- the knowledge base's public answers (App\Services\KnowledgeBase), also in the FAQPage JSON-LD above --}}
        <section id="faq" class="scroll-mt-4" data-faq>
            <h2 class="mb-3 text-xl font-extrabold">{{ __('Frequently asked questions') }}</h2>
            <div class="space-y-2">
                @foreach ($faq as $entry)
                    <details class="faq-item group rounded-2xl m-surface shadow-sm" data-faq-item="{{ $entry['id'] }}">
                        <summary class="flex min-h-14 cursor-pointer list-none items-center gap-3 px-4 py-3 font-bold [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0 flex-1" dir="auto">{{ $entry['question'] }}</span>
                            <span class="m-muted shrink-0 text-lg transition-transform group-open:rotate-45" aria-hidden="true">＋</span>
                        </summary>
                        <div class="faq-answer px-4 pb-4 leading-relaxed" dir="auto">{{ \App\Services\KnowledgeBase::format($entry['answer']) }}</div>
                    </details>
                @endforeach
            </div>
        </section>
    @endif

    <footer class="space-y-3 pt-4 text-center text-sm m-muted">
        @include('menu.partials.powered-by')
        <a href="{{ platform_url(null, 'restaurants') }}" class="inline-flex items-center gap-1 text-xs">{{ __('More places on') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" /></a>
    </footer>
</main>
@endsection
