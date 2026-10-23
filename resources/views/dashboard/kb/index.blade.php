@extends('layouts.dashboard')
@section('title', __('Knowledge base'))

@section('content')
@php
    $base = array_filter(['q' => $q, 'category' => $categoryId]);
    $tabs = [
        ['all', __('All'), route('dash.kb', $base), $counts['all']],
        ['public', __('On the menu'), route('dash.kb', $base + ['show' => 'public']), $counts['public']],
        ['internal', __('Staff only'), route('dash.kb', $base + ['show' => 'internal']), $counts['internal']],
        ['drafts', __('Drafts'), route('dash.kb', $base + ['show' => 'drafts']), $counts['drafts']],
    ];
    $categoryNames = $categories->keyBy('id');
    $canReorder = ! $filtered;
@endphp
<x-dash.page-header :title="__('Knowledge base')" :help="__('The answers your team gives every day. Staff answers show in the call center, the POS and the order board; public ones also show to guests on your place’s page.')">
    <x-slot:actions>
        <a href="{{ route('dash.kb.create') }}" class="btn-primary" data-kb-new>＋ {{ __('New answer') }}</a>
        <form method="post" action="{{ route('dash.kb.suggested') }}" data-kb-suggested>
            @csrf
            <button class="btn-ghost">✨ {{ __('Add suggested questions') }}</button>
        </form>
    </x-slot:actions>
</x-dash.page-header>

@include('dashboard.partials.tabs', ['tabs' => $tabs, 'current' => $filter, 'label' => __('Which answers')])

