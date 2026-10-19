@extends('layouts.dashboard', ['viteEntries' => ['resources/js/menu-editor.js']])
@section('title', __('Menu'))

@section('content')
<x-dash.page-header :title="__('Menu')">
    <p class="dash-help" dir="auto">{{ __('Items') }}: {{ $usage['used'] }} / {{ $usage['limit'] ?? '∞' }} · {{ __('Drag to reorder.') }}</p>
    <x-slot:actions>
        @if ($canAdd && $categories->isNotEmpty())<a href="{{ route('dash.items.create') }}" class="btn-primary">+ {{ __('Add item') }}</a>@endif
        <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Preview menu') }}</a>
        {{-- secondary actions under "more", so the header stays one row on phones (dropdown: resources/js/dash-ui.js) --}}
        <div class="relative" data-dropdown data-menu-more>
            <button type="button" class="btn-ghost" data-dropdown-toggle aria-haspopup="true" aria-expanded="false" aria-controls="menu-more-panel">
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                {{ __('More actions') }}
            </button>
            <div id="menu-more-panel" class="card absolute end-0 z-30 mt-2 hidden w-64 space-y-1 p-2 text-sm" data-dropdown-panel>
                <a href="{{ route('dash.import') }}" class="flex min-h-11 items-center gap-2 rounded-lg px-3 font-semibold hover:bg-navy-50">⬆ {{ __('Import menu') }}</a>
                <div class="border-t border-navy-50 px-3 pt-2 text-xs font-bold text-navy-500">⬇ {{ __('Export menu') }}</div>
                <a href="{{ route('dash.menu.export', 'xlsx') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50">{{ __('Excel (.xlsx)') }}</a>
                <a href="{{ route('dash.menu.export', 'csv') }}" class="flex min-h-11 items-center rounded-lg px-3 hover:bg-navy-50">{{ __('CSV') }}</a>
                <p class="px-3 pb-1 text-xs text-navy-500">{{ __('Same layout as the import, so you can edit and import it back.') }}</p>
                @if (\App\Services\Entitlements::current()->allows('printed_menu'))
                    <a href="{{ route('dash.menu.print') }}" target="_blank" class="flex min-h-11 items-center gap-2 rounded-lg border-t border-navy-50 px-3 font-semibold hover:bg-navy-50">🖨 {{ __('Printed menu') }}</a>
                @endif
            </div>
        </div>
    </x-slot:actions>
</x-dash.page-header>

{{-- the search filters the list as you type (resources/js/menu-editor.js); Enter still searches on the server --}}
<form class="mb-3 sm:max-w-sm" role="search">
    <input type="search" class="field" name="q" value="{{ $q }}" placeholder="{{ __('Search items') }}" aria-label="{{ __('Search items') }}" data-menu-search autocomplete="off">
</form>
<span hidden data-price-strings data-hint="{{ __('Enter saves · Esc cancels') }}" data-saving="{{ __('Saving…') }}" data-saved="{{ __('Saved') }}"
    data-error="{{ __('Not saved, try again.') }}" data-toast="{{ __('Price of :name is now :price') }}"></span>

@if ($categories->count() > 1)
    {{-- jump to a category: sticky under the top bar, scrolls sideways --}}
    <nav class="dash-chips sticky top-14 z-20 -mx-4 mb-3 flex gap-2 overflow-x-auto bg-navy-50/95 px-4 py-2 backdrop-blur sm:-mx-6 sm:px-6 lg:top-0 lg:-mx-8 lg:px-8" aria-label="{{ __('Categories') }}" data-category-chips>
        @foreach ($categories as $category)
            <a href="#cat-{{ $category->id }}" class="flex min-h-9 shrink-0 items-center gap-1.5 rounded-full border border-navy-100 bg-white px-3 text-sm font-semibold text-navy-800 hover:border-brand-500 hover:text-brand-700" data-chip="{{ $category->id }}">
                {{ $category->name }} <span class="text-xs font-normal text-navy-500" data-chip-count>{{ $category->items->count() }}</span>
            </a>
        @endforeach
    </nav>
@endif

@php
    $translateNeedsYou = $extraLocales && ($errors->has('translate') || \App\Jobs\TranslateMenu::isRunning() || ($translation['state'] ?? null) === 'failed');
