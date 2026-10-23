@extends('layouts.dashboard')
@php
    $editing = $article->exists;
    $visibility = old('visibility', $article->visibility ?? 'internal');
@endphp
@section('title', $editing ? __('Edit answer') : __('New answer'))

@section('content')
<x-dash.page-header :title="$editing ? __('Edit answer') : __('New answer')" :help="__('Plain text: line breaks stay, and a link that starts with http shows as a link. Leave the English empty to show the Arabic to everyone.')">
    <x-slot:actions>
        <a href="{{ route('dash.kb') }}" class="btn-ghost">{{ __('Back') }}</a>
    </x-slot:actions>
</x-dash.page-header>

<form method="post" action="{{ $editing ? route('dash.kb.update', $article) : route('dash.kb.store') }}" class="grid max-w-5xl gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]" data-kb-form>
    @csrf
    <section class="card space-y-3">
        <label class="block"><span class="field-label">{{ __('The question') }} *</span>
            <input class="field" name="question" value="{{ old('question', $article->question) }}" required maxlength="255" dir="auto" placeholder="{{ __('e.g. Do you deliver to Maadi?') }}"></label>
        <label class="block"><span class="field-label">{{ __('The answer') }} *</span>
            <textarea class="field min-h-40" name="answer" required maxlength="5000" rows="7" dir="auto" placeholder="{{ __('e.g. Yes, the delivery fee is 25 EGP and it takes about 40 minutes.') }}">{{ old('answer', $article->answer) }}</textarea></label>
        <details class="rounded-xl bg-navy-50 p-3" @if (old('question_en', $article->question_en) || old('answer_en', $article->answer_en)) open @endif>
            <summary class="cursor-pointer font-semibold">{{ __('In English (optional)') }}</summary>
            <div class="mt-3 space-y-3">
                <label class="block"><span class="field-label">{{ __('The question in English') }}</span>
                    <input class="field" name="question_en" value="{{ old('question_en', $article->question_en) }}" maxlength="255" dir="ltr"></label>
                <label class="block"><span class="field-label">{{ __('The answer in English') }}</span>
                    <textarea class="field min-h-32" name="answer_en" maxlength="5000" rows="5" dir="ltr">{{ old('answer_en', $article->answer_en) }}</textarea></label>
            </div>
        </details>
        <label class="block"><span class="field-label">{{ __('Tags (words the team may search with)') }}</span>
            <input class="field" name="tags" value="{{ old('tags', $article->tags) }}" maxlength="255" dir="auto" placeholder="{{ __('e.g. delivery, Maadi, fee') }}"></label>
    </section>

    <aside class="space-y-4">
        <fieldset class="card space-y-2">
            <legend class="section-title mb-1">{{ __('Where does it show?') }}</legend>
            @foreach (['internal' => ['🔒', __('Staff only'), __('The call center, the POS and the order board. Good for policies and what to do when.')],
                       'public' => ['🌐', __('Staff and guests'), __('Also on your place’s page as a frequently asked question.')]] as $key => [$icon, $title, $text])
                <label class="flex cursor-pointer gap-3 rounded-xl border border-navy-100 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                    <input type="radio" name="visibility" value="{{ $key }}" class="mt-1" @checked($visibility === $key) data-kb-visibility="{{ $key }}">
                    <span><span class="font-bold">{{ $icon }} {{ $title }}</span><span class="block text-xs text-navy-500">{{ $text }}</span></span>
                </label>
            @endforeach
        </fieldset>
        <section class="card space-y-3">
            <label class="block"><span class="field-label">{{ __('Category') }}</span>
                <select class="field" name="category_id">
                    <option value="">{{ __('Without a category') }}</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) old('category_id', $article->category_id) === $c->id)>{{ $c->label() }}</option>@endforeach
                </select></label>
            <label class="flex min-h-11 items-center gap-2 font-semibold"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $article->is_active ?? true))> {{ __('On (off = a draft nobody sees)') }}</label>
            <button class="btn-primary w-full">{{ __('Save') }}</button>
        </section>
        @if ($editing)
            <p class="text-xs text-navy-500">{{ __('Updated :when', ['when' => local_time($article->updated_at)]) }}@if ($article->editor) · {{ $article->editor->name }}@endif</p>
        @endif
    </aside>
</form>
@if ($editing)
    <form method="post" action="{{ route('dash.kb.delete', $article) }}" class="mt-4" onsubmit="return confirm(@js(__('Delete this answer?')))">@csrf
        <button class="min-h-11 text-sm text-red-600">{{ __('Delete this answer') }}</button></form>
@endif
@endsection
