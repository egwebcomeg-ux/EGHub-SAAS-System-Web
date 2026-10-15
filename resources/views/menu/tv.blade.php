@extends('layouts.base')
@section('title', $screen->name . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'overflow-hidden cursor-none')

@php
    $color = fn ($k, $d) => is_hex_color($settings[$k]) ? $settings[$k] : $d;
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $currency = tr($settings['currency'], $settings['currency_en']);
    $dark = $screen->theme === 'dark';
    $cols = max(1, min(4, (int) $screen->columns));
@endphp

@section('body')
<style>
    :root { --tv-primary: {{ $color('color_primary', '#E8642C') }}; }
    body { background: {{ $dark ? '#0f1420' : '#ffffff' }}; color: {{ $dark ? '#f3f4f6' : '#1E2D4F' }}; }
    .tv-muted { color: {{ $dark ? '#9aa3b5' : '#64708a' }}; }
    .tv-card { background: {{ $dark ? 'rgba(255,255,255,.04)' : '#f6f7fa' }}; }
    .tv-price { color: var(--tv-primary); }
    [data-page] { transition: opacity .6s; }
    [data-page].tv-hidden { opacity: 0; position: absolute; inset: 0; pointer-events: none; }
</style>

{{-- Font size is shrunk by the script below until the whole page fits the screen without scrolling. --}}
<div class="flex h-dvh flex-col p-[2vh_2vw]" data-tv>
    <header class="mb-[2vh] flex items-center gap-4">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-[7vh] rounded-2xl bg-white object-contain p-1">@endif
        <h1 class="text-[4vh] font-extrabold">{{ $brand }}</h1>
        <div class="tv-muted ms-auto text-[2.4vh] font-semibold" data-clock></div>
    </header>

    <main class="relative min-h-0 flex-1" data-stage>
        @php
            $pages = $screen->layout === 'rotate' ? $categories->chunk($cols) : collect([$categories]);
        @endphp
        @foreach ($pages as $p => $pageCategories)
            <div @class(['h-full', 'tv-hidden' => $p > 0]) data-page>
                <div class="h-full gap-[2vw]" style="{{ $screen->layout === 'rotate' ? 'display:grid;align-items:start;grid-template-columns:repeat(' . $cols . ',minmax(0,1fr))' : 'column-count:' . $cols }}" data-fit>
                    @foreach ($pageCategories as $c)
                        <section class="tv-card mb-[2vh] break-inside-avoid rounded-3xl p-[1.6vh_1.4vw]">
                            <h2 class="tv-price mb-[1vh] text-[1.3em] font-extrabold">{{ tx($c, 'name') }}</h2>
                            <ul class="space-y-[0.9vh]">
                                @foreach ($c->items as $item)
                                    <li @class(['flex items-center gap-3', 'opacity-40' => $item->is_sold_out])>
                                        @if ($screen->show_images && $item->image)
                                            <img src="/storage/{{ $item->image }}" alt="" class="size-[3.2em] shrink-0 rounded-xl object-cover">
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <div class="font-bold leading-tight">{{ $item->displayName() }}</div>
                                            @if (tx($item, 'description'))
                                                <div class="tv-muted truncate text-[.7em]">{{ tx($item, 'description') }}</div>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 items-baseline gap-2">
                                            @if ($item->is_sold_out)
                                                <span class="text-[.8em] font-bold">{{ __('Sold out') }}</span>
                                            @else
                                                @if ($item->getAttribute('offer_from'))
                                                    <span class="tv-muted text-[.7em] line-through">{{ money($item->getAttribute('offer_from')) }}</span>
                                                @endif
                                                <span class="tv-price font-extrabold">{{ money($item->price) }}</span>
                                                <span class="tv-muted text-[.6em]">{{ $currency }}</span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
            </div>
        @endforeach
    </main>
</div>

<script>
    (() => {
        const stage = document.querySelector('[data-stage]');
        const pages = [...document.querySelectorAll('[data-page]')];

        // Biggest font (in vh) that lets every page fit inside the stage.
        function fit() {
            pages.forEach((page) => {
                const hidden = page.classList.contains('tv-hidden');
                page.classList.remove('tv-hidden');
                const box = page.querySelector('[data-fit]');
                let size = 5;
                box.style.fontSize = size + 'vh';
                const overflows = () => box.scrollHeight > stage.clientHeight + 2 || box.scrollWidth > stage.clientWidth + 2;
                while (overflows() && size > 1.2) {
                    size -= 0.1;
                    box.style.fontSize = size.toFixed(2) + 'vh';
                }
                if (hidden) page.classList.add('tv-hidden');
            });
        }
        fit();
        addEventListener('resize', fit);

        if (pages.length > 1) {
            let current = 0;
            setInterval(() => {
                pages[current].classList.add('tv-hidden');
                current = (current + 1) % pages.length;
                pages[current].classList.remove('tv-hidden');
            }, {{ max(5, (int) $screen->rotate_seconds) }} * 1000);
        }

        const clock = document.querySelector('[data-clock]');
        const tick = () => (clock.textContent = new Date().toLocaleTimeString(document.documentElement.lang === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', { hour: '2-digit', minute: '2-digit' }));
        tick();
        setInterval(tick, 30000);

        // Reload only when what the screen shows has changed. A failed check (Wi-Fi drop) keeps the current board.
        const version = @js($version);
        setInterval(async () => {
            try {
                const res = await fetch(@js(route('menu.tv.version', ['token' => $screen->token])), { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (res.ok && (await res.json()).v !== version) location.reload();
            } catch (e) {}
        }, 60000);
    })();
</script>
@endsection
