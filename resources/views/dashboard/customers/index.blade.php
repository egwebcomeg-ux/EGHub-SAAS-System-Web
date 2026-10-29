@extends('layouts.dashboard')
@section('title', __('Customers'))

@section('content')
<x-dash.page-header :title="__('Customers')">
    <x-slot:actions>
        @can('customers.import')<a href="{{ route('dash.customers.import') }}" class="btn-ghost" data-customers-import>⬆ {{ __('Import customers') }}</a>@endcan
        @can('marketing.manage')<a href="{{ route('dash.campaigns', array_filter(['segment' => $segment])) }}" class="btn-ghost" data-campaigns-link>💬 {{ __('Campaigns') }}</a>@endcan
        <a href="{{ route('dash.customers.export', array_filter(['q' => $q, 'segment' => $segment])) }}" class="btn-ghost">⬇ {{ __('Export to Excel (CSV)') }}</a>
    </x-slot:actions>
</x-dash.page-header>

@if ($summary = session('import_summary'))
    <section class="card mb-4 border-s-4 border-emerald-500" data-import-summary>
        <h2 class="section-title">{{ __('Import finished') }} <span class="text-sm font-normal text-navy-500">· <span dir="ltr">{{ $summary['file'] }}</span></span></h2>
        <dl class="mt-3 grid grid-cols-3 gap-3 text-center">
            <div class="rounded-xl bg-emerald-50 p-3"><dt class="text-xs text-emerald-800">{{ __('New customers') }}</dt><dd class="text-xl font-bold text-emerald-900">{{ number_format($summary['created']) }}</dd></div>
            <div class="rounded-xl bg-brand-50 p-3"><dt class="text-xs text-brand-700">{{ __('Updated') }}</dt><dd class="text-xl font-bold text-brand-700">{{ number_format($summary['updated']) }}</dd></div>
            <div class="rounded-xl bg-navy-50 p-3"><dt class="text-xs text-navy-600">{{ __('Skipped rows') }}</dt><dd class="text-xl font-bold text-navy-800">{{ number_format($summary['skipped']) }}</dd></div>
        </dl>
    </section>
@endif

<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
    <div class="stat-card"><div class="stat-label">{{ __('All customers') }}</div><div class="stat-value">{{ $stats['total'] }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('Came back more than once') }}</div><div class="stat-value">{{ $stats['returning'] }}</div></div>
    <div class="stat-card"><div class="stat-label">{{ __('New this month') }}</div><div class="stat-value">{{ $stats['new_month'] }}</div></div>
</div>

@if ($codeUsage !== [])
    {{-- the guests' sign-in codes (App\Services\Customers\SignIn\PhoneCodeSignIn): what the place's own WhatsApp number and SMS cost --}}
    <section class="card mb-4 text-sm" data-code-usage>
        <h2 class="font-bold">🔐 {{ __('Sign-in codes sent to guests (last 30 days)') }}</h2>
        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1">
            @foreach ($codeUsage as $channel => $u)
                <span data-code-usage-channel="{{ $channel }}">{{ \App\Services\Customers\SignIn\PhoneCodeSignIn::label($channel) }}: <b>{{ number_format($u['count']) }}</b>
                    @if ($u['cost'] > 0)<span class="text-navy-500">({{ __('about :cost', ['cost' => money($u['cost'])]) }})</span>@endif</span>
            @endforeach
        </div>
        <p class="mt-1 text-xs text-navy-500">{{ __('WhatsApp codes go out from your own number and Meta bills your account; SMS from the provider set up for you.') }}</p>
    </section>
@endif

@php
    $segmentStyles = [
        'new' => 'bg-sky-100 text-sky-800', 'regular' => 'bg-emerald-100 text-emerald-800', 'vip' => 'bg-amber-100 text-amber-800',
        'at_risk' => 'bg-orange-100 text-orange-800', 'lost' => 'bg-navy-100 text-navy-500',
    ];
