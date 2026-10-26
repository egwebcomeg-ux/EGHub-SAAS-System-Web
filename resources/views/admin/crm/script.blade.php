@use('App\Models\CallScriptItem')
@use('App\Services\Crm\CallScript')
@extends('layouts.admin')
@section('title', __('Call script') . ' · EgHub Admin')

@section('content')
@php
    $en = app()->getLocale() === 'en';
    $optionRows = function (?CallScriptItem $item): array {
        $rows = $item?->answerOptions() ?? [];
        // two empty rows to add answers
        return [...$rows, ['key' => '', 'label' => '', 'reply' => '', 'signal' => ''], ['key' => '', 'label' => '', 'reply' => '', 'signal' => '']];
    };
@endphp

<a href="{{ route('admin.crm') }}" class="text-sm font-semibold text-navy-500 hover:text-navy-800">← {{ __('Customer follow-up') }}</a>
<div class="mt-1 mb-4 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Call script') }}</h1>
        <p class="muted max-w-2xl">{{ __('What the callers read on every lead: write it the way you talk on the phone. Answers carry a signal the recommended offer uses. Prices are placeholders filled from the live plans, so the script never states a wrong number.') }}</p>
    </div>
    <form method="post" action="{{ route('admin.crm.script.defaults') }}">@csrf<button class="btn-ghost" data-install-defaults>{{ __('Add the default script') }}</button></form>
</div>

<details class="card mb-4" data-placeholders>
    <summary class="cursor-pointer font-bold text-navy-900">{{ __('Words filled in on the call') }}</summary>
    <dl class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
        @foreach (CallScript::PLACEHOLDERS as $token => $label)
            <div class="flex gap-2"><dt><code class="rounded bg-navy-50 px-1.5 text-xs" dir="ltr">{{ $token }}</code></dt><dd class="text-navy-600">{{ $en ? $label[1] : $label[0] }}</dd></div>
        @endforeach
    </dl>
</details>

@foreach (CallScriptItem::KINDS as $kind => $kindLabel)
    <section class="mb-6" data-kind="{{ $kind }}">
        <h2 class="section-title mb-2">{{ $en ? $kindLabel[1] : $kindLabel[0] }}</h2>
        <div class="space-y-3">
            @foreach ($items->get($kind, collect()) as $item)
                {{-- folded to its title; opens when tapped or right after it was saved (#item-id) --}}
                <details id="item-{{ $item->id }}" @class(['card group scroll-mt-24 p-0', 'opacity-60' => ! $item->is_active]) data-script-item="{{ $item->key }}">
                    <summary class="flex min-h-14 cursor-pointer list-none items-center gap-2 px-5 py-3 [&::-webkit-details-marker]:hidden">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-bold text-navy-900">{{ $item->title }}</span>
                            <span class="block truncate text-xs text-navy-500">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' ', $item->body), 110) }}</span>
                        </span>
                        @if ($kind === 'question')<span class="badge bg-brand-50 text-brand-800">{{ __(':n answers', ['n' => count($item->answerOptions())]) }}</span>@endif
                        @unless ($item->is_active)<span class="badge bg-navy-100 text-navy-600">{{ __('Hidden') }}</span>@endunless
                        <svg class="size-4 shrink-0 text-navy-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <div class="border-t border-navy-50 px-5 pt-3 pb-5">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            @if (CallScript::isDefault($item->key))<span class="badge bg-navy-50 text-navy-500">{{ __('Default') }}</span>@endif
                        </div>
                        <div class="flex gap-1">
                            @foreach (['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                                <form method="post" action="{{ route('admin.crm.script.move', $item) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button class="btn-ghost min-h-9 px-3" aria-label="{{ $direction === 'up' ? __('Move up') : __('Move down') }}">{{ $arrow }}</button></form>
                            @endforeach
                            <form method="post" action="{{ route('admin.crm.script.delete', $item) }}" onsubmit="return confirm(@js(CallScript::isDefault($item->key) ? __('Hide this from the call screen?') : __('Remove this from the script?')))">@csrf
                                <button class="btn-ghost min-h-9 px-3 text-red-700">{{ CallScript::isDefault($item->key) ? __('Hide') : __('Remove') }}</button>
                            </form>
                        </div>
                    </div>
                    <form method="post" action="{{ route('admin.crm.script.update', $item) }}" class="space-y-3">
                        @csrf
                        @include('admin.crm.partials.script-fields', ['item' => $item, 'kind' => $kind, 'rows' => $optionRows($item)])
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label class="inline-flex items-center gap-2 text-sm font-semibold text-navy-700">
                                <input type="checkbox" name="is_active" value="1" @checked($item->is_active) class="size-4 accent-brand-600"> {{ __('Show on the call screen') }}
                            </label>
                            <button class="btn-primary">{{ __('Save') }}</button>
                        </div>
                    </form>
                    </div>
                </details>
            @endforeach
            <details class="rounded-card border border-dashed border-navy-200 bg-white/60 p-4" data-add-item="{{ $kind }}">
                <summary class="cursor-pointer text-sm font-bold text-navy-700">+ {{ __('Add to this part') }}</summary>
                <form method="post" action="{{ route('admin.crm.script.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    @include('admin.crm.partials.script-fields', ['item' => null, 'kind' => $kind, 'rows' => $optionRows(null)])
                    <button class="btn-primary">{{ __('Add') }}</button>
                </form>
            </details>
        </div>
    </section>
@endforeach
@endsection

@push('scripts')
<script>
    // the item just saved opens again
    if (location.hash.startsWith('#item-')) {
        const item = document.querySelector(location.hash);
        if (item) { item.open = true; item.scrollIntoView({ block: 'start' }); }
    }
</script>
@endpush
