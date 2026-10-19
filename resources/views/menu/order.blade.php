@extends('layouts.base')
@section('title', __('Order #:n', ['n' => $order->number]))

@push('head')
@include('menu.partials.theme')
@include('menu.partials.pixels', ['purchase' => $order->status === 'cancelled' ? null : [
    'id' => $order->number, 'value' => round((float) $order->total, 2),
    'items' => $order->items->map(fn ($i) => ['id' => $i->menu_item_id, 'name' => $i->name, 'quantity' => $i->quantity, 'price' => round((float) $i->unit_price, 2)])->values()->all(),
]])
<style>
    .trk-number { font-size: 3.5rem; line-height: 1; font-weight: 900; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .trk-chip { display: inline-flex; align-items: center; gap: .35rem; min-height: 2rem; padding: .25rem .75rem; border-radius: 9999px; font-size: .8125rem; font-weight: 700; background: var(--menu-surface-2); color: var(--menu-text); }
    .trk-chip-accent { background: color-mix(in srgb, var(--menu-accent) 14%, transparent); color: var(--menu-accent); }
    .trk-icon { display: grid; place-items: center; width: 4rem; height: 4rem; margin-inline: auto; border-radius: 9999px; background: color-mix(in srgb, var(--menu-accent) 14%, transparent); color: var(--menu-accent); }
    .trk-icon svg { width: 2rem; height: 2rem; }
    .trk-icon.is-live { animation: trk-pulse 2.2s ease-out infinite; }
    .trk-icon.is-cancelled { background: rgba(239, 68, 68, .14); color: #DC2626; }
    .trk-steps { position: relative; margin-top: 1.25rem; }
    .trk-step { position: relative; display: flex; align-items: center; gap: .75rem; min-height: 2.75rem; padding-bottom: .5rem; }
    .trk-step:not(:last-child)::after { content: ''; position: absolute; inset-inline-start: .875rem; top: 2.125rem; bottom: -.125rem; width: 2px; margin-inline-start: -1px; border-radius: 2px; background: var(--menu-border); transition: background-color .4s ease; }
    .trk-step.is-done:not(.is-current)::after { background: var(--menu-accent); }
    .trk-dot { position: relative; z-index: 1; display: grid; place-items: center; flex: none; width: 1.75rem; height: 1.75rem; border-radius: 9999px; border: 2px solid var(--menu-border); background: var(--menu-surface); color: transparent; transition: background-color .4s ease, border-color .4s ease, color .4s ease; }
    .trk-dot svg { width: 1rem; height: 1rem; }
    .trk-step.is-done .trk-dot { background: var(--menu-accent); border-color: var(--menu-accent); color: var(--menu-accent-text); }
    .trk-step.is-current .trk-dot { background: var(--menu-surface); color: var(--menu-accent); animation: trk-pulse 2.2s ease-out infinite; }
    .trk-step.is-current .trk-dot svg { display: none; }
    .trk-step.is-current .trk-dot::before { content: ''; width: .625rem; height: .625rem; border-radius: 9999px; background: var(--menu-accent); }
    .trk-step-label { flex: 1; font-weight: 600; color: var(--menu-muted); }
    .trk-step.is-done .trk-step-label { color: var(--menu-text); }
    .trk-step.is-current .trk-step-label { font-weight: 800; color: var(--menu-text); }
    .trk-step-time { font-size: .8125rem; color: var(--menu-muted); font-variant-numeric: tabular-nums; }
    .trk-part { display: inline-flex; align-items: center; gap: .4rem; min-height: 2.25rem; padding: .25rem .85rem; border-radius: 9999px; font-size: .875rem; font-weight: 700; background: var(--menu-surface-2); color: var(--menu-muted); transition: background-color .4s ease, color .4s ease; }
    .trk-part.is-ready { background: color-mix(in srgb, var(--menu-accent) 16%, transparent); color: var(--menu-accent); }
    .trk-live { display: inline-flex; align-items: center; gap: .4rem; font-size: .75rem; color: var(--menu-muted); }
    .trk-live::before { content: ''; width: .5rem; height: .5rem; border-radius: 9999px; background: #10B981; animation: trk-blink 2s ease-in-out infinite; }
    .trk-pop { animation: trk-pop .5s ease-out; }
    .trk-action { display: flex; align-items: center; justify-content: center; gap: .5rem; min-height: 3rem; border-radius: .875rem; font-weight: 700; }
    .trk-action svg { width: 1.25rem; height: 1.25rem; }
    @keyframes trk-pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--menu-accent) 45%, transparent); } 70%, 100% { box-shadow: 0 0 0 .75rem transparent; } }
    @keyframes trk-blink { 50% { opacity: .35; } }
    @keyframes trk-pop { from { opacity: .3; transform: scale(.96); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
        .trk-icon.is-live, .trk-step.is-current .trk-dot, .trk-live::before, .trk-pop { animation: none; }
        .trk-step::after, .trk-dot, .trk-part { transition: none; }
    }
</style>
@endpush

@section('body')
@php
    $icon = fn (string $name) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . match ($name) {
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'new' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'preparing' => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
        'ready' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'on_way' => '<circle cx="18.5" cy="17.5" r="3.5"/><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="M12 17.5V14l-3-3 4-3 2 3h2"/>',
        'served' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'cancelled' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'chat' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'again' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        default => '',
    } . '</svg>';
    $en = app()->getLocale() === 'en';
    $final = $tracking['final'];
    $cancelled = $tracking['cancelled'];
    $branch = $order->branch;
    $phone = preg_replace('/[^\d+]+/', '', (string) ($branch?->phone ?: $settings['phone']));
    $wa = preg_replace('/\D+/', '', (string) ($branch?->whatsapp ?: $settings['whatsapp']));
    $waText = $final
        ? __('Order #:n', ['n' => $order->number]) . "\n" . route('menu.order.show', $order->tracking_token)
        : collect([__('Order #:n', ['n' => $order->number])])
            ->merge($order->items->map(fn ($i) => $i->quantity . '× ' . $i->name))
            ->push(__('Total') . ': ' . money($order->total))
            ->push($order->delivery_address ? ($order->delivery_address['zone'] ?? '') . ' - ' . ($order->delivery_address['text'] ?? '') : null)
            ->push(route('menu.order.show', $order->tracking_token))
            ->filter()->implode("\n");
    $showContact = $order->type !== 'dine_in' && ($phone || $wa);
    $otherDiscount = round($order->discount - $order->coupon_discount - $order->goal_discount - (float) $order->member_discount, 2);
    $manyBranches = $branch && \App\Models\Branch::query()->where('is_active', true)->count() > 1;
    $currency = tr($settings['currency'], $settings['currency_en']);
@endphp
<main class="mx-auto max-w-md px-4 pb-10 pt-6" data-tracking data-status="{{ $order->status }}">
    <header class="text-center">
        <div class="text-sm font-semibold m-muted">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</div>
        <div class="mt-4 text-xs font-bold m-muted">{{ __('Order number') }}</div>
        <h1 class="trk-number mt-1" dir="ltr" aria-label="{{ __('Order #:n', ['n' => $order->number]) }}">#{{ $order->number }}</h1>
        <div class="mt-3 flex flex-wrap justify-center gap-2">
            <span class="trk-chip trk-chip-accent">{{ \App\Models\Order::TYPES[$order->type][$en ? 1 : 0] ?? '' }}</span>
            @if ($order->table)<span class="trk-chip">{{ __('Table :name', ['name' => $order->table->name]) }}</span>@endif
            @if ($manyBranches)<span class="trk-chip">{{ tr($branch->name, $branch->name_en) }}</span>@endif
        </div>
    </header>

    <div class="card mt-5 text-center" aria-live="polite" data-hero>
        @if ($cancelled)
            <div class="trk-icon is-cancelled">{!! $icon('cancelled') !!}</div>
            <h2 class="mt-3 text-xl font-extrabold" data-headline>{{ $tracking['headline'] }}</h2>
            @if ($why = $order->cancelReasonLabel())
                <p class="mt-3 rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700" data-cancel-reason>{{ $why }}</p>
            @endif
        @else
            <div class="trk-icon {{ $final ? '' : 'is-live' }}" data-hero-icon>
                @foreach (\App\Support\OrderTracking::stepKeys($order->type) as $key)
                    <span class="contents" data-icon="{{ $key }}" @if ($key !== $order->status) hidden @endif>{!! $icon($key) !!}</span>
                @endforeach
            </div>
            <h2 class="mt-3 text-xl font-extrabold" data-headline>{{ $tracking['headline'] }}</h2>
            <div class="mt-3 rounded-2xl m-surface-2 px-4 py-3" data-eta @if (! $tracking['eta']) hidden @endif>
                <div class="text-lg font-extrabold" data-eta-text>{{ $tracking['eta']['text'] ?? '' }}</div>
                <div class="mt-0.5 text-sm m-muted" data-countdown @if (! ($tracking['eta']['minutes'] ?? null)) hidden @endif>{{ \App\Support\OrderTracking::minutesLeftText($tracking['eta']['minutes'] ?? 0) }}</div>
            </div>

            <ol class="trk-steps text-start" data-steps>
                @foreach ($tracking['steps'] as $step)
                    <li class="trk-step {{ $step['done'] ? 'is-done' : '' }} {{ $step['current'] ? 'is-current' : '' }}" data-step="{{ $step['key'] }}" @if ($step['current']) aria-current="step" @endif>
                        <span class="trk-dot">{!! $icon('check') !!}</span>
                        <span class="trk-step-label">{{ $step['label'] }}</span>
                        <span class="trk-step-time" dir="ltr" data-step-time>{{ $step['time'] }}</span>
                    </li>
                @endforeach
            </ol>

            @if ($tracking['parts'])
                <div class="mt-2 flex flex-wrap justify-center gap-2" data-parts>
                    @foreach ($tracking['parts'] as $part)
                        <span class="trk-part {{ $part['ready'] ? 'is-ready' : '' }}" data-part="{{ $part['kind'] }}">
                            {{ $part['label'] }} · <span data-part-state>{{ $part['ready'] ? __('Ready') : __('Preparing') }}</span>
                        </span>
                    @endforeach
                </div>
            @endif

            @unless ($final)
                <div class="mt-4"><span class="trk-live" data-live>{{ __('Updates by itself') }}</span></div>
            @endunless
        @endif
    </div>

    @if ($order->status === 'served' && \App\Services\Entitlements::current()->allows('feedback'))
        @include('partials.rate-us', ['orderToken' => $order->tracking_token])
    @endif

    @if ($order->delivery_address || ($order->type === 'takeaway' && $branch?->address))
        <div class="card mt-4 flex items-start gap-3" data-place>
            <span class="mt-0.5 size-5 shrink-0 m-primary-text">{!! $icon('pin') !!}</span>
            <div class="min-w-0 text-sm">
                @if ($order->delivery_address)
                    <div class="font-bold">{{ __('Delivering to') }}</div>
                    <div class="m-muted" data-address>{{ $order->delivery_address['zone'] ?? '' }} — {{ $order->delivery_address['text'] ?? '' }}</div>
                @else
                    <div class="font-bold">{{ __('Pick up from') }}</div>
                    <div class="m-muted">{{ $branch->address }}</div>
                    @if ($branch->maps_url)<a href="{{ $branch->maps_url }}" target="_blank" rel="noopener" class="mt-1 inline-flex min-h-11 items-center font-semibold m-primary-text">{{ __('Open in maps') }}</a>@endif
                @endif
            </div>
        </div>
    @endif

    <div class="card mt-4">
        <h2 class="text-base font-extrabold">{{ __('Your order') }}</h2>
        <ul class="mt-2 divide-y m-divide text-sm">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-3 py-2.5">
                    <span><b>{{ $item->quantity }}×</b> {{ $item->name }}
                        @if ($item->options)<span class="block text-xs m-muted">{{ collect($item->options)->pluck('name')->implode('، ') }}</span>@endif
                        @if ($item->notes)<span class="block text-xs m-primary-text">{{ $item->notes }}</span>@endif
                    </span>
                    <span class="shrink-0 tabular-nums">{{ money($item->line_total) }}</span>
                </li>
            @endforeach
        </ul>
        @if ($order->notes)
            <p class="mt-2 rounded-xl m-surface-2 px-3 py-2 text-sm"><b>{{ __('Notes') }}:</b> {{ $order->notes }}</p>
        @endif
        <div class="mt-3 space-y-1 border-t m-border pt-3 text-sm" data-totals>
            @if ($order->subtotal != $order->total)
                <div class="flex justify-between"><span>{{ __('Subtotal') }}</span><span>{{ money($order->subtotal) }}</span></div>
            @endif
            @if ($order->coupon_discount > 0)
                <div class="flex justify-between m-primary-text" data-coupon><span>{{ __('Coupon :code', ['code' => $order->coupon_code]) }}</span><span dir="ltr">−{{ money($order->coupon_discount) }}</span></div>
            @endif
            @if ($order->goal_reward)
                <div class="flex justify-between gap-3 font-semibold m-primary-text" data-goal-reward><span>🎁 {{ $order->goal_reward }}</span>@if ($order->goal_discount > 0)<span dir="ltr">−{{ money($order->goal_discount) }}</span>@endif</div>
            @endif
            @if ($order->member_discount > 0)
                <div class="flex justify-between m-primary-text"><span>{{ __('Member discount (:tier)', ['tier' => $order->member_tier]) }}</span><span dir="ltr">−{{ money($order->member_discount) }}</span></div>
            @endif
            @if ($otherDiscount > 0)
                <div class="flex justify-between m-primary-text"><span>{{ __('Discount') }}</span><span dir="ltr">−{{ money($otherDiscount) }}</span></div>
            @endif
            @if ($order->service > 0)<div class="flex justify-between"><span>{{ __('Service') }}</span><span>{{ money($order->service) }}</span></div>@endif
            @if ($order->tax > 0)<div class="flex justify-between"><span>{{ __('Tax') }}</span><span>{{ money($order->tax) }}</span></div>@endif
            @if ($order->delivery_fee > 0)<div class="flex justify-between" data-delivery-fee><span>{{ __('Delivery fee') }}</span><span>{{ money($order->delivery_fee) }}</span></div>@endif
            <div class="flex justify-between pt-1 text-base font-extrabold"><span>{{ __('Total') }}</span><span>{{ money($order->total) }} {{ $currency }}</span></div>
            @if ($order->payment_status === 'paid' && $order->payment_method === 'credit')
                <div class="mt-1 rounded-lg bg-emerald-50 px-3 py-2 text-center font-semibold text-emerald-800" data-paid-wallet>
                    {{ $cancelled ? __('Cancelled: the amount went back to your balance.') : __('Paid from your balance') }}
                </div>
            @endif
        </div>
    </div>

    @if ($showContact)
        <div class="mt-4" data-contact>
            <p class="mb-2 text-center text-sm font-bold m-muted">{{ __('Need to reach the restaurant?') }}</p>
            <div class="grid gap-2 {{ $phone && $wa ? 'grid-cols-2' : '' }}">
                @if ($phone)
                    <a href="tel:{{ $phone }}" class="trk-action btn-ghost border" data-call>{!! $icon('phone') !!} {{ __('Call') }}</a>
                @endif
                @if ($wa)
                    <a href="https://wa.me/{{ str_starts_with($wa, '0') ? '2' . $wa : $wa }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener"
                       class="trk-action bg-[#25D366] text-white" data-whatsapp>{!! $icon('chat') !!} {{ __('WhatsApp') }}</a>
                @endif
            </div>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-2 gap-2">
        <a href="{{ route('menu.show', ['reorder' => $order->tracking_token]) }}" class="trk-action btn-primary" data-reorder>{!! $icon('again') !!} {{ __('Order again') }}</a>
        <a href="{{ route('menu.show') }}" class="trk-action btn-ghost border">{{ __('Back to menu') }}</a>
    </div>
</main>

@unless ($final)
<script>
    // Follow the order live: redraw the steps, parts and expected time from the status feed. Polls every 8 s while
    // something changes, slows down to 30 s while nothing does, pauses while the tab is hidden and stops at the end
    // (served / cancelled reload the page once for the rating card or the cancel reason).
    (() => {
        const root = document.querySelector('[data-tracking]');
        const url = @js(route('menu.order.status', $order->tracking_token));
        const t = @js(\App\Support\OrderTracking::scriptTexts());
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let state = @js($tracking);
        let offset = Date.parse(state.now) - Date.now();
        let delay = 8000;
        let timer = null;

        const pop = (el) => {
            if (!el || reduce) return;
            el.classList.remove('trk-pop');
            void el.offsetWidth;
            el.classList.add('trk-pop');
        };
        const minutesText = (n) => n === 1 ? t.one : n === 2 ? t.two : (n <= 10 ? t.few : t.many).replace(':n', n);

        function renderEta(eta) {
            const box = root.querySelector('[data-eta]');
            if (!box) return;
            box.hidden = !eta;
            if (!eta) return;
            const left = Math.ceil((Date.parse(eta.at) - (Date.now() + offset)) / 60000);
            const text = box.querySelector('[data-eta-text]');
            const next = left <= 0 && eta.kind !== 'scheduled' ? t.soon : eta.text;
            if (text.textContent !== next) { text.textContent = next; pop(text); }
            const count = box.querySelector('[data-countdown]');
            count.hidden = !(left > 0 && left <= 120);
            if (!count.hidden) count.textContent = minutesText(left);
        }

        function render(d) {
            if (d.final) return location.reload();
            root.dataset.status = d.status;
            const head = root.querySelector('[data-headline]');
            if (head.textContent !== d.headline) { head.textContent = d.headline; pop(head); }
            root.querySelectorAll('[data-icon]').forEach((i) => { i.hidden = i.dataset.icon !== d.status; });
            if (state.status !== d.status) pop(root.querySelector('[data-hero-icon]'));
            d.steps.forEach((s) => {
                const li = root.querySelector(`[data-step="${s.key}"]`);
                if (!li) return;
                const wasDone = li.classList.contains('is-done');
                li.classList.toggle('is-done', s.done);
                li.classList.toggle('is-current', s.current);
                s.current ? li.setAttribute('aria-current', 'step') : li.removeAttribute('aria-current');
                li.querySelector('[data-step-time]').textContent = s.time || '';
                if (s.done && !wasDone) pop(li);
            });
            const partsBox = root.querySelector('[data-parts]');
            if (partsBox) partsBox.hidden = !d.parts.length; // once the whole order is ready one step says it all
            d.parts.forEach((p) => {
                const el = root.querySelector(`[data-part="${p.kind}"]`);
                if (!el || el.classList.contains('is-ready') === p.ready) return;
                el.classList.toggle('is-ready', p.ready);
                el.querySelector('[data-part-state]').textContent = p.ready ? t.ready : t.preparing;
                pop(el);
            });
            renderEta(d.eta);
            state = d;
        }

        const signature = (d) => JSON.stringify([d.status, d.steps, d.parts, d.eta && d.eta.at, d.headline]);

        async function tick() {
            timer = null;
            if (document.hidden) return; // resumes on visibilitychange
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (res.status === 404) return;
                if (!res.ok) throw new Error(res.status);
                const d = await res.json();
                offset = Date.parse(d.now) - Date.now();
                if (signature(d) !== signature(state)) { render(d); delay = 8000; } else { delay = Math.min(delay * 1.25, 30000); }
            } catch (e) {
                delay = Math.min(delay * 2, 60000);
            }
            if (!state.final) schedule();
        }
        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(tick, delay);
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) return;
            delay = 8000;
            clearTimeout(timer);
            tick();
        });
        setInterval(() => { if (!document.hidden) renderEta(state.eta); }, 30000);
        renderEta(state.eta);
        schedule();
    })();
</script>
@endunless
@endsection