<div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="min-w-0 space-y-4">
        <form class="card flex flex-wrap items-end gap-2" data-kb-search>
            @if ($filter !== 'all')<input type="hidden" name="show" value="{{ $filter }}">@endif
            <label class="block min-w-0 flex-1 basis-56"><span class="field-label">{{ __('Search') }}</span>
                <input type="search" class="field" name="q" value="{{ $q }}" maxlength="80" placeholder="{{ __('A word of the question, the answer or a tag') }}" dir="auto" autocomplete="off"></label>
            @if ($categories->isNotEmpty())
                <label class="block w-full sm:w-52"><span class="field-label">{{ __('Category') }}</span>
                    <select class="field" name="category">
                        <option value="">{{ __('All categories') }}</option>
                        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($categoryId === $c->id)>{{ $c->label() }}</option>@endforeach
                    </select></label>
            @endif
            <button class="btn-ghost">{{ __('Search') }}</button>
            @if ($filtered)<a href="{{ route('dash.kb') }}" class="btn-ghost">{{ __('Clear') }}</a>@endif
        </form>

        @if ($counts['all'] === 0)
            <section class="card space-y-3 text-center" data-kb-empty>
                <div class="text-4xl" aria-hidden="true">💬</div>
                <h2 class="section-title">{{ __('Write the answers once, find them in a second') }}</h2>
                <p class="mx-auto max-w-lg text-sm text-navy-600">{{ __('Start with the suggested questions for your place: they come as drafts with [brackets] where your own details go, and nothing shows until you turn it on.') }}</p>
                <div class="flex flex-wrap justify-center gap-2">
                    <form method="post" action="{{ route('dash.kb.suggested') }}">@csrf<button class="btn-primary">✨ {{ __('Add suggested questions') }}</button></form>
                    <a href="{{ route('dash.kb.create') }}" class="btn-ghost">＋ {{ __('New answer') }}</a>
                </div>
            </section>
        @elseif ($grouped->isEmpty())
            <p class="card empty-state">{{ __('No answer matches.') }}</p>
        @endif

        @foreach ($grouped as $groupKey => $articles)
            @php $category = $groupKey === '' ? null : $categoryNames->get((int) $groupKey); @endphp
            <section class="card" data-kb-group="{{ $groupKey === '' ? 'none' : $groupKey }}">
                <h2 class="section-title mb-2 flex items-center gap-2">
                    {{ $category?->label() ?? __('Without a category') }}
                    @if ($category && ! $category->is_active)<span class="badge bg-navy-100 text-navy-500">{{ __('Hidden') }}</span>@endif
                </h2>
                <ul class="divide-y divide-navy-50">
                    @foreach ($articles as $article)
                        <li class="flex flex-wrap items-start gap-3 py-3" data-kb-article="{{ $article->id }}">
                            <div class="min-w-0 flex-1 basis-64">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('dash.kb.edit', $article) }}" class="font-bold text-navy-900 hover:text-brand-700">{{ $article->question }}</a>
                                    @if ($article->isPublic())
                                        <span class="badge bg-emerald-100 text-emerald-800" data-kb-badge="public">🌐 {{ __('On the menu') }}</span>
                                    @else
                                        <span class="badge bg-navy-100 text-navy-700" data-kb-badge="internal">🔒 {{ __('Staff only') }}</span>
                                    @endif
                                    @unless ($article->is_active)<span class="badge bg-amber-100 text-amber-800" data-kb-badge="draft">{{ __('Draft') }}</span>@endunless
                                </div>
                                <p class="mt-1 line-clamp-2 text-sm text-navy-600" dir="auto">{{ $article->answer }}</p>
                                <p class="mt-1 text-xs text-navy-400">
                                    @if ($article->tags)<span dir="auto">🏷 {{ $article->tags }}</span> · @endif
                                    {{ __('Updated :when', ['when' => local_time($article->updated_at, 'Y/m/d')]) }}@if ($article->editor) · {{ $article->editor->name }}@endif
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap items-center gap-1">
                                @if ($canReorder && $articles->count() > 1)
                                    <form method="post" action="{{ route('dash.kb.move', $article) }}">@csrf<input type="hidden" name="direction" value="up">
                                        <button class="btn-ghost min-h-11 min-w-11 px-2" @disabled($loop->first) aria-label="{{ __('Move up') }}" title="{{ __('Move up') }}">↑</button></form>
                                    <form method="post" action="{{ route('dash.kb.move', $article) }}">@csrf<input type="hidden" name="direction" value="down">
                                        <button class="btn-ghost min-h-11 min-w-11 px-2" @disabled($loop->last) aria-label="{{ __('Move down') }}" title="{{ __('Move down') }}">↓</button></form>
                                @endif
                                <a href="{{ route('dash.kb.edit', $article) }}" class="btn-ghost min-h-11 px-3">{{ __('Edit') }}</a>
                                <form method="post" action="{{ route('dash.kb.toggle', $article) }}">@csrf
                                    <button class="btn-ghost min-h-11 px-3" data-kb-toggle>{{ $article->is_active ? __('Turn off') : __('Turn on') }}</button></form>
                                <form method="post" action="{{ route('dash.kb.delete', $article) }}" onsubmit="return confirm(@js(__('Delete this answer?')))">@csrf
                                    <button class="min-h-11 px-2 text-sm text-red-600">{{ __('Delete') }}</button></form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <aside class="card space-y-3" data-kb-categories>
        <h2 class="section-title">{{ __('Categories') }}</h2>
        <ul class="divide-y divide-navy-50">
            @forelse ($categories as $c)
                <li class="py-2">
                    <div class="flex items-center gap-1">
                        <a href="{{ route('dash.kb', ['category' => $c->id]) }}" class="min-w-0 flex-1 truncate font-semibold @if (! $c->is_active) text-navy-400 line-through @endif">{{ $c->label() }}</a>
                        <span class="text-xs tabular-nums text-navy-400">{{ $c->articles_count }}</span>
                        <form method="post" action="{{ route('dash.kb.categories.move', $c) }}">@csrf<input type="hidden" name="direction" value="up">
                            <button class="grid size-11 place-items-center rounded-lg text-navy-500 hover:bg-navy-50 disabled:opacity-30" @disabled($loop->first) aria-label="{{ __('Move up') }}">↑</button></form>
                        <form method="post" action="{{ route('dash.kb.categories.move', $c) }}">@csrf<input type="hidden" name="direction" value="down">
                            <button class="grid size-11 place-items-center rounded-lg text-navy-500 hover:bg-navy-50 disabled:opacity-30" @disabled($loop->last) aria-label="{{ __('Move down') }}">↓</button></form>
                    </div>
                    <details class="mt-1">
                        <summary class="cursor-pointer text-xs font-semibold text-brand-700">{{ __('Edit') }}</summary>
                        <form method="post" action="{{ route('dash.kb.categories.update', $c) }}" class="mt-2 space-y-2">
                            @csrf
                            <input class="field" name="name" value="{{ $c->name }}" required maxlength="80" aria-label="{{ __('Name (Arabic)') }}">
                            <input class="field" name="name_en" value="{{ $c->name_en }}" maxlength="80" dir="ltr" placeholder="{{ __('Name (English)') }}" aria-label="{{ __('Name (English)') }}">
                            <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> {{ __('Shown (its answers show)') }}</label>
                            <div class="flex gap-2">
                                <button class="btn-primary flex-1">{{ __('Save') }}</button>
                            </div>
                        </form>
                        <form method="post" action="{{ route('dash.kb.categories.delete', $c) }}" class="mt-1" onsubmit="return confirm(@js(__('Delete this category? Its answers stay, without a category.')))">@csrf
                            <button class="min-h-11 text-sm text-red-600">{{ __('Delete the category') }}</button></form>
                    </details>
                </li>
            @empty
                <li class="py-2 text-sm text-navy-500">{{ __('No categories yet: “Delivery”, “Payment”, “Policies”…') }}</li>
            @endforelse
        </ul>
        <form method="post" action="{{ route('dash.kb.categories.store') }}" class="space-y-2" data-kb-category-form>
            @csrf
            <input class="field" name="name" required maxlength="80" placeholder="{{ __('e.g. Delivery') }}" aria-label="{{ __('Name (Arabic)') }}">
            <input class="field" name="name_en" maxlength="80" dir="ltr" placeholder="{{ __('Name (English)') }}" aria-label="{{ __('Name (English)') }}">
            <button class="btn-ghost w-full">＋ {{ __('Add category') }}</button>
        </form>
        <p class="text-xs text-navy-500">{{ __('Where the team reads them: the call center, the POS and the order board (the 📖 button, or F1).') }}</p>
    </aside>
</div>
@endsection
