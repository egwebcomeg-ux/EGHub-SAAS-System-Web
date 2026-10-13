@extends('layouts.base')
@section('title', __('Orders') . ' · ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'bg-navy-900 text-white')

@section('body')
{{-- Screen in the hall: "Preparing" and "Ready" order numbers only. Polls every 5 seconds. --}}
<div class="flex min-h-dvh flex-col p-6">
    <header class="mb-6 flex items-center gap-4">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-14 rounded-2xl bg-white object-contain p-1">@endif
        <h1 class="text-3xl font-extrabold">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</h1>
    </header>
    <div class="grid flex-1 grid-cols-2 gap-6">
        <section class="rounded-3xl bg-white/5 p-6">
            <h2 class="mb-6 text-center text-3xl font-extrabold text-amber-400">{{ __('Preparing') }}</h2>
            <div class="flex flex-wrap justify-center gap-4 text-5xl font-extrabold" id="preparing"></div>
        </section>
        <section class="rounded-3xl bg-emerald-500/15 p-6">
            <h2 class="mb-6 text-center text-3xl font-extrabold text-emerald-400">{{ __('Ready — please collect') }}</h2>
            <div class="flex flex-wrap justify-center gap-4 text-6xl font-extrabold" id="ready"></div>
        </section>
    </div>
</div>
<script>
    (() => {
        let lastReady = [];
        const box = (n, extra) => `<span class="min-w-28 rounded-2xl bg-white/10 px-5 py-3 text-center ${extra}">${Number(n)}</span>`;
        async function poll() {
            try {
                const data = await (await fetch(@js(route('menu.board.feed', ['branch' => request('branch')])), { headers: { Accept: 'application/json' } })).json();
                document.getElementById('preparing').innerHTML = data.preparing.map((n) => box(n, '')).join('');
                document.getElementById('ready').innerHTML = data.ready.map((n) => box(n, lastReady.includes(n) ? '' : 'animate-pulse bg-emerald-500')).join('');
                lastReady = data.ready;
            } catch (e) {}
            setTimeout(poll, 5000);
        }
        poll();
    })();
</script>
@endsection