@endphp
{{-- the guest groups (App\Services\Customers\Segments): how many, what they spent, and what to do; a tap filters the list --}}
<section class="mb-4" data-segments aria-label="{{ __('Groups of guests') }}">
    <div class="m-noscroll -mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-2">
        <a href="{{ route('dash.customers', array_filter(['q' => $q, 'sort' => $sort])) }}" data-segment=""
           @class(['min-w-32 shrink-0 snap-start rounded-2xl border p-3 transition', 'border-navy-900 bg-navy-900 text-white' => $segment === '', 'border-navy-100 bg-white hover:border-brand-500' => $segment !== ''])
           @if ($segment === '') aria-current="true" @endif>
            <span class="block text-sm font-bold">{{ __('All customers') }}</span>
            <span class="mt-1 block text-2xl font-black">{{ number_format($stats['total']) }}</span>
        </a>
        @foreach ($groups as $g)
            <a href="{{ route('dash.customers', array_filter(['segment' => $segment === $g['key'] ? null : $g['key'], 'q' => $q, 'sort' => $sort])) }}" data-segment="{{ $g['key'] }}" title="{{ $g['rule'] }}"
               @class(['min-w-40 shrink-0 snap-start rounded-2xl border p-3 transition', 'border-navy-900 bg-navy-900 text-white' => $segment === $g['key'], 'border-navy-100 bg-white hover:border-brand-500' => $segment !== $g['key']])
               @if ($segment === $g['key']) aria-current="true" @endif>
                <span class="block text-sm font-bold">{{ $g['label'] }}</span>
                <span class="mt-1 block text-2xl font-black" data-segment-count>{{ number_format($g['count']) }}</span>
                <span @class(['block text-xs', 'text-white/70' => $segment === $g['key'], 'text-navy-500' => $segment !== $g['key']])>{{ __('Spent :amount', ['amount' => money($g['revenue'])]) }}</span>
            </a>
        @endforeach
    </div>
    @if ($segment !== '')
        <script>document.querySelector('[data-segment][aria-current]')?.scrollIntoView({ inline: 'center', block: 'nearest' });</script>
    @endif
    @if ($segment !== '' && ($current = collect($groups)->firstWhere('key', $segment)))
        <div class="mt-2 rounded-2xl border border-brand-200 bg-brand-50 p-4" data-segment-action>
            <p class="text-sm text-navy-600" data-segment-rule>{{ $current['rule'] }}.</p>
            <p class="mt-1 font-bold">💡 {{ $current['action'] }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @can('marketing.manage')
                    <a href="{{ route('dash.campaigns', ['segment' => $segment]) }}" class="btn-primary min-h-11" data-segment-campaign>💬 {{ __('Send them a campaign') }}</a>
                @endcan
                @can('customers.import')
                    <a href="{{ route('dash.customers.audience-export', ['segment' => $segment]) }}" class="btn-ghost min-h-11 border" data-segment-meta>⬇ {{ __('Export for Facebook / Instagram ads') }}</a>
                @endcan
            </div>
            @can('customers.import')
                <details class="mt-3 text-sm" data-meta-guide>
                    <summary class="cursor-pointer font-semibold text-brand-700">{{ __('How to use the file in Meta ads') }}</summary>
                    <ol class="mt-2 list-decimal space-y-1 ps-5 text-navy-700">
                        <li>{{ __('Download the file from the button above.') }}</li>
                        <li>{!! __('Open :link → Audiences.', ['link' => '<a href="https://adsmanager.facebook.com/adsmanager/audiences" target="_blank" rel="noopener" class="font-semibold underline">Meta Ads Manager</a>']) !!}</li>
                        <li>{{ __('Create audience → Custom audience → Customer list, and upload the file (the columns are already named the way Meta reads them).') }}</li>
                        <li>{{ __('Use the audience in an ad to remind them of you, or make a Lookalike audience from it to reach people like your best guests.') }}</li>
                        <li>{{ __('Meta matches the numbers and emails on its side; small groups (under about 100) may not be shown.') }}</li>
                    </ol>
                </details>
            @endcan
        </div>
    @endif
</section>

<form class="mb-4 flex flex-wrap gap-2">
    @if ($segment !== '')<input type="hidden" name="segment" value="{{ $segment }}">@endif
    <input class="field max-w-xs" name="q" value="{{ $q }}" placeholder="{{ __('Search name or mobile') }}">
    <select class="field max-w-52" name="sort" onchange="this.form.submit()">
        <option value="last_visit_at" @selected($sort === 'last_visit_at')>{{ __('Last visit') }}</option>
        <option value="visits_count" @selected($sort === 'visits_count')>{{ __('Most visits') }}</option>
        <option value="total_spent" @selected($sort === 'total_spent')>{{ __('Top spenders') }}</option>
    </select>
    <button class="btn-ghost">{{ __('Search') }}</button>
</form>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Segment') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Visits') }}</th><th>{{ __('Total spent') }}</th><th>{{ __('Last visit') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($customers as $c)
            @php($badge = $segments->of($c))
            <tr>
                <td class="font-semibold">{{ $c->name ?: '—' }}</td>
                <td>@if ($badge)<span class="badge {{ $segmentStyles[$badge] }}" data-row-segment="{{ $badge }}">{{ \App\Services\Customers\Segments::badge($badge) }}</span>@else<span class="text-navy-400">—</span>@endif</td>
                <td dir="ltr" class="text-start">{{ $c->phone }}</td>
                <td>{{ $c->visits_count }}</td>
                <td>{{ money($c->total_spent) }}</td>
                <td>{{ $c->last_visit_at ? local_time($c->last_visit_at, 'Y/m/d') : '—' }}</td>
                <td>
                    <div class="flex gap-3">
                        <a href="{{ route('dash.customers.show', $c) }}" class="text-brand-700">{{ __('View') }}</a>
                        <a href="https://wa.me/{{ whatsapp_number($c->phone) }}" target="_blank" rel="noopener" class="text-emerald-700">WhatsApp</a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-10 text-center text-navy-500">{{ $segment !== '' ? __('No guests in this segment yet.') : __('Guests appear here when they order with their mobile number or join the loyalty card.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $customers->links() }}</div>
</div>
@endsection
