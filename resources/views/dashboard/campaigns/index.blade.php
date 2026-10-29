@extends('layouts.dashboard')
@section('title', __('Campaigns'))

@section('content')
<x-dash.page-header :title="__('Campaigns')" :help="__('Send a message to a group of your guests: free from your own phone, or from EgHub WhatsApp with credits. Add a coupon if you like, and see who came back.')" />

<nav class="mb-4 flex gap-2 overflow-x-auto" aria-label="{{ __('Campaigns') }}">
    @foreach (['campaigns' => __('Campaigns'), 'automations' => __('Automations')] as $key => $label)
        <a href="{{ route('dash.campaigns', $key === 'campaigns' ? [] : ['tab' => $key]) }}" data-tab="{{ $key }}"
           @class(['flex min-h-11 shrink-0 items-center rounded-xl px-4 text-sm font-bold', 'bg-navy-900 text-white' => $tab === $key, 'bg-white text-navy-700 ring-1 ring-navy-100' => $tab !== $key])
           @if ($tab === $key) aria-current="page" @endif>{{ $label }}@if ($key === 'automations' && ! $paid) 🔒@endif</a>
    @endforeach
</nav>

@if ($tab === 'automations')
    @include('dashboard.campaigns.automations')
@else
@php
    $pickedVia = old('channel', $via);
    $pickedSegment = old('segment', $segment);
    $viaHelp = [
        'manual' => __('Free: a list of your guests, you tap «Send» on each one and WhatsApp opens on your phone with their message.'),
        'sms' => __('An SMS to each guest, all sent at once.'),
        'whatsapp' => __('EgHub sends it from its WhatsApp number, now or at a time you pick. One credit a message.'),
    ];
