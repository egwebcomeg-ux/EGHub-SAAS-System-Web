@extends('layouts.dashboard')
@section('title', __('Campaigns'))

@section('content')
<h1 class="page-title mb-1">{{ __('Campaigns') }}</h1>
<p class="muted mb-4">{{ __('Send a WhatsApp message to a group of your guests, with a coupon if you like. Each message uses one credit.') }}</p>

<nav class="mb-4 flex gap-2 overflow-x-auto" aria-label="{{ __('Campaigns') }}">
    @foreach (['campaigns' => __('Campaigns'), 'automations' => __('Automations')] as $key => $label)
        <a href="{{ route('dash.campaigns', $key === 'campaigns' ? [] : ['tab' => $key]) }}" data-tab="{{ $key }}"
           @class(['flex min-h-11 shrink-0 items-center rounded-xl px-4 text-sm font-bold', 'bg-navy-900 text-white' => $tab === $key, 'bg-white text-navy-700 ring-1 ring-navy-100' => $tab !== $key])
           @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if ($tab === 'automations')
    @include('dashboard.campaigns.automations')
@else
<div class="grid gap-4 xl:grid-cols-5">
    <form method="post" action="{{ route('dash.campaigns.store') }}" class="card space-y-4 xl:col-span-3" data-campaign-form
        data-preview-url="{{ route('dash.campaigns.preview') }}">
        @csrf
        <h2 class="section-title">{{ __('New campaign') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="field-label" for="c-name">{{ __('Campaign name') }}</label>
                <input class="field" id="c-name" name="name" value="{{ old('name') }}" maxlength="100" required placeholder="{{ __('e.g. We miss you') }}">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <span class="field-label">{{ __('Channel') }}</span>
                <div class="flex gap-2">
                    <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border border-brand-500 bg-brand-50 text-sm font-semibold">
                        <input type="radio" name="channel" value="whatsapp" checked class="sr-only"> {{ __('WhatsApp') }}
                    </label>
                    <span class="flex min-h-11 flex-1 items-center justify-center gap-1 rounded-xl border border-navy-100 text-sm text-navy-400" title="{{ __('Coming soon') }}">
                        SMS <span class="text-xs">({{ __('Coming soon') }})</span>
                    </span>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="field-label" for="c-segment">{{ __('Who gets it') }}</label>
                <select class="field" id="c-segment" name="segment" data-segment>
                    @foreach (\App\Models\Campaign::SEGMENTS as $key => $segment)
                        <option value="{{ $key }}" data-unit="{{ $segment[2] }}" @selected(old('segment', request()->query('segment', 'all')) === $key)>{{ \App\Models\Campaign::segmentLabel($key) }}</option>
                    @endforeach
                </select>
                @error('segment')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div data-segment-value-box>
                <label class="field-label" for="c-value">N</label>
                <input class="field" id="c-value" name="segment_value" type="number" min="1" step="1" value="{{ old('segment_value', 30) }}" data-segment-value dir="ltr">
                @error('segment_value')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl bg-navy-50 p-3 text-sm" data-audience aria-live="polite">
            <span>{{ __('Customers') }}: <b data-audience-count>{{ number_format($preview['audience']) }}</b></span>
            <span>{{ __('Asked to stop') }}: <b data-audience-optout>{{ number_format($preview['opted_out']) }}</b></span>
            <span>{{ __('Cost') }}: <b data-audience-cost>{{ number_format($preview['cost']) }}</b> {{ __('messages') }}</span>
            <span>{{ __('Your balance') }}: <b data-audience-balance>{{ number_format($preview['balance']) }}</b>
                @can('billing.manage')<a href="{{ route('dash.billing') }}#whatsapp-credits" class="text-brand-700 underline">{{ __('Top up') }}</a>@endcan</span>
            <span class="w-full font-semibold text-red-700 {{ $preview['balance'] >= $preview['cost'] ? 'hidden' : '' }}" data-audience-short>{{ __('Your balance is less than the cost: sending stops when the credits run out.') }}</span>
        </div>

        <div>
            <label class="field-label" for="c-message">{{ __('Message') }}</label>
            <textarea class="field min-h-32 py-2" id="c-message" name="message" maxlength="1000" required data-message
                placeholder="{{ __('Hi {name}, we miss you at {restaurant}! Order today with your 20% coupon: {link}') }}">{{ old('message') }}</textarea>
            <div class="mt-1 flex flex-wrap gap-1.5 text-xs">
                @foreach (['{name}', '{restaurant}', '{link}', '{coupon}'] as $ph)
                    <button type="button" class="rounded-lg bg-navy-100 px-2 py-1 font-mono" data-insert="{{ $ph }}" dir="ltr">{{ $ph }}</button>
                @endforeach
            </div>
            @error('message')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <details class="rounded-xl border border-navy-100 p-3" @if (old('coupon_code')) open @endif>
            <summary class="cursor-pointer font-semibold">{{ __('Add a coupon (optional)') }}</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="field-label" for="c-code">{{ __('Coupon code') }}</label>
                    <input class="field uppercase" id="c-code" name="coupon_code" value="{{ old('coupon_code') }}" maxlength="30" dir="ltr" placeholder="WELCOME20" data-coupon-code>
                    @error('coupon_code')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="field-label" for="c-ctype">{{ __('Discount') }}</label>
                    <div class="flex gap-2">
                        <input class="field" id="c-cvalue" name="coupon_value" type="number" min="1" step="1" value="{{ old('coupon_value', 20) }}" dir="ltr">
                        <select class="field w-28" id="c-ctype" name="coupon_type">
                            <option value="percent" @selected(old('coupon_type', 'percent') === 'percent')>%</option>
                            <option value="amount" @selected(old('coupon_type') === 'amount')>{{ __('EGP') }}</option>
                        </select>
                    </div>
                    @error('coupon_value')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="field-label" for="c-cmax">{{ __('Maximum discount (EGP)') }}</label>
                    <input class="field" id="c-cmax" name="coupon_max_discount" type="number" min="1" value="{{ old('coupon_max_discount') }}" dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="c-cmin">{{ __('Minimum order (EGP)') }}</label>
                    <input class="field" id="c-cmin" name="coupon_min_order" type="number" min="0" value="{{ old('coupon_min_order') }}" dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="c-cexp">{{ __('Valid until') }}</label>
                    <input class="field" id="c-cexp" name="coupon_expires_at" type="date" value="{{ old('coupon_expires_at', now()->addDays(14)->format('Y-m-d')) }}" dir="ltr">
                    @error('coupon_expires_at')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="field-label" for="c-cuses">{{ __('Total uses (empty = no limit)') }}</label>
                    <input class="field" id="c-cuses" name="coupon_max_uses" type="number" min="1" value="{{ old('coupon_max_uses') }}" dir="ltr">
                </div>
                <p class="text-xs text-navy-500 sm:col-span-3">{{ __('Each guest can use the coupon once. The {link} in the message opens your menu with the code filled in.') }}</p>
            </div>
        </details>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <span class="field-label">{{ __('When') }}</span>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="when" value="now" @checked(old('when', 'now') === 'now') class="size-4 accent-brand-600" data-when> {{ __('Send now') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" name="when" value="later" @checked(old('when') === 'later') class="size-4 accent-brand-600" data-when> {{ __('Schedule') }}</label>
                </div>
                @error('when')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div data-schedule-box class="{{ old('when') === 'later' ? '' : 'hidden' }}">
                <label class="field-label" for="c-at">{{ __('Send at') }}</label>
                <input class="field" id="c-at" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at') }}" dir="ltr">
                @error('scheduled_at')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>
        <button class="btn-primary w-full sm:w-auto">{{ __('Create campaign') }}</button>
    </form>

    <aside class="space-y-3 xl:col-span-2">
        <h2 class="section-title">{{ __('Preview') }}</h2>
        <div class="rounded-2xl bg-[#e5ddd5] p-4" dir="{{ \App\Services\WhatsApp\WhatsApp::locale() === 'en' ? 'ltr' : 'rtl' }}">
            <div class="max-w-sm whitespace-pre-line rounded-xl rounded-ss-none bg-white p-3 text-sm leading-relaxed shadow-sm" data-message-preview
                data-sample-name="{{ \App\Services\WhatsApp\WhatsApp::locale() === 'en' ? 'Ahmed' : 'أحمد' }}" data-sender="{{ $sender }}" data-menu-url="{{ $menuUrl }}">—</div>
        </div>
    </aside>
</div>

<section class="mt-6 space-y-3">
    <h2 class="section-title">{{ __('Your campaigns') }}</h2>
    @forelse ($campaigns as $c)
        <div class="card flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <b>{{ $c->name }}</b>
                    <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $c->status === 'sent', 'bg-brand-100 text-brand-700' => in_array($c->status, ['sending', 'scheduled'], true),
                        'bg-red-100 text-red-700' => $c->status === 'stopped', 'bg-navy-100 text-navy-500' => $c->status === 'cancelled'])>{{ $c->statusLabel() }}</span>
                    @if ($c->coupon)<span class="badge bg-navy-800 text-white" dir="ltr">{{ $c->coupon->code }} · -{{ $c->coupon->label() }}</span>@endif
                </div>
                <div class="mt-1 text-sm text-navy-500">
                    {{ $c->audienceLabel() }} ·
                    @if ($c->status === 'scheduled'){{ __('Sends at :time', ['time' => local_time($c->scheduled_at)]) }}@else{{ local_time($c->started_at ?? $c->created_at) }}@endif
                    @if ($c->status === 'stopped' && $c->stop_reason === 'no_credits') · <span class="font-semibold text-red-700">{{ __('Your WhatsApp credits ran out') }}</span>@endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <span>{{ __('Sent') }} <b>{{ number_format($c->sent_count) }}</b></span>
                <span>{{ __('Failed') }} <b>{{ number_format($c->failed_count) }}</b></span>
                <span>{{ __('Skipped') }} <b>{{ number_format($c->skipped_count) }}</b></span>
                @if ($c->coupon)<span>{{ __('Redemptions') }} <b>{{ number_format($c->redemptions()) }}</b></span>@endif
                @if ($c->status === 'stopped')
                    <form method="post" action="{{ route('dash.campaigns.resume', $c) }}">@csrf<button class="btn-primary min-h-9 px-3">{{ __('Resume') }}</button></form>
                @endif
                @if (in_array($c->status, ['scheduled', 'stopped'], true))
                    <form method="post" action="{{ route('dash.campaigns.cancel', $c) }}" onsubmit="return confirm(@js(__('Cancel this campaign?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Cancel') }}</button></form>
                @endif
            </div>
        </div>
    @empty
        <p class="card muted">{{ __('No campaigns yet. Example: a 20% coupon to everyone who has not visited for 30 days.') }}</p>
    @endforelse
</section>

<script>
(() => {
    const form = document.querySelector('[data-campaign-form]');
    if (!form) return;
    const $ = (s) => form.querySelector(s);
    const segment = $('[data-segment]');
    const value = $('[data-segment-value]');
    const valueBox = $('[data-segment-value-box]');
    const message = $('[data-message]');
    const preview = document.querySelector('[data-message-preview]');
    const fmt = (n) => Number(n).toLocaleString();
    let timer;

    const refreshAudience = () => {
        const unit = segment.selectedOptions[0]?.dataset.unit || '';
        valueBox.classList.toggle('hidden', !unit);
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const params = new URLSearchParams({ segment: segment.value });
            if (unit && value.value) params.set('value', value.value);
            try {
                const res = await fetch(form.dataset.previewUrl + '?' + params, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const p = await res.json();
                $('[data-audience-count]').textContent = fmt(p.audience);
                $('[data-audience-optout]').textContent = fmt(p.opted_out);
                $('[data-audience-cost]').textContent = fmt(p.cost);
                $('[data-audience-balance]').textContent = fmt(p.balance);
                $('[data-audience-short]').classList.toggle('hidden', p.balance >= p.cost);
            } catch {}
        }, 250);
    };

    const refreshMessage = () => {
        const code = ($('[data-coupon-code]').value || '').trim().toUpperCase();
        const link = preview.dataset.menuUrl + (code ? '?coupon=' + encodeURIComponent(code) : '');
        const text = (message.value || message.placeholder)
            .replaceAll('{name}', preview.dataset.sampleName).replaceAll('{restaurant}', preview.dataset.sender)
            .replaceAll('{link}', link).replaceAll('{coupon}', code);
        preview.textContent = text;
    };

    form.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('click', () => {
        const at = message.selectionStart ?? message.value.length;
        message.value = message.value.slice(0, at) + b.dataset.insert + message.value.slice(message.selectionEnd ?? at);
        message.focus();
        refreshMessage();
    }));
    form.querySelectorAll('[data-when]').forEach((r) => r.addEventListener('change', () => {
        $('[data-schedule-box]').classList.toggle('hidden', !$('[data-when][value="later"]').checked);
    }));
    segment.addEventListener('change', refreshAudience);
    value.addEventListener('input', refreshAudience);
    message.addEventListener('input', refreshMessage);
    $('[data-coupon-code]').addEventListener('input', refreshMessage);
    refreshAudience();
    refreshMessage();
})();
</script>
@endif
@endsection
