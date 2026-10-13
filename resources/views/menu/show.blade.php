@extends('layouts.base')
@section('title', tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name)
@section('body_class', 'text-navy-900')

@push('head')
<style>
    :root { --menu-primary: {{ is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#E8642C' }};
            --menu-secondary: {{ is_hex_color($settings['color_secondary']) ? $settings['color_secondary'] : '#1E2D4F' }};
            --menu-bg: {{ is_hex_color($settings['color_bg']) ? $settings['color_bg'] : '#FFFFFF' }}; }
    body { background: var(--menu-bg); }
</style>
@endpush

@section('body')
@php($currency = tr($settings['currency'], $settings['currency_en']))
<header class="text-white" style="background: var(--menu-secondary)">
    <div class="mx-auto flex max-w-2xl items-center justify-between gap-3 px-4 py-5">
        <div>
            <h1 class="text-2xl font-extrabold">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
            @if ($t = tr($settings['tagline'], $settings['tagline_en']))<p class="text-sm text-white/75">{{ $t }}</p>@endif
        </div>
        @if ($settings['english_enabled'] === '1')
            <a href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}" class="rounded-full border border-white/30 px-3 py-1.5 text-sm font-semibold">{{ app()->getLocale() === 'ar' ? 'EN' : 'ع' }}</a>
        @endif
    </div>
    @if ($table)
        <div class="mx-auto max-w-2xl px-4 pb-4">
            <span class="rounded-full px-3 py-1 text-sm font-bold text-white" style="background: var(--menu-primary)">{{ __('Table :name', ['name' => $table->name]) }}</span>
        </div>
    @endif
</header>

@if ($categories->isNotEmpty())
    <nav class="sticky top-0 z-10 border-b border-black/5 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-2xl gap-2 overflow-x-auto px-4 py-3">
            @foreach ($categories as $c)
                <a href="#cat-{{ $c->id }}" class="shrink-0 rounded-full bg-navy-50 px-4 py-2 text-sm font-semibold">{{ tr($c->name, $c->name_en) }}</a>
            @endforeach
        </div>
    </nav>
@endif

<main class="mx-auto max-w-2xl px-4 py-6">
    @forelse ($categories as $c)
        <section id="cat-{{ $c->id }}" class="mb-8 scroll-mt-20">
            <h2 class="mb-3 text-xl font-extrabold" style="color: var(--menu-secondary)">{{ tr($c->name, $c->name_en) }}</h2>
            <div class="divide-y divide-black/5 rounded-2xl border border-black/5 bg-white">
                @foreach ($c->items as $item)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 {{ $item->is_sold_out ? 'opacity-50' : '' }}">
                        <div class="min-w-0">
                            <div class="font-bold">{{ $item->displayName() }}</div>
                            @if ($d = tr($item->description, $item->description_en))<div class="text-sm text-navy-500">{{ $d }}</div>@endif
                            @if ($item->is_sold_out)<div class="text-xs font-bold text-red-600">{{ __('Sold out') }}</div>@endif
                        </div>
                        <div class="shrink-0 font-extrabold" style="color: var(--menu-primary)">{{ money($item->price) }} {{ $currency }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <p class="py-16 text-center text-navy-500">{{ __('The menu is being prepared. Please check back soon.') }}</p>
    @endforelse
</main>

@unless (\App\Services\Entitlements::current()->allows('hide_badge'))
    <footer class="pb-8 text-center text-xs text-navy-500">
        <a href="{{ platform_url(null) }}" class="inline-flex items-center gap-1">{{ __('Powered by') }} <x-logo :size="14" class="!gap-1 [&_span]:text-xs" /></a>
    </footer>
@endunless
@endsection
