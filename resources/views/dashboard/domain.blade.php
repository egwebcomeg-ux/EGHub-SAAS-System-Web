@extends('layouts.dashboard')
@section('title', __('Your own domain'))

@section('content')
<x-dash.page-header :title="__('Your own domain')" :help="__('Show your menu on your own address, like mycafe.com, instead of the EgMenu link. Every QR code switches to it, and QR codes you already printed keep working.')" />

@if (! $allowed)
    <div class="card max-w-2xl">
        <p class="text-navy-700">{{ \App\Services\Entitlements::current()->upgradeMessage('custom_domain') }}</p>
        <a href="{{ route('dash.billing') }}" class="btn-primary mt-4">{{ __('See plans') }}</a>
    </div>
@else
    @if ($domain)
        <section class="card mb-4 max-w-3xl space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-xl font-extrabold" dir="ltr">{{ $domain->domain }}</div>
                    <span @class(['badge mt-1', 'bg-emerald-100 text-emerald-800' => $domain->isActive(), 'bg-amber-100 text-amber-800' => in_array($domain->status, ['requested', 'pending'], true), 'bg-red-100 text-red-700' => $domain->status === 'rejected'])>{{ $domain->statusLabel() }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($domain->isActive())
                        <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-primary">{{ __('Open menu') }}</a>
                    @elseif ($domain->source === 'own')
                        <form method="post" action="{{ route('dash.domain.check') }}">@csrf<button class="btn-ghost">{{ __('Check the DNS') }}</button></form>
                    @endif
                    <form method="post" action="{{ route('dash.domain.delete') }}" onsubmit="return confirm(@js(__('Remove this domain? Your menu goes back to the EgMenu link.')))">@csrf<button class="btn-ghost text-red-600">{{ __('Remove') }}</button></form>
                </div>
            </div>
            @if ($domain->note)<p class="rounded-lg bg-navy-50 p-3 text-sm">{{ $domain->note }}</p>@endif

            @if ($domain->status === 'requested')
                <p class="text-sm text-navy-600">{{ __('Finish the booking on egweb.com.eg. Once it is registered, EgWeb connects it to your menu; no technical steps on your side.') }}</p>
            @elseif ($domain->status === 'pending' && $domain->source === 'own')
                <div class="text-sm text-navy-700">
                    <p class="mb-2 font-semibold">{{ __('In your domain DNS settings, add these records:') }}</p>
                    <table class="table-base max-w-lg" dir="ltr">
                        <thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead>
                        <tbody>
                            <tr><td>A</td><td>@</td><td>{{ $serverIp ?: '—' }}</td></tr>
                            <tr><td>A</td><td>www</td><td>{{ $serverIp ?: '—' }}</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-2 text-navy-500">{{ __('For a sub-domain such as menu.mycafe.com, add one A record with the name "menu". EgWeb then adds the SSL certificate and activates it.') }}</p>
                </div>
            @endif
        </section>
    @endif

    <div class="grid max-w-5xl gap-4 lg:grid-cols-2">
        <form method="post" action="{{ route('dash.domain.book') }}" target="_blank" class="card space-y-3">
            @csrf
            <h2 class="section-title">{{ __('Book a new domain') }}</h2>
            <p class="text-sm text-navy-500">{{ __('Search and pay on egweb.com.eg (the domain price is paid there, yearly). EgWeb registers it and links it to your menu.') }}</p>
            <input class="field" name="domain" required dir="ltr" placeholder="mycafe.com" value="{{ old('domain') }}">
            <button class="btn-primary w-full">{{ __('Search and book on EgWeb') }}</button>
        </form>
        <form method="post" action="{{ route('dash.domain.connect') }}" class="card space-y-3">
            @csrf
            <h2 class="section-title">{{ __('I already have a domain') }}</h2>
            <p class="text-sm text-navy-500">{{ __('Write it here, then point it to our server. A sub-domain like menu.mycafe.com works too.') }}</p>
            <input class="field" name="domain" required dir="ltr" placeholder="menu.mycafe.com">
            <button class="btn-ghost w-full">{{ __('Connect my domain') }}</button>
        </form>
    </div>
@endif
@endsection
