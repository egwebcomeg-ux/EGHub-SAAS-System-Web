@extends('layouts.landing')
@section('title', $post->displayTitle() . ' · EgHub')
@section('description', $post->displayDescription())

@php
    $url = route('blog.show', $post->slug);
    $cover = $post->cover ? url('/storage/' . $post->cover) : null;
    $jsonLd = [
        '@context' => 'https://schema.org', '@type' => 'BlogPosting',
        'headline' => $post->displayTitle(), 'description' => $post->displayDescription(),
        'datePublished' => $post->published_at->toAtomString(), 'dateModified' => $post->updated_at->toAtomString(),
        'mainEntityOfPage' => $url, 'inLanguage' => $post->isArabicBody() ? 'ar' : 'en',
        'author' => ['@type' => 'Organization', 'name' => 'EgHub'],
        'publisher' => ['@type' => 'Organization', 'name' => 'EgHub', 'logo' => ['@type' => 'ImageObject', 'url' => url('/icons/egmenu-512.png')]],
    ] + ($cover ? ['image' => [$cover]] : []);
@endphp

@section('canonical', $url)
@section('og_type', 'article')
@if ($cover)
    @section('og_image', $cover)
@endif

@push('head')
<meta property="article:published_time" content="{{ $post->published_at->toAtomString() }}">
<script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('page')
<article class="pb-14" data-post>
    <header class="border-b border-navy-100 bg-[var(--lp-paper)]">
        <div class="mx-auto max-w-3xl px-4 pt-10 pb-8 sm:px-6 lg:pt-14">
            <nav class="text-sm text-navy-500"><a href="{{ route('blog') }}" class="hover:text-navy-900">{{ __('Blog') }}</a></nav>
            <h1 class="mt-3 text-[1.9rem] leading-[1.3] font-black tracking-tight sm:text-4xl sm:leading-[1.25]">{{ $post->displayTitle() }}</h1>
            <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-navy-500">
                <time datetime="{{ $post->published_at->toDateString() }}">{{ local_time($post->published_at, 'd/m/Y') }}</time>
                <span aria-hidden="true">·</span>
                <span>{{ __(':n min read', ['n' => $post->readingMinutes()]) }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ __('EgHub team') }}</span>
            </div>
        </div>
    </header>

    @if ($cover)
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            <img src="{{ $cover }}" alt="" class="-mt-6 aspect-[16/9] w-full rounded-2xl object-cover shadow-lg sm:-mt-8">
        </div>
    @endif

    <div class="mx-auto mt-10 max-w-3xl px-4 sm:px-6">
        <div class="lp-prose text-[1.05rem] leading-[1.9] text-navy-800" @if (! $post->isArabicBody()) dir="ltr" lang="en" @endif>
            {!! $post->html() !!}
        </div>

        <div class="mt-12 rounded-2xl bg-navy-900 p-6 text-white sm:p-8">
            <h2 class="text-xl font-extrabold">{{ __('Put it to work on your own menu') }}</h2>
            <p class="mt-2 text-white/75">{{ __('EgHub gives you a free QR menu in minutes; ordering, loyalty and the rest are there when you need them.') }}</p>
            <a href="{{ route('register') }}" class="btn-primary mt-5 min-h-12 px-6">{{ __('Start free') }}</a>
        </div>
    </div>
</article>

@if ($others->isNotEmpty())
    <section class="border-t border-navy-100 bg-[var(--lp-paper)] py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <h2 class="text-xl font-black">{{ __('More to read') }}</h2>
            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                @foreach ($others as $o)
                    <a href="{{ route('blog.show', $o->slug) }}" class="rounded-2xl border border-navy-100 bg-white p-5 transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="text-xs text-navy-500">{{ local_time($o->published_at, 'd/m/Y') }}</div>
                        <div class="mt-1 font-extrabold leading-snug">{{ $o->displayTitle() }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif
@endsection
