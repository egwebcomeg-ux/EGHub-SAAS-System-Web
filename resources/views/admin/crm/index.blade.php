@extends('layouts.admin')
@section('title', __('Customer follow-up') . ' · EgHub Admin')

@section('content')
@php
    $f = $filters;
    $en = app()->getLocale() === 'en';
    $canManage = $admin->allows('crm.manage');
    // the business type of each listed place, one query (the restaurants list reads it the same way)
    $types = \App\Services\AdminTenantSearch::typesOf($leads->pluck('tenant_id')->merge($queue->pluck('tenant_id'))->unique());
    $link = fn (array $extra) => route('admin.crm', array_filter([...$f, ...$extra], fn ($v) => $v !== '' && $v !== 'newest'));
    $followUpLabel = function ($lead): array {
        if (! $lead->next_follow_up_at) {
            return ['', ''];
        }
        if ($lead->isOverdue()) {
            return ['text-red-700', __('Overdue since :at', ['at' => local_time($lead->next_follow_up_at, 'd/m h:i A')])];
        }
        if ($lead->isDueToday()) {
            return ['text-brand-700', __('Today :at', ['at' => local_time($lead->next_follow_up_at, 'h:i A')])];
        }

        return ['text-navy-600', local_time($lead->next_follow_up_at, 'd/m h:i A')];
    };
    $trialLeft = function ($lead): ?int {
        if (! $lead->trial_ends_at) {
            return null;
        }
        $end = \Illuminate\Support\Carbon::parse($lead->trial_ends_at);

        return $end->isPast() ? 0 : (int) ceil(now()->diffInHours($end) / 24);
    };
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Customer follow-up') }}</h1>
        <p class="muted">{{ __('Every new sign-up is here. Call them, see where they got stuck, and help them start.') }}</p>
    </div>
    @if ($canManage && $admin->isOwner())
        <a href="{{ route('admin.crm.script') }}" class="btn-ghost" data-script-link>{{ __('Edit the call script') }}</a>
    @endif
</div>

{{-- the three numbers that matter in the morning --}}
<div class="mb-4 grid grid-cols-3 gap-2 sm:gap-3" data-crm-counts>
    @foreach ([
        ['uncontacted', __('New, not contacted'), $link(['stage' => 'new', 'due' => '']), 'text-brand-700'],
        ['today', __('Due today'), $link(['due' => 'today', 'stage' => '']), 'text-navy-900'],
        ['overdue', __('Overdue'), $link(['due' => 'overdue', 'stage' => '']), 'text-red-700'],
    ] as [$key, $label, $href, $tone])
        <a href="{{ $href }}" class="card block p-3 transition hover:border-navy-200 hover:shadow-md sm:p-4" data-count="{{ $key }}">
            <div class="text-xs font-semibold text-navy-500 sm:text-sm">{{ $label }}</div>
            <div class="mt-0.5 text-2xl font-black sm:text-3xl {{ $counts[$key] > 0 ? $tone : 'text-navy-300' }}">{{ number_format($counts[$key]) }}</div>
        </a>
    @endforeach
</div>

