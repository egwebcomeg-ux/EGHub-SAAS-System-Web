@extends('layouts.dashboard', ['viteEntries' => ['resources/js/menu-editor.js']])
@section('title', __('Menu'))

@section('content')
<x-dash.page-header :title="__('Menu')">
    <p class="dash-help" dir="auto">{{ __('Items') }}: {{ $usage['used'] }} / {{ $usage['limit'] ?? '∞' }} · {{ __('Drag to reorder.') }}</p>
    <x-slot:actions>
        @if ($canAdd && $categories->isNotEmpty())<a href="{{ route('dash.items.create') }}" class="btn-primary">+ {{ __('Add item') }}</a>@endif
        <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Preview menu') }}</a>
        <a href="{{ route('dash.import') }}" class="btn-ghost">⬆ {{ __('Import menu') }}</a>
        <details class="relative">
            <summary class="btn-ghost cursor-pointer list-none">⬇ {{ __('Export menu') }}</summary>
            <div class="card absolute end-0 z-20 mt-2 w-56 space-y-1 p-2 text-sm">
                <a href="{{ route('dash.menu.export', 'xlsx') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50">{{ __('Excel (.xlsx)') }}</a>
                <a href="{{ route('dash.menu.export', 'csv') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50">{{ __('CSV') }}</a>
                <p class="px-3 pb-1 text-xs text-navy-500">{{ __('Same layout as the import, so you can edit and import it back.') }}</p>
            </div>
        </details>
        @if (\App\Services\Entitlements::current()->allows('printed_menu'))<a href="{{ route('dash.menu.print') }}" target="_blank" class="btn-ghost">🖨 {{ __('Printed menu') }}</a>@endif
    </x-slot:actions>
</x-dash.page-header>
<form class="mb-4 sm:max-w-sm"><input type="search" class="field" name="q" value="{{ $q }}" placeholder="{{ __('Search items') }}" aria-label="{{ __('Search items') }}"></form>

@if ($extraLocales)
    @include('dashboard.menu.partials.translate-banner')
@endif

@if ($categories->isNotEmpty())
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm text-navy-700 ring-1 ring-navy-100">
        <label for="sold-out-until" class="font-semibold">{{ __('When I mark an item sold out, it comes back:') }}</label>
        <select id="sold-out-until" class="field min-h-9 w-auto py-1" data-sold-out-until>
            <option value="">{{ __('When I say so') }}</option>
            <option value="end_of_day">{{ __('End of today') }}</option>
            <option value="tomorrow">{{ __('Tomorrow morning (6 AM)') }}</option>
            <option value="hours_2">{{ __('In 2 hours') }}</option>
            <option value="custom">{{ __('At a time I pick…') }}</option>
        </select>
        <input type="datetime-local" class="field hidden min-h-9 w-auto py-1" data-sold-out-until-at dir="ltr">
    </div>
@endif

@if ($categories->isEmpty())
    <div class="card mb-4 flex flex-wrap items-center justify-between gap-3 bg-brand-50">
        <p class="font-semibold text-brand-700">{{ __('Starting from zero? Import your menu from Excel or a photo instead of typing it.') }}</p>
        <a href="{{ route('dash.import') }}" class="btn-primary">{{ __('Import menu') }}</a>
    </div>
@endif

<details class="card mb-4" @if ($categories->isEmpty()) open @endif>
    <summary class="cursor-pointer font-bold">+ {{ __('Add category') }}</summary>
    <form method="post" action="{{ route('dash.categories.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @csrf
        <input class="field" name="name" placeholder="{{ __('Name (Arabic)') }}" required maxlength="80">
        <input class="field" name="name_en" placeholder="{{ __('Name (English)') }}" maxlength="80" dir="ltr">
        <input class="field py-2" type="file" name="image" accept="image/jpeg,image/png,image/webp">
        <button class="btn-primary">{{ __('Add category') }}</button>
    </form>
</details>

