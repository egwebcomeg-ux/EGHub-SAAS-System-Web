@extends('layouts.landing')
@section('title', __('Blog') . ' · EgMenu')
@section('description', __('Practical articles for cafe and restaurant owners in Egypt: menus, ordering, loyalty and running the place.'))

@section('page')
<section class="border-b border-navy-100 bg-[var(--lp-paper)]">
    <div class="mx-auto max-w-7xl px-4 pt-12 pb-10 sm:px-6 lg:pt-16">
        <p class="lp-eyebrow">{{ __('Blog') }}</p>
        <h1 class="mt-3 max-w-3xl text-[2rem] leading-[1.3] font-black tracking-tight sm:text-5xl sm:leading-[1.25]">{{ __('Notes from behind the counter') }}</h1>
        <p class="lp-lead mt-4 max-w-2xl">{{ __('Short, practical pieces on menus, ordering, loyalty and the daily running of a cafe or restaurant in Egypt. No fluff.') }}</p>
        <a href="{{ route('blog.rss') }}" class="mt-4 inline-flex min-h-11 items-center gap-2 text-sm font-bold text-brand-700 hover:text-brand-600" rel="alternate" type="application/rss+xml">
            <x-landing.icon name="zap" class="size-4" /> RSS
        </a>
    </div>
</section>

<section class="py-14 lg:py-16">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        @if ($posts->isEmpty())
            <p class="text-center text-navy-500">{{ __('The first articles are on their way.') }}</p>
        @else
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-posts>
                @foreach ($posts as $post)
                    <article class="group flex flex-col overflow-hidden rounded-2xl border border-navy-100 bg-white transition hover:-translate-y-0.5 hover:shadow-lg">
                        <a href="{{ route('blog.show', $post->slug) }}" class="block aspect-[16/9] overflow-hidden bg-navy-50">
                            @if ($post->cover)
                                <img src="/storage/{{ $post->cover }}" alt="" loading="{{ $loop->first ? 'eager' : 'lazy' }}" class="size-full object-cover transition group-hover:scale-[1.02]">
                            @else
                                <div class="grid size-full place-items-center bg-gradient-to-br from-navy-800 to-navy-600 text-5xl font-black text-white/15">eg</div>
                            @endif
                        </a>
                        <div class="flex flex-1 flex-col p-5">
                            <div class="flex items-center gap-2 text-xs text-navy-500">
                                <time datetime="{{ $post->published_at->toDateString() }}">{{ local_time($post->published_at, 'd/m/Y') }}</time>
                                <span aria-hidden="true">·</span>
                                <span>{{ __(':n min read', ['n' => $post->readingMinutes()]) }}</span>
                            </div>
                            <h2 class="mt-2 text-lg leading-snug font-extrabold"><a href="{{ route('blog.show', $post->slug) }}" class="hover:text-brand-700">{{ $post->displayTitle() }}</a></h2>
                            @if ($post->displayExcerpt())<p class="mt-2 flex-1 text-sm leading-relaxed text-navy-600">{{ $post->displayExcerpt() }}</p>@endif
                            <a href="{{ route('blog.show', $post->slug) }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-bold text-brand-700">{{ __('Read') }} <span aria-hidden="true" class="ms-1 inline-block rtl:rotate-180">→</span></a>
                        </div>
                    </article>
                @endforeach
            </div>
            <div class="mt-10">{{ $posts->links() }}</div>
        @endif
    </div>
</section>

<section class="border-t border-navy-100 bg-[var(--lp-paper)] py-14">
    <div class="mx-auto max-w-3xl px-4 text-center sm:px-6">
        <h2 class="lp-h2">{{ __('Reading is good. A live menu is better.') }}</h2>
        <p class="lp-lead mt-3">{{ __('Your QR menu is free, forever. Add ordering, loyalty and the rest when you need them.') }}</p>
        <a href="{{ route('register') }}" class="btn-primary mt-6 min-h-12 px-7 text-base">{{ __('Start free') }}</a>
    </div>
</section>
@endsection