@endphp
@if ($extraLocales || $categories->isNotEmpty())
    {{-- menu settings, closed by default so the items come first; remembers open/closed on this device --}}
    <details class="card group/settings mb-4 p-0" data-menu-settings @if ($translateNeedsYou) open data-force-open @endif>
        <summary class="flex min-h-12 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 sm:px-5 [&::-webkit-details-marker]:hidden">
            <span class="font-bold">⚙ {{ __('Menu settings') }}</span>
            <span class="hidden truncate text-xs text-navy-500 sm:inline">{{ collect([$extraLocales ? __('Extra languages') : null, $categories->isNotEmpty() ? __('Sold-out items coming back') : null])->filter()->implode(' · ') }}</span>
            <svg class="ms-auto size-4 shrink-0 text-navy-500 transition group-open/settings:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="space-y-3 border-t border-navy-50 p-4 sm:p-5">
            @if ($extraLocales)
                @include('dashboard.menu.partials.translate-banner', ['embedded' => true])
            @endif
            @if ($categories->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 text-sm text-navy-700">
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
        </div>
    </details>
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

<p class="empty-state mb-4 hidden" data-search-empty>{{ __('No items match your search.') }}</p>

<div class="space-y-4" data-sortable data-url="{{ route('dash.categories.reorder') }}">
    @foreach ($categories as $category)
        <section class="card scroll-mt-28 lg:scroll-mt-16" id="cat-{{ $category->id }}" data-id="{{ $category->id }}" data-category-section>
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
                            <label class="block text-sm">
                                <span class="mb-1 block text-xs font-semibold text-navy-500">{{ __('Icon in the round category row (when it has no photo)') }}</span>
                                <select class="field min-h-9" name="icon" data-category-icon>
                                    <option value="">{{ __('From the category name') }} ({{ \App\Support\ItemPlaceholder::label(\App\Support\ItemPlaceholder::icon($category->name . ' ' . $category->name_en, \App\Services\Settings::get('business_type'))) }})</option>
                                    @foreach (\App\Support\ItemPlaceholder::ICONS as $iconKey)
                                        <option value="{{ $iconKey }}" @selected($category->icon === $iconKey)>{{ \App\Support\ItemPlaceholder::label($iconKey) }}</option>
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
                    @php($soldOutNow = $item->isSoldOutNow())
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2 scroll-mt-32 lg:scroll-mt-20" id="item-{{ $item->id }}" data-id="{{ $item->id }}"
                        data-item-row data-search="{{ $item->name }} {{ $item->name_en }}" @if ((int) session('focus_item') === $item->id) data-focus @endif>
                        <span class="cursor-grab text-navy-500" data-handle>⋮⋮</span>
                        <a href="{{ route('dash.items.edit', $item) }}" class="shrink-0" tabindex="-1" aria-hidden="true">
                            @if ($item->image)
                                <img src="/storage/{{ $item->image }}" alt="" class="size-12 rounded-lg object-cover">
                            @else
                                <span class="block size-12 rounded-lg bg-navy-50"></span>
                            @endif
                        </a>
                        <div class="min-w-0 flex-1 basis-40">
                            <a href="{{ route('dash.items.edit', $item) }}" class="block truncate font-semibold hover:text-brand-700 hover:underline">{{ $item->name }}</a>
                            {{-- inline price: tap, type, Enter (resources/js/menu-editor.js → dash.items.price) --}}
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1" data-price-cell>
                                <button type="button" class="dash-price" data-price-edit="{{ route('dash.items.price', $item) }}" data-price="{{ (float) $item->price }}"
                                    aria-label="{{ __('Change the price of :name', ['name' => $item->name]) }}"
                                    title="{{ isset($branchPriced[$item->id]) ? __('Changes the main menu price only; branches with their own price keep it.') : __('Tap to change the price') }}">
                                    <span data-price-text>{{ money($item->price) }} {{ __('EGP') }}</span>
                                    <svg class="size-3.5 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z"/></svg>
                                </button>
                                @if ($item->sizes_count)<span class="text-xs text-navy-500" title="{{ __('This is the base price; size prices are added on top of it.') }}">+ {{ __('sizes') }}</span>@endif
                                @if (isset($branchPriced[$item->id]))<span class="badge bg-sky-50 text-sky-800" title="{{ __('Changes the main menu price only; branches with their own price keep it.') }}">{{ __('Has branch prices') }}</span>@endif
                                <span class="text-xs font-semibold" data-price-status aria-live="polite"></span>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 max-sm:w-full max-sm:ps-[4.75rem]">
                            <button type="button" class="dash-toggle" data-toggle="{{ route('dash.items.toggle', $item) }}" data-field="sold_out"
                                data-on="{{ __('Sold out') }}" data-off="{{ __('Available') }}" data-until-label="{{ __('until :time', ['time' => '__T__']) }}"
                                data-title-on="{{ __('Tap to mark it available again') }}" data-title-off="{{ __('Tap to mark it sold out') }}"
                                aria-pressed="{{ $soldOutNow ? 'true' : 'false' }}" title="{{ $soldOutNow ? __('Tap to mark it available again') : __('Tap to mark it sold out') }}">
                                <svg class="dash-toggle-on size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/></svg>
                                <svg class="dash-toggle-off size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                                <span data-toggle-label>{{ $soldOutNow ? __('Sold out') : __('Available') }}</span>@if ($until = $item->soldOutUntil())<span class="font-normal" dir="ltr" data-toggle-until>· {{ __('until :time', ['time' => local_time($until, $until->isToday() ? 'h:i A' : 'd/m h:i A')]) }}</span>@endif
                            </button>
                            <button type="button" class="dash-toggle" data-toggle="{{ route('dash.items.toggle', $item) }}" data-field="active"
                                data-on="{{ __('Visible') }}" data-off="{{ __('Hidden') }}"
                                data-title-on="{{ __('Tap to hide it from the menu') }}" data-title-off="{{ __('Tap to show it on the menu') }}"
                                aria-pressed="{{ $item->is_active ? 'true' : 'false' }}" title="{{ $item->is_active ? __('Tap to hide it from the menu') : __('Tap to show it on the menu') }}">
                                <svg class="dash-toggle-on size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="dash-toggle-off size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                                <span data-toggle-label>{{ $item->is_active ? __('Visible') : __('Hidden') }}</span>
                            </button>
                        </div>
                    </li>
                @empty
                    <li class="py-4 text-sm text-navy-500">{{ __('No items in this category yet.') }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
@endsection