{{-- today's queue: follow-ups due, then new sign-ups nobody called yet (mine and unassigned) --}}
<section class="card mb-4" data-call-queue>
    <div class="mb-2 flex items-baseline justify-between gap-2">
        <h2 class="section-title">{{ __('Today\'s calls') }}</h2>
        <span class="text-sm text-navy-500">{{ __(':n to call', ['n' => $queue->count()]) }}</span>
    </div>
    @if ($queue->isEmpty())
        <p class="py-6 text-center text-sm text-navy-500">{{ __('Nothing to call right now. New sign-ups show up here by themselves.') }}</p>
    @else
        <ul class="divide-y divide-navy-50">
            @foreach ($queue as $lead)
                @php
                    [$fuTone, $fuText] = $followUpLabel($lead);
                    $type = $types[$lead->tenant_id] ?? null;
                @endphp
                <li class="flex items-center gap-3 py-2.5" data-queue-lead="{{ $lead->id }}">
                    <a href="{{ route('admin.crm.show', $lead) }}" class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span class="truncate font-bold text-navy-900">{{ $lead->tenant->name }}</span>
                            <span class="badge {{ $lead->stageClasses() }}">{{ $lead->stageLabel() }}</span>
                        </span>
                        <span class="block truncate text-xs text-navy-500">
                            {{ $lead->owner_name }}
                            @if ($type) · <span aria-hidden="true">{{ \App\Support\BusinessType::emoji($type) }}</span> {{ \App\Support\BusinessType::label($type) }}@endif
                            · {{ $fuText ?: __('Signed up :ago', ['ago' => $lead->tenant->created_at?->diffForHumans()]) }}
                        </span>
                    </a>
                    @if ($lead->owner_phone)
                        <a href="tel:{{ $lead->owner_phone }}" class="grid size-11 shrink-0 place-items-center rounded-full bg-emerald-600 text-white shadow-sm hover:bg-emerald-700" aria-label="{{ __('Call :name', ['name' => $lead->owner_name]) }}" data-tel>
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>

<form class="card mb-4 grid gap-3" method="get" action="{{ route('admin.crm') }}" data-crm-filters>
    <div class="flex flex-col gap-2 sm:flex-row">
        <input class="field flex-1" type="search" name="q" value="{{ $f['q'] }}" placeholder="{{ __('Name, link, mobile or email') }}" aria-label="{{ __('Search') }}">
        <div class="flex gap-2">
            <button class="btn-primary flex-1 sm:flex-none">{{ __('Filter') }}</button>
            @if ($filtered)<a href="{{ route('admin.crm') }}" class="btn-ghost flex-1 sm:flex-none">{{ __('Reset') }}</a>@endif
        </div>
    </div>
    <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 md:flex-wrap" data-quick-filters>
        @foreach ([['mine', __('Assigned to me')], ['recent', __('Signed up in the last 7 days')], ['trial', __('Trial ends within 3 days')]] as [$key, $label])
            <label class="inline-flex min-h-10 shrink-0 cursor-pointer items-center gap-2 rounded-full border border-navy-200 bg-white px-3.5 text-sm font-semibold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800">
                <input type="checkbox" name="{{ $key }}" value="{{ $key === 'trial' ? 'ending' : '1' }}" @checked($f[$key] !== '') class="size-4 accent-brand-600" onchange="this.form.requestSubmit()">
                {{ $label }}
            </label>
        @endforeach
    </div>
    {{-- a phone keeps the other filters folded until asked; wide screens always show them --}}
    <details class="group" data-more-filters @if ($f['stage'].$f['due'].$f['type'].$f['plan'] !== '' || $f['sort'] !== 'newest') open @endif>
    <summary class="flex min-h-10 cursor-pointer list-none items-center gap-1 text-sm font-bold text-navy-700 md:hidden [&::-webkit-details-marker]:hidden">{{ __('More filters') }} <span class="transition group-open:rotate-180" aria-hidden="true">▾</span></summary>
    <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Stage') }}
            <select class="field" name="stage">
                <option value="">{{ __('All stages') }}</option>
                <option value="open" @selected($f['stage'] === 'open')>{{ __('Still open') }}</option>
                @foreach (\App\Models\Lead::STAGES as $key => $l)<option value="{{ $key }}" @selected($f['stage'] === $key)>{{ $en ? $l[1] : $l[0] }}</option>@endforeach
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Follow-up') }}
            <select class="field" name="due">
                <option value="">{{ __('Any time') }}</option>
                <option value="due" @selected($f['due'] === 'due')>{{ __('Today and overdue') }}</option>
                <option value="today" @selected($f['due'] === 'today')>{{ __('Today') }}</option>
                <option value="overdue" @selected($f['due'] === 'overdue')>{{ __('Overdue') }}</option>
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Business type') }}
            <select class="field" name="type">
                <option value="">{{ __('All types') }}</option>
                @foreach (\App\Support\BusinessType::TYPES as $key => $l)<option value="{{ $key }}" @selected($f['type'] === $key)>{{ $en ? $l[1] : $l[0] }}</option>@endforeach
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Plan') }}
            <select class="field" name="plan">
                <option value="">{{ __('All plans') }}</option>
                @foreach ($plans as $p)<option value="{{ $p->code }}" @selected($f['plan'] === $p->code)>{{ $p->displayName() }}</option>@endforeach
            </select>
        </label>
        <label class="grid gap-1 text-xs font-semibold text-navy-600">{{ __('Sort') }}
            <select class="field" name="sort">
                <option value="newest" @selected($f['sort'] === 'newest')>{{ __('Newest first') }}</option>
                <option value="oldest" @selected($f['sort'] === 'oldest')>{{ __('Oldest first') }}</option>
                <option value="follow_up" @selected($f['sort'] === 'follow_up')>{{ __('Next follow-up') }}</option>
                <option value="trial" @selected($f['sort'] === 'trial')>{{ __('Trial ends first') }}</option>
                <option value="name" @selected($f['sort'] === 'name')>{{ __('Name') }}</option>
            </select>
        </label>
    </div>
    </details>
</form>

<div class="card p-0 sm:p-0" data-leads>
    <div class="flex items-baseline justify-between gap-2 px-4 pt-4 sm:px-5">
        <h2 class="section-title">{{ __('Leads') }}</h2>
        <span class="text-sm text-navy-500">{{ __(':count leads', ['count' => number_format($leads->total())]) }}</span>
    </div>
    <ul class="mt-2 divide-y divide-navy-50">
        @forelse ($leads as $lead)
            @php
                [$fuTone, $fuText] = $followUpLabel($lead);
                $type = $types[$lead->tenant_id] ?? null;
                $sub = $lead->tenant->subscription;
                $left = $trialLeft($lead);
            @endphp
            <li class="flex items-start gap-3 px-4 py-3 transition hover:bg-navy-50/60 sm:px-5" data-lead-row="{{ $lead->id }}">
                <a href="{{ route('admin.crm.show', $lead) }}" class="grid min-w-0 flex-1 gap-1 md:grid-cols-[minmax(0,2fr)_minmax(0,1.3fr)_minmax(0,1fr)] md:items-center md:gap-4">
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="truncate font-bold text-navy-900">{{ $lead->tenant->name }}</span>
                            <span class="badge {{ $lead->stageClasses() }}">{{ $lead->stageLabel() }}</span>
                            @if ($lead->source === 'manual')<span class="badge bg-navy-100 text-navy-600">{{ $lead->sourceLabel() }}</span>@endif
                        </span>
                        <span class="block truncate text-xs text-navy-500">
                            {{ $lead->owner_name }} · <span dir="ltr">{{ $lead->owner_phone ?: '—' }}</span>
                            @if ($type) · <span aria-hidden="true">{{ \App\Support\BusinessType::emoji($type) }}</span> {{ \App\Support\BusinessType::label($type) }}@endif
                        </span>
                    </span>
                    <span class="text-xs text-navy-600">
                        <span class="font-semibold">{{ $sub?->plan?->displayName() ?? '—' }}</span>
                        @if ($left !== null)
                            · <span @class(['font-bold text-amber-700' => $left <= 3])>{{ __('Trial days left: :count', ['count' => $left]) }}</span>
                        @endif
                        <span class="block text-navy-500">{{ __('Signed up :date', ['date' => local_time($lead->tenant->created_at, 'Y/m/d')]) }}</span>
                    </span>
                    <span class="text-xs">
                        @if ($fuText)<span class="block font-semibold {{ $fuTone }}">{{ $fuText }}</span>@endif
                        <span class="block text-navy-500">{{ $lead->assignee?->name ?? __('Not assigned') }}</span>
                    </span>
                </a>
                @if ($lead->owner_phone)
                    <a href="tel:{{ $lead->owner_phone }}" class="grid size-10 shrink-0 place-items-center rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100" aria-label="{{ __('Call :name', ['name' => $lead->owner_name]) }}">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>
                    </a>
                @endif
            </li>
        @empty
            <li class="px-5 py-10 text-center text-sm text-navy-500">{{ __('No leads match these filters.') }}</li>
        @endforelse
    </ul>
    <div class="px-4 pb-4 sm:px-5">{{ $leads->links() }}</div>
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-more-filters]').forEach((d) => { if (matchMedia('(min-width: 768px)').matches) d.open = true; });
</script>
@endpush