@endphp
<div class="grid gap-4 xl:grid-cols-5">
    <form method="post" action="{{ route('dash.campaigns.store') }}" class="card space-y-4 xl:col-span-3" data-campaign-form
        data-preview-url="{{ route('dash.campaigns.preview') }}">
        @csrf
        <h2 class="section-title">{{ __('New campaign') }}</h2>
        <div>
            <label class="field-label" for="c-name">{{ __('Campaign name') }}</label>
            <input class="field" id="c-name" name="name" value="{{ old('name') }}" maxlength="100" required placeholder="{{ __('e.g. We miss you') }}">
            @error('name')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <fieldset>
            <legend class="field-label">{{ __('Send via') }}</legend>
            <div class="grid gap-2 sm:grid-cols-3" data-vias>
                @foreach (['manual', 'sms', 'whatsapp'] as $key)
                    @php($open = in_array($key, $vias, true))
                    {{-- EgHub WhatsApp shows locked without the campaigns feature; the others only when they can be used --}}
                    @continue(! $open && $key !== 'whatsapp')
                    <label @class(['flex min-h-14 items-start gap-3 rounded-xl border border-navy-100 p-3', 'cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50' => $open, 'opacity-60' => ! $open]) data-via="{{ $key }}">
                        <input type="radio" name="channel" value="{{ $key }}" class="mt-1 size-4" @checked($open && $pickedVia === $key) @disabled(! $open) data-via-pick>
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold">{{ \App\Models\Campaign::viaLabel($key) }}
                                @if ($key === 'manual')<span class="badge bg-emerald-100 text-emerald-800">{{ __('Free') }}</span>@endif</span>
                            <span class="block text-xs text-navy-500">{{ $viaHelp[$key] }}</span>
                            @if (! $open)
                                <span class="mt-1 block text-xs font-semibold text-navy-600" data-via-locked>🔒 {{ __('Needs a higher plan or an add-on') }}
                                    @can('billing.manage')<a href="{{ route('dash.billing') }}" class="text-brand-700 underline">{{ __('Upgrade') }}</a>@endcan</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            @error('channel')<div class="field-error">{{ $message }}</div>@enderror
        </fieldset>

        <fieldset>
            <legend class="field-label">{{ __('Who gets it') }}</legend>
            {{-- the guest groups of the customers page, then filters with a number --}}
            <div class="grid gap-2 sm:grid-cols-2" data-segments>
                @foreach ($groups as $g)
                    <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50" title="{{ $g['rule'] }}">
                        <input type="radio" name="segment" value="{{ $g['key'] }}" class="size-4" @checked($pickedSegment === $g['key']) data-segment data-action="{{ $g['action'] }}">
                        <span class="min-w-0 flex-1">
                            <span class="block font-bold">{{ $g['label'] }}</span>
                            <span class="block text-xs text-navy-500">{{ trans_choice(':count guest|:count guests', $g['count'], ['count' => number_format($g['count'])]) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <div class="mt-3 text-sm font-semibold text-navy-600">{{ __('More filters') }}</div>
            <div class="mt-2 flex flex-wrap gap-2" data-filters>
                @foreach (\App\Models\Campaign::FILTERS as $key => $filter)
                    <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="segment" value="{{ $key }}" class="size-4" @checked($pickedSegment === $key) data-segment data-unit="{{ $filter[2] }}">
                        {{ \App\Models\Campaign::segmentLabel($key) }}
                    </label>
                @endforeach
            </div>
            <div class="mt-2 max-w-40 {{ \App\Models\Campaign::valueUnit($pickedSegment) ? '' : 'hidden' }}" data-segment-value-box>
                <label class="field-label" for="c-value">N</label>
                <input class="field" id="c-value" name="segment_value" type="number" min="1" step="1" value="{{ old('segment_value', 30) }}" data-segment-value dir="ltr">
            </div>
            @error('segment')<div class="field-error">{{ $message }}</div>@enderror
            @error('segment_value')<div class="field-error">{{ $message }}</div>@enderror
        </fieldset>
        <p class="rounded-xl border border-brand-200 bg-brand-50 p-3 text-sm font-semibold {{ \App\Services\Customers\Segments::is($pickedSegment) ? '' : 'hidden' }}" data-segment-hint>💡 <span>{{ \App\Services\Customers\Segments::is($pickedSegment) ? \App\Services\Customers\Segments::action($pickedSegment) : '' }}</span></p>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl bg-navy-50 p-3 text-sm" data-audience aria-live="polite">
            <span>{{ __('Customers') }}: <b data-audience-count>{{ number_format($preview['audience']) }}</b></span>
            <span>{{ __('Asked to stop') }}: <b data-audience-optout>{{ number_format($preview['opted_out']) }}</b></span>
            <span data-via-only="whatsapp">{{ __('Cost') }}: <b data-audience-cost>{{ number_format($preview['cost']) }}</b> {{ __('messages') }}</span>
            <span data-via-only="whatsapp">{{ __('Your balance') }}: <b data-audience-balance>{{ number_format($preview['balance']) }}</b>
                @can('billing.manage')<a href="{{ route('dash.billing') }}#whatsapp-credits" class="text-brand-700 underline">{{ __('Top up') }}</a>@endcan</span>
            <span class="w-full font-semibold text-red-700 {{ $preview['balance'] >= $preview['cost'] ? 'hidden' : '' }}" data-audience-short>{{ __('Your balance is less than the cost: sending stops when the credits run out.') }}</span>
        </div>

        <div>
            <label class="field-label" for="c-message">{{ __('Message') }}</label>
            <textarea class="field min-h-32 py-2" id="c-message" name="message" maxlength="1000" required data-message
                placeholder="{{ __('Hi {name}, we miss you at {restaurant}! Order today with your 20% coupon: {link}') }}">{{ old('message', \App\Services\Customers\Segments::is($pickedSegment) ? \App\Services\Customers\Segments::message($pickedSegment) : '') }}</textarea>
            <div class="mt-1 flex flex-wrap gap-1.5 text-xs">
                @foreach (['{name}', '{restaurant}', '{link}', '{coupon}'] as $ph)
                    <button type="button" class="rounded-lg bg-navy-100 px-2 py-1 font-mono" data-insert="{{ $ph }}" dir="ltr">{{ $ph }}</button>
                @endforeach
            </div>
            @error('message')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        {{-- from the staff's own phone or by SMS: a coupon and an account link of each guest's own --}}
        <div class="space-y-3" data-via-only="manual sms">
            <div class="rounded-xl bg-navy-50 p-3">
                <label class="flex items-center gap-2 font-semibold">
                    <input type="checkbox" name="personal_coupon" value="1" class="size-4" @checked(old('personal_coupon')) data-personal-coupon-toggle>
                    {{ __('A personal one-time coupon for each guest') }}
                </label>
                <div @class(['mt-3 grid gap-3 sm:grid-cols-4', 'hidden' => ! old('personal_coupon')]) data-personal-coupon>
                    <div>
                        <label class="field-label" for="p-type">{{ __('Type') }}</label>
                        <select class="field" id="p-type" name="personal_type">
                            <option value="percent" @selected(old('personal_type') === 'percent')>%</option>
                            <option value="amount" @selected(old('personal_type') === 'amount')>{{ __('EGP') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="p-value">{{ __('Value') }}</label>
                        <input class="field" id="p-value" type="number" name="personal_value" min="1" step="0.5" value="{{ old('personal_value', 15) }}" dir="ltr">
                        @error('personal_value')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="field-label" for="p-days">{{ __('Good for (days)') }}</label>
                        <input class="field" id="p-days" type="number" name="personal_days" min="1" max="90" value="{{ old('personal_days', 7) }}" dir="ltr">
                    </div>
                    <div>
                        <label class="field-label" for="p-min">{{ __('Minimum order') }}</label>
                        <input class="field" id="p-min" type="number" name="personal_min_order" min="0" value="{{ old('personal_min_order', 0) }}" dir="ltr">
                    </div>
                </div>
            </div>
            <label class="flex items-start gap-2 rounded-xl bg-navy-50 p-3">
                <input type="checkbox" name="with_claim" value="1" class="mt-1 size-4" @checked(old('with_claim', $pickedSegment === 'imported')) data-claim>
                <span>
                    <span class="block font-semibold">{{ __('Invite them to their account («ادعي عملاءك»)') }}</span>
                    <span class="block text-xs text-navy-500">{{ __('{link} becomes a personal link: the guest sees their name and saved address, chooses a password or continues with Google, and is signed in. One use, for 14 days.') }}</span>
                </span>
            </label>
        </div>

        {{-- from EgHub WhatsApp: one code for everyone, now or at a time --}}
        <div class="space-y-4" data-via-only="whatsapp">
            <details class="rounded-xl border border-navy-100 p-3" @if (old('coupon_code') || old('coupon_id')) open @endif>
                <summary class="cursor-pointer font-semibold">{{ __('Add a coupon (optional)') }}</summary>
                @if ($pickable->isNotEmpty())
                    {{-- a code from the coupons page (public, still good), or a new one below --}}
                    <div class="mt-3" data-coupon-pick-box>
                        <label class="field-label" for="c-pick">{{ __('Pick a coupon from your list') }}</label>
                        <select class="field" id="c-pick" name="coupon_id" data-coupon-pick>
                            <option value="">{{ __('No coupon, or a new one below') }}</option>
                            @foreach ($pickable as $coupon)
                                <option value="{{ $coupon->id }}" data-code="{{ $coupon->code }}" @selected((int) old('coupon_id') === $coupon->id)>{{ $coupon->code }} · -{{ $coupon->label() }} · {{ \App\Models\Coupon::kindLabel($coupon->kind()) }}</option>
                            @endforeach
                        </select>
                        @error('coupon_id')<div class="field-error">{{ $message }}</div>@enderror
                        <p class="mt-1 text-xs text-navy-500">{{ __('All your codes are on the coupons page.') }}
                            @if (\App\Http\Controllers\Dashboard\CouponsController::visibleFor(auth()->user()))<a href="{{ route('dash.coupons') }}" class="font-semibold text-brand-700 underline">{{ __('Coupons & vouchers') }}</a>@endif</p>
                    </div>
                    <div class="mt-3 text-sm font-semibold text-navy-600">{{ __('Or make a new coupon') }}</div>
                @endif
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
        </div>
        <button class="btn-primary min-h-12 w-full sm:w-auto">{{ __('Create campaign') }}</button>
    </form>
    <aside class="space-y-3 xl:col-span-2">
        <h2 class="section-title">{{ __('Preview') }}</h2>
        <div class="rounded-2xl bg-[#e5ddd5] p-4" dir="{{ \App\Services\WhatsApp\WhatsApp::locale() === 'en' ? 'ltr' : 'rtl' }}">
            <div class="max-w-sm whitespace-pre-line rounded-xl rounded-ss-none bg-white p-3 text-sm leading-relaxed shadow-sm" data-message-preview
                data-sample-name="{{ \App\Services\WhatsApp\WhatsApp::locale() === 'en' ? 'Ahmed' : 'أحمد' }}" data-sender="{{ $sender }}" data-menu-url="{{ $menuUrl }}">—</div>
        </div>
    </aside>
</div>

<section class="mt-6 space-y-3" data-campaigns>
    <h2 class="section-title">{{ __('Your campaigns') }}</h2>
    @forelse ($campaigns as $c)
        @php($manual = $c->channel === \App\Services\Customers\Outreach::CHANNEL)
        <div class="card flex flex-wrap items-center justify-between gap-3" data-campaign="{{ $c->id }}" data-campaign-via="{{ $c->via() }}">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <b>{{ $c->name }}</b>
                    <span class="badge bg-navy-100 text-navy-700">{{ \App\Models\Campaign::viaLabel($c->via()) }}</span>
                    @unless ($manual)
                        <span @class(['badge', 'bg-emerald-100 text-emerald-800' => $c->status === 'sent', 'bg-brand-100 text-brand-700' => in_array($c->status, ['sending', 'scheduled'], true),
                            'bg-amber-100 text-amber-800' => $c->status === 'stopped', 'bg-navy-100 text-navy-500' => $c->status === 'cancelled'])>{{ $c->statusLabel() }}</span>
                    @endunless
                    @if ($c->coupon)<span class="badge bg-navy-800 text-white" dir="ltr">{{ $c->coupon->code }} · -{{ $c->coupon->label() }}</span>@endif
                </div>
                <div class="mt-1 text-sm text-navy-500">
                    {{ $c->audienceLabel() }} ·
                    @if ($c->status === 'scheduled'){{ __('Sends at :time', ['time' => local_time($c->scheduled_at)]) }}@else{{ local_time($c->started_at ?? $c->created_at) }}@endif
                    @if ($c->status === 'stopped' && $c->stop_reason === 'no_credits') · <span class="font-semibold text-red-700">{{ __('Your WhatsApp credits ran out') }}</span>@endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-sm">
                @if ($manual)
                    @php($s = $stats[$c->id])
                    <span>{{ __('Sent') }} <b>{{ number_format($c->via() === 'sms' ? $s['sms'] : $s['sent']) }}/{{ number_format($s['recipients']) }}</b></span>
                    <span>{{ __('Orders') }} <b>{{ number_format($s['orders']) }}</b></span>
                    <span>{{ __('Sales') }} <b>{{ money($s['revenue']) }}</b></span>
                    <a href="{{ route('dash.outreach.show', $c) }}" class="btn-ghost min-h-9 border px-3" data-outreach-campaign="{{ $c->id }}">{{ __('Open the send list') }}</a>
                @else
                    <span>{{ __('Sent') }} <b>{{ number_format($c->sent_count) }}</b></span>
                    <span>{{ __('Failed') }} <b>{{ number_format($c->failed_count) }}</b></span>
                    <span>{{ __('Skipped') }} <b>{{ number_format($c->skipped_count) }}</b></span>
                    @if ($c->coupon)<span>{{ __('Redemptions') }} <b>{{ number_format($c->redemptions()) }}</b></span>@endif
                    @if ($paid && $c->status === 'stopped')
                        <form method="post" action="{{ route('dash.campaigns.resume', $c) }}">@csrf<button class="btn-primary min-h-9 px-3">{{ __('Resume') }}</button></form>
                    @endif
                    @if ($paid && in_array($c->status, ['scheduled', 'stopped'], true))
                        <form method="post" action="{{ route('dash.campaigns.cancel', $c) }}" onsubmit="return confirm(@js(__('Cancel this campaign?')))">@csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Cancel') }}</button></form>
                    @endif
                @endif
            </div>
        </div>
    @empty
        <p class="empty-state">{{ __('No campaigns yet. Example: a 20% coupon to everyone who has not visited for 30 days.') }}</p>
    @endforelse
</section>

<script>
(() => {
    const form = document.querySelector('[data-campaign-form]');
    if (!form) return;
    const $ = (s) => form.querySelector(s);
    const value = $('[data-segment-value]');
    const valueBox = $('[data-segment-value-box]');
    const message = $('[data-message]');
    const preview = document.querySelector('[data-message-preview]');
    // the suggested message follows the group, until the owner writes their own
    const messages = @json($messages);
    let touched = message.value !== '' && !Object.values(messages).includes(message.value);
    const fmt = (n) => Number(n).toLocaleString();
    const via = () => form.querySelector('[data-via-pick]:checked')?.value || '';
    const segment = () => form.querySelector('[data-segment]:checked');
    let timer;

    const refreshVia = () => {
        form.querySelectorAll('[data-via-only]').forEach((el) => el.classList.toggle('hidden', !el.dataset.viaOnly.split(' ').includes(via())));
        refreshAudience();
        refreshMessage();
    };

    const refreshAudience = () => {
        const picked = segment();
        if (!picked) return;
        const unit = picked.dataset.unit || '';
        valueBox.classList.toggle('hidden', !unit);
        const hint = $('[data-segment-hint]');
        hint.classList.toggle('hidden', !picked.dataset.action);
        hint.querySelector('span').textContent = picked.dataset.action || '';
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const params = new URLSearchParams({ segment: picked.value, channel: via() || 'whatsapp' });
            if (unit && value.value) params.set('value', value.value);
            try {
                const res = await fetch(form.dataset.previewUrl + '?' + params, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const p = await res.json();
                $('[data-audience-count]').textContent = fmt(p.audience);
                $('[data-audience-optout]').textContent = fmt(p.opted_out);
                $('[data-audience-cost]').textContent = fmt(p.cost);
                $('[data-audience-balance]').textContent = fmt(p.balance);
                $('[data-audience-short]').classList.toggle('hidden', via() !== 'whatsapp' || p.balance >= p.cost);
            } catch {}
        }, 250);
    };

    const refreshMessage = () => {
        const picked = $('[data-coupon-pick]')?.selectedOptions[0]?.dataset.code || '';
        const personal = via() !== 'whatsapp' && $('[data-personal-coupon-toggle]')?.checked;
        const code = personal ? 'ABC123' : (($('[data-coupon-code]')?.value || '').trim().toUpperCase() || picked);
        const link = preview.dataset.menuUrl + (code ? '?coupon=' + encodeURIComponent(code) : '');
        const text = (message.value || message.placeholder)
            .replaceAll('{name}', preview.dataset.sampleName).replaceAll('{restaurant}', preview.dataset.sender)
            .replaceAll('{link}', link).replaceAll('{coupon}', code).replaceAll('{code}', code);
        preview.textContent = text;
    };

    form.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('click', () => {
        const at = message.selectionStart ?? message.value.length;
        message.value = message.value.slice(0, at) + b.dataset.insert + message.value.slice(message.selectionEnd ?? at);
        touched = true;
        message.focus();
        refreshMessage();
    }));
    form.querySelectorAll('[data-when]').forEach((r) => r.addEventListener('change', () => {
        $('[data-schedule-box]').classList.toggle('hidden', !$('[data-when][value="later"]').checked);
    }));
    form.querySelectorAll('[data-via-pick]').forEach((r) => r.addEventListener('change', refreshVia));
    form.querySelectorAll('[data-segment]').forEach((r) => r.addEventListener('change', () => {
        if (!touched) {
            message.value = messages[r.value] || '';
        }
        if ($('[data-claim]') && !$('[data-claim]').dataset.touched) {
            $('[data-claim]').checked = r.value === 'imported';
        }
        refreshAudience();
        refreshMessage();
    }));
    $('[data-claim]')?.addEventListener('change', (e) => { e.target.dataset.touched = '1'; });
    $('[data-personal-coupon-toggle]')?.addEventListener('change', (e) => {
        $('[data-personal-coupon]').classList.toggle('hidden', !e.target.checked);
        refreshMessage();
    });
    value.addEventListener('input', refreshAudience);
    message.addEventListener('input', () => { touched = true; refreshMessage(); });
    $('[data-coupon-code]')?.addEventListener('input', refreshMessage);
    $('[data-coupon-pick]')?.addEventListener('change', refreshMessage);
    refreshVia();
})();
</script>
@endif
@endsection
