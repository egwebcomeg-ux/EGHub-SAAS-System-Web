@extends('layouts.dashboard')
@section('title', $campaign->name)

@section('content')
<x-dash.page-header :title="$campaign->name" :help="__('Tap «Send» on each guest: WhatsApp opens on your phone with their message ready, you press send there. The row is marked at once.')">
    <x-slot:actions>
        <a href="{{ route('dash.campaigns') }}" class="btn-ghost">{{ __('All campaigns') }}</a>
        @can('customers.import')<a href="{{ route('dash.outreach.export', $campaign) }}" class="btn-ghost" data-outreach-export>⬇ {{ __('Links as CSV (for SMS)') }}</a>@endcan
    </x-slot:actions>
</x-dash.page-header>

<x-flash />

<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4" data-outreach-stats>
    <div class="stat-card"><div class="stat-label">{{ __('Customers') }}</div><div class="stat-value">{{ number_format($stats['recipients']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Sent') }}</div><div class="stat-value" data-outreach-sent>{{ number_format($stats['sent']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Orders from it') }}</div><div class="stat-value" data-outreach-orders>{{ number_format($stats['orders']) }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Sales from it') }}</div><div class="stat-value">{{ money($stats['revenue']) }}</div></div>
</div>

@if ($sms)
    {{-- only with an SMS provider set up by the platform (config/sms.php) --}}
    <section class="card mb-4 flex flex-wrap items-center justify-between gap-3" data-outreach-sms>
        <div class="text-sm">
            <p class="font-bold">{{ __('Send it as SMS to the whole group') }}</p>
            @if ($smsEstimate)
                <p class="text-navy-500">{{ __(':messages messages × :segments SMS each', ['messages' => number_format($smsEstimate['messages']), 'segments' => $smsEstimate['segments']]) }}
                    @if ($smsEstimate['cost'] > 0) · {{ __('about :cost :currency', ['cost' => money($smsEstimate['cost']), 'currency' => $smsEstimate['currency']]) }}@endif
                    @if ($stats['sms']) · {{ __('Sent by SMS') }}: <b>{{ number_format($stats['sms']) }}</b>@endif
                </p>
            @endif
        </div>
        <form method="post" action="{{ route('dash.outreach.sms', $campaign) }}" onsubmit="return confirm(@js(__('Send the SMS to the whole group now?')))">
            @csrf
            <button class="btn-primary min-h-11" @disabled($smsState === 'sending')>{{ $smsState === 'sending' ? __('Sending…') : __('Send SMS to the group') }}</button>
        </form>
    </section>
@endif

<nav class="mb-3 flex gap-2" aria-label="{{ __('Filter') }}">
    @foreach (['all' => __('All'), 'waiting' => __('Not sent yet'), 'sent' => __('Sent')] as $key => $label)
        <a href="{{ route('dash.outreach.show', ['campaign' => $campaign, 'show' => $key === 'all' ? null : $key]) }}"
           @class(['badge min-h-9 px-3', 'bg-navy-900 text-white' => $filter === $key, 'bg-white text-navy-700 ring-1 ring-navy-100' => $filter !== $key])>{{ $label }}</a>
    @endforeach
</nav>

<div class="card divide-y divide-navy-100 p-0" data-outreach-rows data-sent-url="{{ route('dash.outreach.sent', ['campaign' => $campaign, 'recipient' => '__R__']) }}">
    @forelse ($rows as $row)
        @php($out = $row->opted_out_at !== null || ($row->customer && $row->customer->whatsapp_opt_out))
        <div class="flex flex-wrap items-center gap-3 p-3 sm:p-4" data-outreach-row="{{ $row->id }}">
            <div class="min-w-40 flex-1">
                <div class="font-bold">{{ $row->name ?: '—' }}</div>
                <div class="whitespace-nowrap text-sm text-navy-500" dir="ltr" style="text-align: start">{{ $row->phone }}</div>
                @if ($row->coupon)<div class="whitespace-nowrap font-mono text-xs text-navy-400" dir="ltr" style="text-align: start">{{ $row->coupon->code }}</div>@endif
            </div>
            @if ($out)
                <span class="badge bg-navy-100 text-navy-500" data-row-state="opted-out">{{ __('Asked to stop') }}</span>
            @else
                <span @class(['badge bg-emerald-100 text-emerald-800', 'hidden' => ! $row->sent_at]) data-row-state="sent">✓ {{ __('Sent') }}</span>
                <a href="{{ $outreach->whatsappUrl($campaign, $row) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-1 rounded-xl bg-[#25D366] px-4 text-sm font-bold text-[#0b3d1f] shadow-sm" data-outreach-send="{{ $row->id }}">
                    {{ $row->sent_at ? __('Send again') : __('Send') }}
                </a>
                <form method="post" action="{{ route('dash.outreach.opt-out', ['campaign' => $campaign, 'recipient' => $row]) }}" onsubmit="return confirm(@js(__('Mark this guest as «stop»? They will not get campaign messages again.')))">
                    @csrf
                    <button class="min-h-11 px-2 text-sm font-semibold text-navy-500 underline underline-offset-4" data-outreach-optout>{{ __('Replied «stop»') }}</button>
                </form>
            @endif
        </div>
    @empty
        <p class="p-6 text-center text-navy-500">{{ __('Nobody here.') }}</p>
    @endforelse
</div>
<div class="mt-4">{{ $rows->links() }}</div>

<script>
// «ابعت»: WhatsApp opens in a new tab with the message; the row is marked sent on the server right away
(function () {
    var list = document.querySelector('[data-outreach-rows]');
    if (!list) { return; }
    var token = document.querySelector('meta[name="csrf-token"]');
    list.addEventListener('click', function (e) {
        var link = e.target.closest('[data-outreach-send]');
        if (!link) { return; }
        var row = link.closest('[data-outreach-row]');
        fetch(list.dataset.sentUrl.replace('__R__', link.dataset.outreachSend), {
            method: 'POST', keepalive: true, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
        }).then(function (r) { return r.json(); }).then(function (d) {
            row.querySelector('[data-row-state="sent"]').classList.remove('hidden');
            link.textContent = @json(__('Send again'));
            var sent = document.querySelector('[data-outreach-sent]');
            if (sent && d.sent !== undefined) { sent.textContent = d.sent; }
        }).catch(function () {});
    });
})();
</script>
@endsection
