@extends('layouts.admin')
@section('title', __('Calls') . ' · EgHub Admin')

@section('content')
@php
    $contactUrl = $call->lead ? route('admin.crm.show', $call->lead) : ($call->prospect ? route('admin.crm.prospects.show', $call->prospect) : null);
@endphp
@include('admin.crm.partials.tabs')

<div class="mb-4">
    <a href="{{ route('admin.crm.calls') }}" class="text-sm font-semibold text-navy-500 hover:underline">← {{ __('Calls') }}</a>
    <h1 class="page-title mt-1">{{ $call->contactName() }}</h1>
    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-navy-600">
        <span class="badge {{ $call->directionClasses() }}">{{ $call->directionLabel() }}</span>
        @if ($call->phone)<bdi dir="ltr">{{ $call->phone }}</bdi>@endif
        <span>· {{ local_time($call->started_at, 'Y/m/d h:i A') }}</span>
        <span class="tabular-nums">· {{ $call->durationLabel() }}</span>
        @if ($call->admin)<span>· {{ $call->admin->name }}</span>@endif
    </div>
    <div class="mt-3 flex flex-wrap gap-2">
        @if ($call->phone)<a href="tel:{{ $call->phone }}" class="btn bg-emerald-600 text-white">{{ __('Call') }}</a>@endif
        @if ($contactUrl)<a href="{{ $contactUrl }}" class="btn-ghost">{{ __('Open the file') }}</a>@endif
    </div>
</div>

<form method="post" action="{{ route('admin.crm.calls.summarize', $call) }}" class="card mb-4 space-y-3" data-call-summary>
    @csrf
    <h2 class="section-title">{{ __('The call summary') }}</h2>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach (array_diff_key(\App\Models\Lead::OUTCOMES, ['sent' => true]) as $key => $label)
            <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-200 px-2 text-center text-sm font-semibold text-navy-700 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800">
                <input type="radio" name="outcome" value="{{ $key }}" class="sr-only" required @checked(old('outcome', $call->outcome ?? ($call->wasTalked() ? 'answered' : 'no_answer')) === $key)>{{ \App\Models\Lead::pick($label) }}
            </label>
        @endforeach
    </div>
    @error('outcome')<p class="field-error">{{ $message }}</p>@enderror
    <textarea name="notes" rows="4" class="field py-2" dir="auto" placeholder="{{ __('What they said, what they need, what you promised') }}">{{ old('notes', $call->notes) }}</textarea>
    @error('notes')<p class="field-error">{{ $message }}</p>@enderror
    @if (! $call->summarized_at && ($call->lead || $call->prospect))
        <div class="sm:max-w-xs"><label class="field-label" for="call-follow">{{ __('Next follow-up') }}</label>
            <input id="call-follow" type="datetime-local" name="next_follow_up_at" class="field" dir="ltr" value="{{ old('next_follow_up_at') }}"></div>
    @endif
    <button class="btn-primary">{{ __('Save the call') }}</button>
</form>

@if ($call->phone && ! $call->lead_id && ! $call->prospect_id)
    <form method="post" action="{{ route('admin.crm.calls.place', $call) }}" class="card space-y-3" data-call-new-place>
        @csrf
        <h2 class="section-title">{{ __('This number is new. Add it as a place') }}</h2>
        <div class="grid gap-3 sm:grid-cols-[1fr_14rem]">
            <div><label class="field-label" for="call-place-name">{{ __('Place name') }}</label>
                <input id="call-place-name" name="name" class="field" required maxlength="150" value="{{ old('name') }}">
                @error('name')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div><label class="field-label" for="call-place-kind">{{ __('Kind') }}</label>
                <select id="call-place-kind" name="category" class="field">
                    @foreach (\App\Models\Prospect::CATEGORIES as $key => $l)<option value="{{ $key }}">{{ $l[2] }} {{ \App\Models\Lead::pick($l) }}</option>@endforeach
                </select></div>
        </div>
        <button class="btn-navy">{{ __('Save the place') }}</button>
    </form>
@endif
@endsection
