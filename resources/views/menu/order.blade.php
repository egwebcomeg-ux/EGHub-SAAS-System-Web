@extends('layouts.base')
@section('title', __('Order #:n', ['n' => $order->number]))

@push('head')
<style>:root { --menu-primary: {{ is_hex_color($settings['color_primary']) ? $settings['color_primary'] : '#E8642C' }}; }</style>
@include('menu.partials.pixels', ['purchase' => $order->status === 'cancelled' ? null : [
    'id' => $order->number, 'value' => round((float) $order->total, 2),
    'items' => $order->items->map(fn ($i) => ['id' => $i->menu_item_id, 'name' => $i->name, 'quantity' => $i->quantity, 'price' => round((float) $i->unit_price, 2)])->values()->all(),
]])
@endpush

@section('body')
@php
    $steps = $order->type === 'delivery' ? ['new', 'preparing', 'ready', 'on_way', 'served'] : ['new', 'preparing', 'ready', 'served'];
    $wa = preg_replace('/\D+/', '', (string) ($order->branch?->whatsapp ?: $settings['whatsapp']));
    $waText = collect([__('Order #:n', ['n' => $order->number])])
        ->merge($order->items->map(fn ($i) => $i->quantity . '× ' . $i->name))
        ->push(__('Total') . ': ' . money($order->total))
        ->push($order->delivery_address ? ($order->delivery_address['zone'] ?? '') . ' - ' . ($order->delivery_address['text'] ?? '') : null)
        ->push(route('menu.order.show', $order->tracking_token))
        ->filter()->implode("\n");
@endphp
<main class="mx-auto max-w-md px-4 py-8">
    <div class="card text-center">
        <div class="text-sm text-navy-500">{{ tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name }}</div>
        <h1 class="mt-1 text-3xl font-extrabold">{{ __('Order #:n', ['n' => $order->number]) }}</h1>
        @if ($order->table)<div class="mt-1 font-semibold" style="color: var(--menu-primary)">{{ __('Table :name', ['name' => $order->table->name]) }}</div>@endif
        @if (! $order->table)
            <div class="mt-1 font-semibold" style="color: var(--menu-primary)">{{ \App\Models\Order::TYPES[$order->type][app()->getLocale() === 'en' ? 1 : 0] ?? '' }}</div>
            @if ($order->delivery_address)<div class="text-sm text-navy-500">📍 {{ $order->delivery_address['zone'] ?? '' }} — {{ $order->delivery_address['text'] ?? '' }}</div>@endif
            @if ($order->scheduled_for)<div class="text-sm text-navy-500">🕒 {{ local_time($order->scheduled_for, 'd/m h:i A') }}</div>@endif
        @endif

        <div class="mt-6 text-lg font-bold" data-status-label>{{ $order->statusLabel() }}</div>
        @if ($order->status === 'cancelled' && ($why = $order->cancelReasonLabel()))
            <p class="mt-2 rounded-xl bg-red-50 p-3 text-sm text-red-800" data-cancel-reason>{{ $why }}</p>
        @endif
        @if ($order->status !== 'cancelled')
            <ol class="mt-4 flex items-center justify-between gap-1" data-steps>
                @foreach ($steps as $i => $s)
                    @php($done = array_search($order->status, $steps, true) >= $i)
                    <li class="flex flex-1 flex-col items-center gap-1 text-xs" data-step="{{ $s }}">
                        <span class="size-4 rounded-full {{ $done ? '' : 'bg-navy-100' }}" @if ($done) style="background: var(--menu-primary)" @endif></span>
                        {{ \App\Models\Order::STATUSES[$s][app()->getLocale() === 'en' ? 1 : 0] }}
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <div class="card mt-4">
        <ul class="divide-y divide-navy-50 text-sm">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-3 py-2">
                    <span><b>{{ $item->quantity }}×</b> {{ $item->name }}
                        @if ($item->options)<span class="block text-xs text-navy-500">{{ collect($item->options)->pluck('name')->implode('، ') }}</span>@endif
                        @if ($item->notes)<span class="block text-xs" style="color: var(--menu-primary)">{{ $item->notes }}</span>@endif
                    </span>
                    <span>{{ money($item->line_total) }}</span>
                </li>
            @endforeach
        </ul>
        <div class="mt-3 space-y-1 border-t border-navy-100 pt-3 text-sm">
            @if ($order->service > 0)<div class="flex justify-between"><span>{{ __('Service') }}</span><span>{{ money($order->service) }}</span></div>@endif
            @if ($order->tax > 0)<div class="flex justify-between"><span>{{ __('Tax') }}</span><span>{{ money($order->tax) }}</span></div>@endif
            @if ($order->delivery_fee > 0)<div class="flex justify-between"><span>{{ __('Delivery fee') }}</span><span>{{ money($order->delivery_fee) }}</span></div>@endif
            <div class="flex justify-between text-base font-extrabold"><span>{{ __('Total') }}</span><span>{{ money($order->total) }} {{ tr($settings['currency'], $settings['currency_en']) }}</span></div>
            @if ($order->payment_status === 'paid' && $order->payment_method === 'credit')
                <div class="mt-1 rounded-lg bg-emerald-50 px-3 py-2 text-center font-semibold text-emerald-800" data-paid-wallet>
                    {{ $order->status === 'cancelled' ? __('Cancelled: the amount went back to your balance.') : __('Paid from your balance') }}
                </div>
            @endif
        </div>
    </div>

    @if ($order->status === 'served' && \App\Services\Entitlements::current()->allows('feedback'))
        @include('partials.rate-us', ['orderToken' => $order->tracking_token])
    @endif

    @if (! $order->table && $wa && ! in_array($order->status, ['served', 'cancelled'], true))
        <a href="https://wa.me/{{ str_starts_with($wa, '0') ? '2' . $wa : $wa }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener"
           class="mt-4 flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#25D366] font-bold text-white">{{ __('Send the order on WhatsApp too') }}</a>
    @endif
    <a href="{{ route('menu.show') }}" class="btn-ghost mt-4 w-full">{{ __('Back to menu') }}</a>
</main>

@if (!in_array($order->status, ['served', 'cancelled'], true))
<script>
    // Follow the order without reloading: reload once the status changes.
    (function poll(status) {
        setTimeout(async () => {
            try {
                const res = await fetch(@js(route('menu.order.status', $order->tracking_token)), { headers: { Accept: 'application/json' } });
                const data = await res.json();
                if (data.status !== status) return location.reload();
            } catch (e) {}
            poll(status);
        }, 8000);
    })(@js($order->status));
</script>
@endif
@endsection
