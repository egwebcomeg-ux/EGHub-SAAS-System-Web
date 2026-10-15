{{-- Extra languages on the menu page: "Translate with AI" and the progress of a running translation (polled). --}}
@php
    $running = \App\Jobs\TranslateMenu::isRunning();
    $percent = $translation && $translation['total'] > 0 ? (int) floor($translation['done'] * 100 / $translation['total']) : 0;
@endphp
<section class="card mb-4 space-y-3" id="ai-translate" data-translate data-status-url="{{ route('dash.menu.translate.status') }}" @if ($running) data-running @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="font-bold">{{ __('Extra languages') }}:
                <span class="font-semibold text-navy-700">{{ collect($extraLocales)->map(fn ($c) => \App\Support\MenuLocales::native($c))->implode(' · ') }}</span>
            </h2>
            <p class="text-sm text-navy-500">{{ __('AI fills only the empty translations with your own key; what you wrote by hand stays as it is.') }}</p>
        </div>
        @if ($aiReady)
            <form method="post" action="{{ route('dash.menu.translate') }}">
                @csrf
                <button class="btn-primary" @disabled($running) data-translate-button>✨ {{ __('Translate with AI') }}</button>
            </form>
        @else
            <a href="{{ route('dash.settings') }}#ai" class="btn-ghost">{{ __('Add your AI key in Settings') }}</a>
        @endif
    </div>
    @error('translate')<p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror

    <div @class(['space-y-1', 'hidden' => ! $running]) data-translate-progress role="status" aria-live="polite">
        <div class="flex justify-between text-sm">
            <span class="font-semibold">{{ __('Translating the menu…') }}</span>
            <span class="text-navy-500" dir="ltr" data-translate-count>{{ $translation['done'] ?? 0 }} / {{ $translation['total'] ?? 0 }}</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-navy-50"><div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $percent }}%" data-translate-bar></div></div>
    </div>
    @if ($translation && $translation['state'] === 'done')
        <p class="text-sm text-emerald-700" data-translate-done>✓ {{ __('Last translation finished: :n rows filled.', ['n' => $translation['filled']]) }}</p>
    @elseif ($translation && $translation['state'] === 'failed')
        <p class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ __('The last translation stopped: :error', ['error' => $translation['error']]) }}</p>
    @endif
</section>

<script>
    (() => {
        const box = document.querySelector('[data-translate][data-running]');
        if (!box) return;
        const bar = box.querySelector('[data-translate-bar]');
        const count = box.querySelector('[data-translate-count]');
        const tick = async () => {
            try {
                const s = await (await fetch(box.dataset.statusUrl, { headers: { Accept: 'application/json' } })).json();
                if (s.state === 'queued' || s.state === 'running') {
                    count.textContent = `${s.done} / ${s.total}`;
                    bar.style.width = (s.total ? Math.floor((s.done * 100) / s.total) : 0) + '%';
                    setTimeout(tick, 3000);
                } else {
                    location.reload();
                }
            } catch {
                setTimeout(tick, 6000);
            }
        };
        setTimeout(tick, 2000);
    })();
</script>
