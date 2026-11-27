@extends('layouts.dashboard')
@section('title', __('Guest follow-up'))

@section('content')
@php
    use App\Models\Order;
    use App\Services\Orders\FollowUp;
    $statusFilters = ['open' => __('Still open'), 'served' => __('Delivered'), 'cancelled' => __('Cancelled')];
@endphp
<x-dash.page-header :title="__('Guest follow-up')" :help="__('Every order of the day with a mobile, from every source. «WhatsApp Web» opens the chat of the guest with a message ready for where the order is now; you only press send.')">
    <x-slot:actions>
        @can('callcenter.use')<a href="{{ route('dash.callcenter') }}" class="btn-ghost">{{ __('Call center') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>

<form method="get" action="{{ route('dash.followup') }}" class="card mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-followup-filters>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Day') }}<input class="field" type="date" name="day" value="{{ $filters['day'] }}" dir="ltr"></label>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Status') }}
        <select class="field" name="status">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statusFilters as $key => $label)
                <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Source') }}
        <select class="field" name="source">
            <option value="">{{ __('All sources') }}</option>
            @foreach (Order::SOURCES as $key => $label)
                <option value="{{ $key }}" @selected($filters['source'] === $key)>{{ tr($label[0], $label[1]) }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Order number, name or mobile') }}
        <input class="field" type="search" name="q" value="{{ $filters['q'] }}">
    </label>
    <div class="flex flex-wrap items-end gap-3">
        <label class="flex min-h-11 items-center gap-2 text-sm font-semibold"><input type="checkbox" name="pending" value="1" class="size-4 accent-brand-600" @checked($filters['pending'])> {{ __('Not followed up yet') }}</label>
        <button class="btn-navy px-5">{{ __('Search') }}</button>
    </div>
</form>

<div class="grid gap-2" data-followup-list>
    @forelse ($orders as $order)
        @php $web = FollowUp::webUrl($order); @endphp
        <article class="card flex flex-wrap items-center gap-3 p-3" data-followup-row="{{ $order->id }}">
            <div class="min-w-0 flex-1 basis-60">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <a href="{{ route('dash.orders.receipt', $order) }}" target="_blank" rel="noopener" class="font-extrabold text-brand-700">#{{ $order->number }}</a>
                    <span class="badge bg-navy-50 text-navy-700">{{ tr(...(Order::SOURCES[$order->source] ?? [$order->source, $order->source])) }}</span>
                    <span class="badge bg-navy-50 text-navy-700">{{ tr(...(Order::TYPES[$order->type] ?? [$order->type, $order->type])) }}</span>
                    <span @class(['badge', 'bg-red-50 text-red-700' => $order->status === 'cancelled', 'bg-emerald-50 text-emerald-700' => $order->status === 'served', 'bg-brand-50 text-brand-700' => ! in_array($order->status, ['served', 'cancelled'], true)])>{{ $order->statusLabel() }}</span>
                    <span class="text-xs text-navy-500" dir="ltr">{{ local_time($order->created_at, 'h:i A') }}</span>
                </div>
                <div class="mt-1 text-sm"><bdi class="font-semibold">{{ $order->customer_name ?: __('No name') }}</bdi> · <span dir="ltr">{{ $order->customer_phone }}</span> · {{ money($order->total) }}@if ($order->branch) · {{ $order->branch->name }}@endif</div>
                <div class="mt-1 text-xs font-semibold text-emerald-700 {{ $order->followed_up_at ? '' : 'hidden' }}" data-followup-done>
                    ✓ {{ __('Followed up') }}: <span data-followup-by>{{ $order->followedUpBy?->name }}</span> · <span dir="ltr" data-followup-at>{{ $order->followed_up_at ? local_time($order->followed_up_at, 'h:i A') : '' }}</span>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                @if ($web)
                    <a href="{{ $web }}" data-app-url="{{ FollowUp::appUrl($order) }}" target="eghub-whatsapp" rel="noopener" class="btn-primary min-h-11 px-4"
                        data-followup-open="{{ route('dash.followup.mark', $order) }}">WhatsApp Web</a>
                @else
                    <span class="text-xs text-navy-500">{{ __('Not a mobile number') }}</span>
                @endif
                @unless ($order->followed_up_at)
                    <form method="post" action="{{ route('dash.followup.mark', $order) }}" data-followup-mark-form>
                        @csrf
                        <button class="btn-ghost min-h-11 px-3">{{ __('Mark as done') }}</button>
                    </form>
                @endunless
            </div>
        </article>
    @empty
        <p class="card py-10 text-center text-navy-500">{{ __('No orders with a mobile on this day.') }}</p>
    @endforelse
    <div class="mt-3">{{ $orders->links() }}</div>
</div>

<script>
(function () {
    // a phone has no WhatsApp Web: the same message opens in the WhatsApp app
    const phone = window.matchMedia('(pointer: coarse)').matches && !window.matchMedia('(min-width: 1024px)').matches;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    document.querySelectorAll('[data-followup-open]').forEach((link) => {
        if (phone) { link.href = link.dataset.appUrl; link.target = '_blank'; }
        link.addEventListener('click', () => {
            const row = link.closest('[data-followup-row]');
            fetch(link.dataset.followupOpen, { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } })
                .then((r) => r.ok ? r.json() : null).then((r) => {
                    if (!r || !row) return;
                    const done = row.querySelector('[data-followup-done]');
                    if (done.classList.contains('hidden')) {
                        done.querySelector('[data-followup-by]').textContent = r.by || '';
                        done.querySelector('[data-followup-at]').textContent = r.at || '';
                        done.classList.remove('hidden');
                    }
                    row.querySelector('[data-followup-mark-form]')?.remove();
                }).catch(() => {});
        });
    });
})();
</script>
@endsection
