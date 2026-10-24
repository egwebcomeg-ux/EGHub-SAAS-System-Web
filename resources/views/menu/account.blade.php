@extends('layouts.base')
@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $currency = tr($settings['currency'], $settings['currency_en']);
    $en = app()->getLocale() === 'en';
    $typeLabel = fn (string $type) => (\App\Models\Order::TYPES[$type] ?? [$type, $type])[$en ? 1 : 0];
    $selfWhatsapp = 'https://wa.me/' . whatsapp_number($customer->phone) . '?text=' . rawurlencode(__('My account at :place (orders, addresses, points): :url', ['place' => $brand, 'url' => $shareUrl]));
@endphp
@section('title', __('My account') . ' | ' . $brand)
@section('body_class', 'm-surface-2 m-text')

@push('head')
@include('menu.partials.theme')
<style>
    .acc-chip { display: inline-flex; align-items: center; min-height: 1.75rem; padding: 0 .65rem; border-radius: 999px; font-size: .75rem; font-weight: 800; background: var(--menu-surface-2); color: var(--menu-text); }
    .acc-chip-accent { background: color-mix(in srgb, var(--menu-accent) 14%, transparent); color: var(--menu-accent); }
    .acc-chip-done { background: rgba(16, 185, 129, .14); color: #047857; }
    .acc-chip-off { background: rgba(239, 68, 68, .12); color: #B91C1C; }
    .acc-hero { background: var(--menu-secondary); color: #fff; }
</style>
@endpush

@section('body')
<main class="mx-auto max-w-md pb-10" data-account>
    <section class="acc-hero rounded-b-[2rem] px-4 pb-6 pt-5 shadow-sm">
        <div class="flex items-center gap-3">
            @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 shrink-0 rounded-2xl bg-white object-contain p-1">@endif
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-bold text-white/80">{{ $brand }}</div>
                <h1 class="text-2xl font-black">{{ __('My account') }}</h1>
            </div>
            <a href="{{ route('menu.show') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-full bg-white/15 px-4 text-sm font-bold ring-1 ring-white/30" data-account-menu>{{ __('Menu') }}</a>
        </div>
        <div class="mt-4 rounded-2xl bg-white/10 p-4 ring-1 ring-white/20">
            <div class="text-lg font-extrabold" data-account-name>{{ $customer->name ?: __('Guest') }}</div>
            <div class="mt-0.5 text-sm text-white/80" dir="ltr" data-account-phone>{{ \App\Services\Customers\GuestAccount::maskedPhone($customer->phone) }}</div>
            <form method="post" action="{{ route('menu.account.sign-out', $customer->card_token) }}" class="mt-3">
                @csrf
                <button class="text-sm font-bold text-white underline underline-offset-4" data-account-sign-out>{{ __('Not me / sign out on this phone') }}</button>
            </form>
        </div>
    </section>

    <div class="space-y-4 px-4 pt-4">
        <x-flash />

        {{-- the link is the key: keep it, or send it to your own WhatsApp to open it on another phone --}}
        <section class="card" data-account-link>
            <h2 class="section-title">{{ __('Open your account on any phone') }}</h2>
            <p class="mt-1 text-sm m-muted">{{ __('Save this link or send it to yourself. Whoever has it can see your orders, so keep it to yourself.') }}</p>
            <div class="mt-3 flex gap-2">
                <input class="field min-w-0 flex-1 text-sm" value="{{ $shareUrl }}" readonly dir="ltr" aria-label="{{ __('Account link') }}" data-account-link-field>
                <button type="button" class="btn-ghost min-h-11 shrink-0 border px-3" data-copy="{{ $shareUrl }}" data-copied="{{ __('Copied ✓') }}">{{ __('Copy') }}</button>
            </div>
            <a href="{{ $selfWhatsapp }}" target="_blank" rel="noopener noreferrer" class="mt-2 flex min-h-12 items-center justify-center gap-2 rounded-xl bg-[#25D366] font-bold text-white" data-account-whatsapp>{{ __('Send it to myself on WhatsApp') }}</a>
        </section>

        @if ($card || $wallet !== null)
            <section class="card" data-account-loyalty>
                <h2 class="section-title">{{ __('Points and balance') }}</h2>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    @if ($card && $card['type'] === 'stamps')
                        <div class="rounded-2xl m-surface-2 p-3"><div class="text-xs font-bold m-muted">{{ __('Stamps') }}</div><div class="text-2xl font-black" dir="ltr">{{ $card['stamps'] }} / {{ $card['required'] }}</div></div>
                    @elseif ($card && $card['type'] === 'points')
                        <div class="rounded-2xl m-surface-2 p-3"><div class="text-xs font-bold m-muted">{{ __('Points') }}</div><div class="text-2xl font-black">{{ $card['points'] }}</div></div>
                    @endif
                    @if ($wallet !== null)
                        <div class="rounded-2xl m-surface-2 p-3" data-account-wallet><div class="text-xs font-bold m-muted">{{ __('Balance') }}</div><div class="text-2xl font-black">{{ money($wallet) }} <span class="text-sm">{{ $currency }}</span></div></div>
                    @endif
                    @if ($card && $card['tier'])
                        <div class="rounded-2xl m-surface-2 p-3"><div class="text-xs font-bold m-muted">{{ __('Membership') }}</div><div class="text-lg font-black">{{ $card['tier'] }}</div></div>
                    @endif
                </div>
                @foreach ($card['rewards'] ?? [] as $reward)
                    <div class="mt-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800">🎁 {{ $reward }}</div>
                @endforeach
                @if ($card)
                    <a href="{{ route('menu.card', $customer->card_token) }}" class="btn-ghost mt-3 w-full min-h-12 border" data-account-card-link>{{ __('Open my full card') }}</a>
                @endif
            </section>
        @endif

        <section data-account-orders>
            <h2 class="section-title mb-2 px-1">{{ __('My orders') }}</h2>
            @forelse ($orders as $order)
                <article class="card mb-3" data-account-order="{{ $order->number }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-lg font-black" dir="ltr">#{{ $order->number }}</div>
                            <div class="text-xs m-muted">{{ local_time($order->created_at, 'Y/m/d h:i A') }}</div>
                        </div>
                        <div class="flex flex-wrap justify-end gap-1">
                            <span class="acc-chip">{{ $typeLabel($order->type) }}</span>
                            <span @class(['acc-chip', 'acc-chip-done' => $order->status === 'served', 'acc-chip-off' => $order->status === 'cancelled', 'acc-chip-accent' => ! in_array($order->status, ['served', 'cancelled'], true)])>{{ $order->statusLabel() }}</span>
                        </div>
                    </div>
                    <p class="mt-2 text-sm leading-relaxed">{{ \App\Services\Customers\GuestAccount::itemsSummary($order) }}</p>
                    @if (\App\Services\Customers\GuestAccount::showsTotal($order))
                        <div class="mt-2 text-sm font-extrabold" data-account-total>{{ __('Total') }}: {{ money($order->total) }} {{ $currency }}</div>
                    @endif
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <a href="{{ route('menu.show', ['reorder' => $order->tracking_token]) }}" class="btn-primary min-h-11 text-sm" data-account-reorder>{{ __('Order again') }}</a>
                        <a href="{{ route('menu.order.show', $order->tracking_token) }}" class="btn-ghost min-h-11 border text-sm" data-account-track>{{ __('Order details') }}</a>
                    </div>
                </article>
            @empty
                <div class="card text-center text-sm m-muted" data-account-no-orders>{{ __('No orders yet. Your next order shows here.') }}</div>
            @endforelse
        </section>

        <section class="card" id="addresses" data-account-addresses>
            <h2 class="section-title">{{ __('My delivery addresses') }}</h2>
            @forelse ($addresses as $address)
                <div class="mt-3 flex items-start gap-3 rounded-2xl m-surface-2 p-3" data-account-address="{{ $address['key'] }}">
                    <span class="text-xl" aria-hidden="true">📍</span>
                    <div class="min-w-0 flex-1 text-sm">
                        @if ($address['label'])<div class="font-extrabold">{{ $address['label'] }}</div>@endif
                        @if ($address['zone'])<div class="font-bold">{{ $address['zone'] }}</div>@endif
                        <div class="m-muted">{{ $address['text'] }}</div>
                        @if ($address['lat'] !== null && $address['lng'] !== null)
                            <a href="https://www.google.com/maps?q={{ $address['lat'] }},{{ $address['lng'] }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex min-h-9 items-center font-semibold m-primary-text">{{ __('Pin on the map') }}</a>
                        @endif
                    </div>
                    <form method="post" action="{{ route('menu.account.address.delete', [$customer->card_token, $address['key']]) }}" onsubmit="return confirm(@js(__('Remove this address?')))">
                        @csrf
                        <button class="min-h-11 rounded-xl px-3 text-sm font-bold text-red-600" data-account-address-delete>{{ __('Remove') }}</button>
                    </form>
                </div>
            @empty
                <p class="mt-2 text-sm m-muted">{{ __('Addresses of your delivery orders show here.') }}</p>
            @endforelse
        </section>

        <a href="{{ route('menu.show') }}" class="btn-primary w-full min-h-12">{{ __('Open the menu') }}</a>
        @include('menu.partials.powered-by', ['class' => 'mt-4 m-muted'])
    </div>
</main>
<script>
    document.querySelectorAll('[data-copy]').forEach((button) => button.addEventListener('click', async () => {
        const label = button.textContent;
        try { await navigator.clipboard.writeText(button.dataset.copy); } catch {
            const field = document.querySelector('[data-account-link-field]');
            field?.select(); try { document.execCommand('copy'); } catch {}
        }
        button.textContent = button.dataset.copied;
        setTimeout(() => { button.textContent = label; }, 1800);
    }));
</script>
@endsection
