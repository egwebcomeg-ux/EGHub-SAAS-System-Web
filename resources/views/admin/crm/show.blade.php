@extends('layouts.admin')
@section('title', $tenant->name . ' · ' . __('Customer follow-up') . ' · EgHub Admin')

@section('content')
@php
    $en = app()->getLocale() === 'en';
    $canManage = $admin->allows('crm.manage');
    $canDiscount = $admin->allows('crm.discount');
    $limit = $admin->discountLimit();
    $owner = $activity['owner'];
    $sub = $tenant->subscription;
    $answers = (array) ($lead->answers ?? []);
    $fill = fn (?string $text) => nl2br(e(\App\Services\Crm\CallScript::fill((string) $text, $context)));
    $stateDot = ['ok' => 'bg-emerald-500', 'warn' => 'bg-amber-500', 'none' => 'bg-navy-200'];
    $suggested = $advice['discount']['percent'] ?? null;
    $phoneIcon = 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z';
    $waIcon = 'M3 21l1.6-4.7A8.5 8.5 0 1 1 7.9 19.5zM9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.3-1.8-1-1 .8c-1-.4-1.8-1.2-2.2-2.2l.8-1-1-1.8z';
@endphp

<a href="{{ route('admin.crm') }}" class="text-sm font-semibold text-navy-500 hover:text-navy-800">← {{ __('Customer follow-up') }}</a>

{{-- who: everything needed before saying hello --}}
<section class="card mt-2 mb-4" data-lead-who>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title flex flex-wrap items-center gap-2">
                <span class="break-words">{{ $tenant->name }}</span>
                <span class="badge {{ $lead->stageClasses() }}" data-lead-stage="{{ $lead->stage }}">{{ $lead->stageLabel() }}</span>
            </h1>
            <div class="mt-1 text-base font-bold text-navy-800">{{ $owner?->name ?? '—' }}</div>
            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-navy-600">
                @if ($phone)<span dir="ltr" class="font-semibold" data-lead-phone>{{ $phone }}</span>@else<span>{{ __('No mobile number') }}</span>@endif
                @if ($owner?->email)<span dir="ltr" class="break-all">{{ $owner->email }}</span>@endif
            </div>
        </div>
        <div class="flex w-full gap-2 sm:w-auto">
            @if ($telUrl)
                <a href="{{ $telUrl }}" class="btn flex-1 bg-emerald-600 text-white hover:bg-emerald-700 sm:flex-none" data-call-button>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $phoneIcon }}"/></svg>
                    {{ __('Call') }}
                </a>
            @endif
            @if ($whatsappUrl)
                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="btn flex-1 border border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 sm:flex-none" data-whatsapp-button>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $waIcon }}"/></svg>
                    {{ __('WhatsApp') }}
                </a>
            @endif
        </div>
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
        <div><dt class="text-xs text-navy-500">{{ __('Business type') }}</dt><dd class="font-semibold"><span aria-hidden="true">{{ \App\Support\BusinessType::emoji($activity['type']) }}</span> {{ \App\Support\BusinessType::label($activity['type']) }}</dd></div>
        <div><dt class="text-xs text-navy-500">{{ __('City') }}</dt><dd class="font-semibold">{{ $activity['city'] ?: __('Not set') }}</dd></div>
        <div><dt class="text-xs text-navy-500">{{ __('Plan') }}</dt><dd class="font-semibold">{{ $sub?->plan?->displayName() ?? '—' }} @if ($sub)<span class="text-xs font-normal text-navy-500">· {{ $sub->statusLabel() }}</span>@endif</dd></div>
        <div><dt class="text-xs text-navy-500">{{ __('Trial') }}</dt><dd @class(['font-semibold', 'text-amber-700' => $sub?->status === 'trialing' && $sub->daysLeft() <= 3])>
            {{ $sub?->status === 'trialing' ? __('Trial days left: :count', ['count' => $sub->daysLeft()]) : __('Not on trial') }}</dd></div>
        <div><dt class="text-xs text-navy-500">{{ __('Signed up') }}</dt><dd class="font-semibold">{{ local_time($tenant->created_at, 'Y/m/d') }} <span class="text-xs font-normal text-navy-500">· {{ $lead->sourceLabel() }}</span></dd></div>
        <div><dt class="text-xs text-navy-500">{{ __('Follows up') }}</dt><dd class="font-semibold">{{ $lead->assignee?->name ?? __('Not assigned') }}</dd></div>
    </dl>
    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
        <a href="{{ $tenant->url() }}" target="_blank" rel="noopener" class="font-semibold text-navy-600 hover:underline" dir="ltr">{{ $tenant->url() }}</a>
        @if ($admin->allows('tenants.view'))<a href="{{ route('admin.tenants.show', $tenant) }}" class="font-semibold text-brand-700 hover:underline">{{ __('Restaurant page') }}</a>@endif
    </div>

    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-lead-stuck>
        <span class="font-bold">{{ __('Where they are:') }}</span> {{ $activity['stuck'] }}
    </div>
    @if ($lead->next_follow_up_at)
        <p @class(['mt-2 text-sm font-semibold', 'text-red-700' => $lead->isOverdue(), 'text-brand-700' => ! $lead->isOverdue()])>
            {{ __('Next follow-up: :at', ['at' => local_time($lead->next_follow_up_at, 'Y/m/d h:i A')]) }}
        </p>
    @endif
    @if ($lead->stage === 'lost')
        <p class="mt-2 text-sm text-navy-600">{{ __('Not interested: :reason', ['reason' => $lead->lostReasonLabel()]) }} @if ($lead->lost_note)· {{ $lead->lost_note }}@endif</p>
    @endif