<div class="space-y-4" data-sortable data-url="{{ route('dash.categories.reorder') }}">
    @foreach ($categories as $category)
        <section class="card" data-id="{{ $category->id }}">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="cursor-grab text-navy-500" data-handle title="{{ __('Drag to reorder.') }}">⋮⋮</span>
                    <h2 class="text-lg font-bold">{{ $category->name }}</h2>
                    @if ($category->name_en)<span class="text-sm text-navy-500" dir="ltr">{{ $category->name_en }}</span>@endif
                    @unless ($category->is_active)<span class="badge bg-navy-100 text-navy-500">{{ __('Hidden') }}</span>@endunless
                    @if ($category->hasSchedule())
                        <span class="badge bg-sky-100 text-sky-800" title="{{ __('Shown only during these hours') }}" dir="ltr">🕒 {{ $category->windowLabel() ?: __('Some days') }}</span>
                    @endif
                </div>
                <div class="flex gap-2">
                    @if ($canAdd)<a href="{{ route('dash.items.create', ['category' => $category->id]) }}" class="btn-ghost min-h-9 px-3">+ {{ __('Item') }}</a>@endif
                    <details class="relative">
                        <summary class="dash-sheet-toggle btn-ghost min-h-9 cursor-pointer list-none px-3">{{ __('Edit') }}</summary>
                        <form method="post" action="{{ route('dash.categories.update', $category) }}" enctype="multipart/form-data" class="card z-40 max-h-[80dvh] space-y-3 overflow-y-auto max-sm:fixed max-sm:inset-x-3 max-sm:bottom-3 max-sm:shadow-2xl sm:absolute sm:end-0 sm:z-20 sm:mt-2 sm:w-80">
                            @csrf
                            <div class="-mt-1 flex items-center justify-between gap-2 sm:hidden">
                                <b class="truncate">{{ __('Edit') }} · {{ $category->name }}</b>
                                <button type="button" class="grid size-11 shrink-0 place-items-center rounded-xl text-navy-500 hover:bg-navy-50" onclick="this.closest('details').open = false" aria-label="{{ __('Close') }}">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <input class="field" name="name" value="{{ $category->name }}" required maxlength="80">
                            <input class="field" name="name_en" value="{{ $category->name_en }}" maxlength="80" dir="ltr" placeholder="{{ __('Name (English)') }}">
                            <input class="field py-2" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                            @if ($extraLocales)
                                @include('dashboard.menu.partials.translations', ['translations' => $categoryTranslations[$category->id] ?? [], 'descriptionMax' => 200, 'compact' => true])
                            @endif
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="size-4 accent-brand-600"> {{ __('Visible on the menu') }}</label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-xs font-semibold text-navy-500">{{ __('Prepared as (food ready / drinks ready on the orders board)') }}</span>
                                <select class="field min-h-9" name="prep_kind">
                                    @foreach (\App\Support\PrepKind::KINDS as $kind => $l)
                                        <option value="{{ $kind }}" @selected(($category->prep_kind ?? 'food') === $kind)>{{ $kind === 'drinks' ? '☕' : '🍽' }} {{ \App\Support\PrepKind::label($kind) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-xs font-semibold text-navy-500">{{ __('Layout of this category on the menu') }}</span>
                                <select class="field min-h-9" name="menu_layout" data-category-layout>
                                    <option value="">{{ __('Like the rest of the menu') }}</option>
                                    @foreach (\App\Support\MenuLayouts::OVERRIDES as $layoutKey)
                                        <option value="{{ $layoutKey }}" @selected($category->menu_layout === $layoutKey)>{{ \App\Support\MenuLayouts::label($layoutKey)[0] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <fieldset class="space-y-2 rounded-xl bg-navy-50 p-2 text-sm">
                                <legend class="px-1 text-xs font-semibold text-navy-500">🕒 {{ __('Shown only during (optional)') }}</legend>
                                <div class="grid grid-cols-2 gap-2">
                                    <input class="field min-h-9" type="time" name="visible_from" value="{{ $category->visible_from ? substr($category->visible_from, 0, 5) : '' }}" dir="ltr" title="{{ __('From') }}">
                                    <input class="field min-h-9" type="time" name="visible_until" value="{{ $category->visible_until ? substr($category->visible_until, 0, 5) : '' }}" dir="ltr" title="{{ __('To') }}">
                                </div>
                                <div class="flex flex-wrap gap-x-2 gap-y-1">
                                    @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $d => $label)
                                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="visible_days[]" value="{{ $d }}" @checked($category->visible_days === null || in_array($d, $category->visible_days, true)) class="size-3.5 accent-brand-600"> {{ $label }}</label>
                                    @endforeach
                                </div>
                                <p class="text-xs text-navy-500">{{ __('e.g. breakfast 07:00–12:00; a window may cross midnight (22:00–03:00).') }}</p>
                            </fieldset>
                            <button class="btn-primary w-full">{{ __('Save') }}</button>
                        </form>
                    </details>
                    <form method="post" action="{{ route('dash.categories.delete', $category) }}" onsubmit="return confirm(@js(__('Delete this category?')))">
                        @csrf<button class="btn-ghost min-h-9 px-3 text-red-600">{{ __('Delete') }}</button>
                    </form>
                </div>
            </div>

            <ul class="divide-y divide-navy-50" data-sortable data-url="{{ route('dash.items.reorder') }}">
                @forelse ($category->items as $item)
                    <li class="flex flex-wrap items-center gap-3 py-2" data-id="{{ $item->id }}">
                        <span class="cursor-grab text-navy-500" data-handle>⋮⋮</span>
                        @if ($item->image)
                            <img src="/storage/{{ $item->image }}" alt="" class="size-12 rounded-lg object-cover">
                        @else
                            <span class="size-12 rounded-lg bg-navy-50"></span>
                        @endif
                        <a href="{{ route('dash.items.edit', $item) }}" class="min-w-0 flex-1">
                            <div class="font-semibold">{{ $item->name }}</div>
                            <div class="text-sm text-navy-500">{{ money($item->price) }} {{ __('EGP') }}</div>
                        </a>
                        @php($soldOutNow = $item->isSoldOutNow())
                        <button type="button" data-toggle="{{ route('dash.items.toggle', $item) }}" data-field="sold_out"
                            data-on="{{ __('Sold out') }}" data-off="{{ __('Available') }}" data-until-label="{{ __('until :time', ['time' => '__T__']) }}"
                            @class(['badge min-h-9 px-3', 'bg-amber-100 text-amber-800' => $soldOutNow, 'bg-emerald-100 text-emerald-800' => !$soldOutNow])>
                            {{ $soldOutNow ? __('Sold out') : __('Available') }}@if ($until = $item->soldOutUntil()) <span class="font-normal" dir="ltr">· {{ __('until :time', ['time' => local_time($until, $until->isToday() ? 'h:i A' : 'd/m h:i A')]) }}</span>@endif
                        </button>
                        <button type="button" data-toggle="{{ route('dash.items.toggle', $item) }}" data-field="active"
                            data-on="{{ __('Visible') }}" data-off="{{ __('Hidden') }}"
                            @class(['badge min-h-9 px-3', 'bg-navy-100 text-navy-700' => $item->is_active, 'bg-navy-800 text-white' => !$item->is_active])>
                            {{ $item->is_active ? __('Visible') : __('Hidden') }}
                        </button>
                    </li>
                @empty
                    <li class="py-4 text-sm text-navy-500">{{ __('No items in this category yet.') }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
@endsection