</section>

<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,380px)] lg:items-start">
    <div class="space-y-4">
        {{-- the guided script --}}
        <section class="card" data-script>
            <div class="mb-3 flex items-baseline justify-between gap-2">
                <h2 class="section-title">{{ __('What to say') }}</h2>
                @if ($canManage && $admin->isOwner())<a href="{{ route('admin.crm.script') }}" class="text-sm font-semibold text-navy-600 hover:underline">{{ __('Edit the script') }}</a>@endif
            </div>
            @if (collect($sections)->flatten()->isEmpty())
                <p class="rounded-xl bg-navy-50 p-4 text-sm text-navy-600" data-script-empty>{{ __('The call script is empty. The platform owner adds the default one from the script page.') }}</p>
            @endif

            @foreach ($sections['opening'] as $item)
                <div class="rounded-2xl bg-navy-800 p-4 text-white" data-script-item="{{ $item->key }}">
                    <div class="text-xs font-bold text-white/60">{{ $item->title }}</div>
                    <p class="mt-1 text-[15px] leading-8">{!! $fill($item->body) !!}</p>
                </div>
            @endforeach

            @foreach ($sections['pitch'] as $item)
                <details class="group mt-3 rounded-2xl border border-navy-100 bg-white" data-script-item="{{ $item->key }}" @if ($loop->first && ! $activity['came_back']) open @endif>
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 font-bold text-navy-900 [&::-webkit-details-marker]:hidden">
                        {{ $item->title }}
                        <svg class="size-4 shrink-0 text-navy-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <p class="px-4 pb-4 text-[15px] leading-8 text-navy-800">{!! $fill($item->body) !!}</p>
                </details>
            @endforeach

            @if ($sections['question']->isNotEmpty())
                <h3 class="mt-5 mb-2 text-sm font-black text-navy-500">{{ __('Questions: tap their answer') }}</h3>
                <div class="space-y-3">
                    @foreach ($sections['question'] as $item)
                        @php($options = $item->answerOptions())
                        <div class="rounded-2xl border border-navy-100 p-4" data-question="{{ $item->key }}">
                            <div class="text-xs font-bold text-navy-500">{{ $item->title }}</div>
                            <p class="mt-0.5 font-bold text-navy-900">{!! $fill($item->body) !!}</p>
                            <input type="hidden" name="answers[{{ $item->key }}]" value="{{ $answers[$item->key] ?? '' }}" form="call-form" data-answer-input>
                            <div class="mt-3 flex flex-wrap gap-2" role="radiogroup" aria-label="{{ $item->title }}">
                                @foreach ($options as $option)
                                    <button type="button" role="radio" aria-checked="{{ ($answers[$item->key] ?? '') === $option['key'] ? 'true' : 'false' }}" data-answer="{{ $option['key'] }}"
                                        class="min-h-10 rounded-full border border-navy-200 bg-white px-3.5 text-sm font-semibold text-navy-700 transition hover:border-navy-400 aria-checked:border-brand-600 aria-checked:bg-brand-600 aria-checked:text-white">{{ $option['label'] }}</button>
                                @endforeach
                            </div>
                            @foreach ($options as $option)
                                <div @class(['mt-3 rounded-xl bg-brand-50 p-3 text-[15px] leading-7 text-navy-900', 'hidden' => ($answers[$item->key] ?? '') !== $option['key']]) data-reply="{{ $option['key'] }}">
                                    <span class="text-xs font-bold text-brand-800">{{ __('Say') }}:</span> {!! $fill($option['reply']) !!}
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($sections['objection']->isNotEmpty())
                <h3 class="mt-5 mb-2 text-sm font-black text-navy-500">{{ __('If they say…') }}</h3>
                <div class="space-y-2">
                    @foreach ($sections['objection'] as $item)
                        <details class="group rounded-2xl border border-navy-100" data-objection="{{ $item->key }}">
                            <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 font-bold text-navy-900 [&::-webkit-details-marker]:hidden">
                                {{ $item->title }}
                                <svg class="size-4 shrink-0 text-navy-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                            </summary>
                            <div class="px-4 pb-4">
                                <p class="text-[15px] leading-8 text-navy-800">{!! $fill($item->body) !!}</p>
                                @if ($canManage)
                                    <label class="mt-2 inline-flex items-center gap-2 text-sm text-navy-600">
                                        <input type="checkbox" name="objections[]" value="{{ $item->key }}" form="call-form" class="size-4 accent-brand-600">
                                        {{ __('They said this on this call') }}
                                    </label>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
            @endif

            @foreach ($sections['closing'] as $item)
                <div class="mt-4 rounded-2xl border border-dashed border-navy-200 p-4" data-script-item="{{ $item->key }}">
                    <div class="text-xs font-bold text-navy-500">{{ $item->title }}</div>
                    <p class="mt-1 text-[15px] leading-8 text-navy-800">{!! $fill($item->body) !!}</p>
                </div>
            @endforeach
        </section>

        {{-- the offer, from the answers tapped so far (on a phone under the script, on a wide screen beside it) --}}
        <section class="card border-navy-200 lg:hidden" data-offer data-offer-url="{{ route('admin.crm.offer', $lead) }}">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="section-title">{{ __('Recommended offer') }}</h2>
                <span class="hidden text-xs text-navy-400" data-offer-loading>{{ __('Updating…') }}</span>
            </div>
            @include('admin.crm.partials.offer', ['advice' => $advice])
        </section>

        @if ($canManage)
            {{-- log the call --}}
            <form method="post" action="{{ route('admin.crm.call', $lead) }}" id="call-form" class="card space-y-4" data-call-form>
                @csrf
                <h2 class="section-title">{{ __('Log this call') }}</h2>
                <div class="flex gap-2" role="radiogroup" aria-label="{{ __('How') }}">
                    @foreach (['call' => __('Phone call'), 'whatsapp' => __('WhatsApp')] as $key => $label)
                        <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border border-navy-200 text-sm font-bold text-navy-700 has-[:checked]:border-navy-800 has-[:checked]:bg-navy-800 has-[:checked]:text-white">
                            <input type="radio" name="channel" value="{{ $key }}" class="sr-only" @checked(old('channel', 'call') === $key) data-channel>{{ $label }}
                        </label>
                    @endforeach
                </div>
                <div>
                    <div class="field-label">{{ __('What happened') }}</div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                        @foreach (\App\Models\Lead::OUTCOMES as $key => $l)
                            <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-200 px-2 text-center text-sm font-semibold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800" data-outcome-option="{{ $key }}">
                                <input type="radio" name="outcome" value="{{ $key }}" class="sr-only" @checked(old('outcome') === $key) required>{{ $en ? $l[1] : $l[0] }}
                            </label>
                        @endforeach
                    </div>
                    @error('outcome')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="field-label" for="call-notes">{{ __('Notes') }}</label>
                    <textarea id="call-notes" name="notes" rows="3" class="field py-2" placeholder="{{ __('What they said, what they need, what you promised') }}">{{ old('notes') }}</textarea>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="call-follow-up">{{ __('Next follow-up') }}</label>
                        <input id="call-follow-up" type="datetime-local" name="next_follow_up_at" value="{{ old('next_follow_up_at') }}" class="field" dir="ltr" data-follow-up>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ([['tomorrow', __('Tomorrow 11 AM')], ['2days', __('In 2 days')], ['week', __('In a week')]] as [$key, $label])
                                <button type="button" class="min-h-9 rounded-full border border-navy-200 px-3 text-xs font-semibold text-navy-700 hover:bg-navy-50" data-follow-up-chip="{{ $key }}">{{ $label }}</button>
                            @endforeach
                        </div>
                        @error('next_follow_up_at')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="field-label" for="call-stage">{{ __('Move to stage') }}</label>
                        <select id="call-stage" name="stage" class="field" data-stage-select @disabled($lead->stage === 'won')>
                            <option value="">{{ $lead->stage === 'won' ? __('Subscribed (stays)') : __('Leave it (new becomes contacted when they answer)') }}</option>
                            @foreach (\App\Models\Lead::MANUAL_STAGES as $key)
                                <option value="{{ $key }}" @selected(old('stage') === $key)>{{ $en ? \App\Models\Lead::STAGES[$key][1] : \App\Models\Lead::STAGES[$key][0] }}</option>
                            @endforeach
                        </select>
                        <select name="lost_reason" class="field mt-2 hidden" data-lost-reason aria-label="{{ __('Why not') }}">
                            <option value="">{{ __('Why not?') }}</option>
                            @foreach (\App\Models\Lead::LOST_REASONS as $key => $l)<option value="{{ $key }}" @selected(old('lost_reason') === $key)>{{ $en ? $l[1] : $l[0] }}</option>@endforeach
                        </select>
                        @error('lost_reason')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="text-xs text-navy-500">{{ __('The answers you tapped above and the objections you ticked are saved with the call.') }}</p>
                <button class="btn-primary w-full sm:w-auto" data-save-call>{{ __('Save the call') }}</button>
            </form>
        @endif
    </div>

    <aside class="space-y-4">
        <section class="card hidden border-2 border-navy-200 lg:block" data-offer data-offer-url="{{ route('admin.crm.offer', $lead) }}">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="section-title">{{ __('Recommended offer') }}</h2>
                <span class="hidden text-xs text-navy-400" data-offer-loading>{{ __('Updating…') }}</span>
            </div>
            @include('admin.crm.partials.offer', ['advice' => $advice])
        </section>

        @if ($canDiscount || $discount)
            <section class="card" id="discount" data-discount-card>
                <h2 class="section-title">{{ __('Discount on the next invoice') }}</h2>
                @if ($discount)
                    <div class="mt-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900" data-active-discount="{{ $discount->id }}">
                        <div class="text-lg font-black">{{ __(':pct% off', ['pct' => money($discount->percent)]) }}</div>
                        <div>{{ __('On :n subscription invoice(s), :left left, until :date', ['n' => $discount->invoices, 'left' => $discount->invoicesLeft(), 'date' => local_time($discount->expires_at, 'Y/m/d')]) }}</div>
                        <div class="mt-1 text-xs text-emerald-800">{{ $discount->note }} · {{ $discount->grantor?->name ?? '—' }}</div>
                        @if ($canDiscount)
                            <form method="post" action="{{ route('admin.crm.discount.revoke', $discount) }}" class="mt-2" onsubmit="return confirm(@js(__('Cancel this discount?')))">@csrf<button class="text-xs font-bold text-red-700 hover:underline">{{ __('Cancel the discount') }}</button></form>
                        @endif
                    </div>
                @else
                    <p class="muted mt-1">{{ __('No discount right now.') }}</p>
                @endif
                @if ($canDiscount)
                    <form method="post" action="{{ route('admin.crm.discount', $lead) }}" class="mt-3 space-y-3" data-discount-form>
                        @csrf
                        <p class="text-xs text-navy-500">{{ __('Your limit: :max%. It comes off their next subscription invoice, shown on it as a line. A new discount replaces the current one.', ['max' => $limit]) }}</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="field-label" for="d-percent">{{ __('Discount %') }}</label>
                                <input id="d-percent" type="number" name="percent" min="1" max="{{ $limit }}" step="1" class="field" dir="ltr" value="{{ old('percent', $suggested) }}" required>
                            </div>
                            <div>
                                <label class="field-label" for="d-invoices">{{ __('Invoices') }}</label>
                                <select id="d-invoices" name="invoices" class="field">
                                    @foreach ([1, 2, 3, 6, 12] as $n)<option value="{{ $n }}" @selected((int) old('invoices', 1) === $n)>{{ $n === 1 ? __('The next one') : __(':n invoices', ['n' => $n]) }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="field-label" for="d-expires">{{ __('Valid until') }}</label>
                            <input id="d-expires" type="date" name="expires_at" class="field" dir="ltr" value="{{ old('expires_at', now()->addDays(7)->toDateString()) }}" min="{{ now()->addDay()->toDateString() }}" max="{{ now()->addDays(180)->toDateString() }}" required>
                        </div>
                        <div>
                            <label class="field-label" for="d-note">{{ __('Why (for the team)') }}</label>
                            <input id="d-note" type="text" name="note" maxlength="255" class="field" value="{{ old('note') }}" placeholder="{{ __('e.g. subscribes this week, price was the only issue') }}" required>
                        </div>
                        @error('percent')<p class="field-error">{{ $message }}</p>@enderror
                        @error('expires_at')<p class="field-error">{{ $message }}</p>@enderror
                        <button class="btn-navy w-full">{{ __('Give the discount') }}</button>
                    </form>
                @endif
                @if ($discounts->count() > ($discount ? 1 : 0))
                    <ul class="mt-3 space-y-1 border-t border-navy-50 pt-3 text-xs text-navy-500">
                        @foreach ($discounts->reject(fn ($d) => $discount && $d->is($discount)) as $d)
                            <li>{{ money($d->percent) }}% · {{ $d->statusLabel() }} · {{ local_time($d->created_at, 'Y/m/d') }} · {{ $d->grantor?->name ?? '—' }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if ($canManage)
            <form method="post" action="{{ route('admin.crm.update', $lead) }}" class="card space-y-3" data-lead-form>
                @csrf
                <h2 class="section-title">{{ __('Lead') }}</h2>
                <div>
                    <label class="field-label" for="l-stage">{{ __('Stage') }}</label>
                    <select id="l-stage" name="stage" class="field" data-stage-select>
                        @if ($lead->stage === 'won')
                            <option value="won" selected>{{ $lead->stageLabel() }}</option>
                        @else
                            @foreach (\App\Models\Lead::MANUAL_STAGES as $key)<option value="{{ $key }}" @selected(old('stage', $lead->stage) === $key)>{{ $en ? \App\Models\Lead::STAGES[$key][1] : \App\Models\Lead::STAGES[$key][0] }}</option>@endforeach
                        @endif
                    </select>
                    <div class="mt-2 hidden space-y-2" data-lost-reason>
                        <select name="lost_reason" class="field" aria-label="{{ __('Why not') }}">
                            <option value="">{{ __('Why not?') }}</option>
                            @foreach (\App\Models\Lead::LOST_REASONS as $key => $l)<option value="{{ $key }}" @selected(old('lost_reason', $lead->lost_reason) === $key)>{{ $en ? $l[1] : $l[0] }}</option>@endforeach
                        </select>
                        <input type="text" name="lost_note" maxlength="255" class="field" value="{{ old('lost_note', $lead->lost_note) }}" placeholder="{{ __('In their words (optional)') }}">
                    </div>
                </div>
                <div>
                    <label class="field-label" for="l-admin">{{ __('Follows up') }}</label>
                    <select id="l-admin" name="assigned_admin_id" class="field">
                        <option value="">{{ __('Not assigned') }}</option>
                        @foreach ($admins as $a)<option value="{{ $a->id }}" @selected((int) old('assigned_admin_id', $lead->assigned_admin_id) === $a->id)>{{ $a->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="field-label" for="l-follow">{{ __('Next follow-up') }}</label>
                    <input id="l-follow" type="datetime-local" name="next_follow_up_at" class="field" dir="ltr" value="{{ old('next_follow_up_at', $lead->next_follow_up_at?->format('Y-m-d\TH:i')) }}">
                </div>
                <div>
                    <label class="field-label" for="l-notes">{{ __('Notes about this customer') }}</label>
                    <textarea id="l-notes" name="notes" rows="3" class="field py-2">{{ old('notes', $lead->notes) }}</textarea>
                </div>
                @foreach (['stage', 'lost_reason', 'assigned_admin_id'] as $field)@error($field)<p class="field-error">{{ $message }}</p>@enderror @endforeach
                <button class="btn-ghost w-full">{{ __('Save') }}</button>
            </form>
        @endif

        {{-- what they did so far, from real data --}}
        <section class="card" data-activity>
            <h2 class="section-title mb-2">{{ __('What they did so far') }}</h2>
            <ul class="divide-y divide-navy-50 text-sm">
                @foreach ($activity['rows'] as $row)
                    <li class="flex items-start gap-2.5 py-2" data-signal="{{ $row['key'] }}" data-state="{{ $row['state'] }}">
                        <span class="mt-2 size-2 shrink-0 rounded-full {{ $stateDot[$row['state']] ?? 'bg-navy-200' }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs text-navy-500">{{ $row['label'] }}</span>
                            <span class="block font-semibold text-navy-900">{{ $row['value'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- the timeline --}}
        <section class="card" data-timeline>
            <h2 class="section-title mb-2">{{ __('Timeline') }}</h2>
            <ol class="relative space-y-4 border-s border-navy-100 ps-4">
                @forelse ($events as $event)
                    @php($meta = (array) ($event->meta ?? []))
                    <li class="relative" data-event="{{ $event->type }}">
                        <span @class(['absolute -start-[21px] top-1.5 size-2.5 rounded-full ring-4 ring-white',
                            'bg-emerald-500' => in_array($event->type, ['won', 'discount'], true) || $event->outcome === 'answered',
                            'bg-amber-500' => in_array($event->outcome, ['no_answer', 'busy'], true),
                            'bg-red-500' => $event->outcome === 'wrong_number',
                            'bg-navy-400' => ! in_array($event->type, ['won', 'discount'], true) && ! in_array($event->outcome, ['answered', 'no_answer', 'busy', 'wrong_number'], true)])></span>
                        <div class="flex flex-wrap items-center gap-x-2 text-sm">
                            <span class="font-bold text-navy-900">{{ $event->typeLabel() }}</span>
                            @if ($event->outcome)<span class="badge bg-navy-100 text-navy-700">{{ $event->outcomeLabel() }}</span>@endif
                            <span class="text-xs text-navy-500">{{ local_time($event->created_at, 'd/m h:i A') }} · {{ $event->admin?->name ?? __('System') }}</span>
                        </div>
                        @if (isset($meta['to']) && in_array($event->type, ['stage', 'call', 'whatsapp'], true))
                            <div class="text-xs text-navy-600">{{ __('Stage: :from → :to', ['from' => \App\Models\Lead::pick(\App\Models\Lead::STAGES[$meta['from'] ?? ''] ?? ['—', '—']), 'to' => \App\Models\Lead::pick(\App\Models\Lead::STAGES[$meta['to']] ?? [$meta['to'], $meta['to']])]) }}</div>
                        @endif
                        @if ($event->type === 'assign')<div class="text-xs text-navy-600">{{ $meta['to'] ?? __('Not assigned') }}</div>@endif
                        @if ($event->type === 'won')<div class="text-xs text-emerald-700">{{ __('Paid :total EGP · :number', ['total' => money($meta['total'] ?? 0), 'number' => $meta['invoice'] ?? '']) }}</div>@endif
                        @if ($event->type === 'discount')
                            <div class="text-xs text-emerald-700">{{ isset($meta['revoked']) ? __('Cancelled the :pct% discount', ['pct' => money($meta['percent'] ?? 0)]) : __(':pct% on :n invoice(s), until :date', ['pct' => money($meta['percent'] ?? 0), 'n' => $meta['invoices'] ?? 1, 'date' => $meta['expires_at'] ?? '']) }}</div>
                        @endif
                        @if ($event->answers)
                            <div class="mt-1 flex flex-wrap gap-1">
                                @foreach ($event->answers as $q => $opt)
                                    @php($question = $sections['question']->firstWhere('key', $q))
                                    @php($label = collect($question?->answerOptions() ?? [])->firstWhere('key', $opt)['label'] ?? $opt)
                                    <span class="badge bg-brand-50 text-brand-800">{{ $question?->title ?? $q }}: {{ $label }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if ($event->objections)
                            <div class="mt-1 text-xs text-navy-600">{{ __('Said:') }} {{ collect($event->objections)->map(fn ($k) => $sections['objection']->firstWhere('key', $k)?->title ?? $k)->implode('، ') }}</div>
                        @endif
                        @if ($event->notes)<p class="mt-1 whitespace-pre-line text-sm text-navy-800">{{ $event->notes }}</p>@endif
                        @if ($event->next_follow_up_at)<div class="mt-0.5 text-xs text-navy-500">{{ __('Follow up :at', ['at' => local_time($event->next_follow_up_at, 'd/m h:i A')]) }}</div>@endif
                    </li>
                @empty
                    <li class="text-sm text-navy-500">{{ __('No calls yet. The first one is yours.') }}</li>
                @endforelse
            </ol>
        </section>
    </aside>
</div>

{{-- on a phone: call and WhatsApp always under the thumb --}}
@if ($telUrl || $whatsappUrl)
    <div class="h-16 lg:hidden" aria-hidden="true"></div>
    <div class="fixed inset-x-0 bottom-0 z-30 flex gap-2 border-t border-navy-100 bg-white/95 px-4 py-2.5 backdrop-blur lg:hidden" data-call-bar>
        @if ($telUrl)<a href="{{ $telUrl }}" class="btn flex-1 bg-emerald-600 text-white">{{ __('Call') }}</a>@endif
        @if ($whatsappUrl)<a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="btn flex-1 border border-emerald-200 bg-emerald-50 text-emerald-800">{{ __('WhatsApp') }}</a>@endif
        @if ($canManage)<a href="#call-form" class="btn-ghost flex-1">{{ __('Log') }}</a>@endif
    </div>
@endif
@endsection

@push('scripts')
<script>
    (() => {
        const offers = document.querySelectorAll('[data-offer]');
        let timer = null;
        const refreshOffer = () => {
            if (!offers.length) return;
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const params = new URLSearchParams();
                document.querySelectorAll('[data-answer-input]').forEach((input) => { if (input.value) params.append(input.name, input.value); });
                offers.forEach((o) => o.querySelector('[data-offer-loading]')?.classList.remove('hidden'));
                try {
                    const res = await fetch(offers[0].dataset.offerUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (res.ok) {
                        const html = await res.text();
                        offers.forEach((o) => { o.querySelector('[data-offer-body]').outerHTML = html; });
                        const pct = offers[0].querySelector('[data-offer-discount]')?.dataset.offerDiscount;
                        const field = document.getElementById('d-percent');
                        if (pct && field && !field.dataset.touched) field.value = pct;
                    }
                } finally {
                    offers.forEach((o) => o.querySelector('[data-offer-loading]')?.classList.add('hidden'));
                }
            }, 250);
        };
        document.getElementById('d-percent')?.addEventListener('input', (e) => { e.target.dataset.touched = '1'; });

        // tapping an answer: it is picked (tap again to clear), its reply shows, the call form carries it
        document.querySelectorAll('[data-question]').forEach((box) => {
            const input = box.querySelector('[data-answer-input]');
            box.querySelectorAll('[data-answer]').forEach((button) => button.addEventListener('click', () => {
                const value = input.value === button.dataset.answer ? '' : button.dataset.answer;
                input.value = value;
                box.querySelectorAll('[data-answer]').forEach((b) => b.setAttribute('aria-checked', b.dataset.answer === value ? 'true' : 'false'));
                box.querySelectorAll('[data-reply]').forEach((r) => r.classList.toggle('hidden', r.dataset.reply !== value));
                refreshOffer();
            }));
        });

        // "not interested" asks why
        document.querySelectorAll('form').forEach((form) => {
            const stage = form.querySelector('[data-stage-select]');
            const reason = form.querySelector('[data-lost-reason]');
            if (!stage || !reason) return;
            const sync = () => reason.classList.toggle('hidden', stage.value !== 'lost');
            stage.addEventListener('change', sync);
            sync();
        });

        // quick follow-up times (local time of the browser, Cairo for the team)
        const followUp = document.querySelector('[data-follow-up]');
        const pad = (n) => String(n).padStart(2, '0');
        document.querySelectorAll('[data-follow-up-chip]').forEach((chip) => chip.addEventListener('click', () => {
            const d = new Date();
            const days = { tomorrow: 1, '2days': 2, week: 7 }[chip.dataset.followUpChip] || 1;
            d.setDate(d.getDate() + days);
            d.setHours(11, 0, 0, 0);
            followUp.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        }));
    })();
</script>
@endpush
