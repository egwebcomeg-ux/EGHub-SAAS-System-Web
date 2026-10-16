@extends('layouts.dashboard', ['viteEntries' => ['resources/js/menu-editor.js']])
@section('title', __('Menu'))

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-extrabold">{{ __('Menu') }}</h1>
        <p class="text-sm text-navy-500" dir="auto">{{ __('Items') }}: {{ $usage['used'] }} / {{ $usage['limit'] ?? '∞' }} · {{ __('Drag to reorder.') }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <form class="flex gap-2"><input class="field max-w-56" name="q" value="{{ $q }}" placeholder="{{ __('Search items') }}"></form>
        <a href="{{ tenant()->url() }}" target="_blank" rel="noopener" class="btn-ghost">{{ __('Preview menu') }}</a>
        <a href="{{ route('dash.import') }}" class="btn-ghost">⬆ {{ __('Import menu') }}</a>
        <details class="relative">
            <summary class="btn-ghost cursor-pointer list-none">⬇ {{ __('Export menu') }}</summary>
            <div class="card absolute end-0 z-10 mt-2 w-56 space-y-1 p-2 text-sm">
                <a href="{{ route('dash.menu.export', 'xlsx') }}" class="block rounded-lg px-3 py-2 hover:bg-navy-50">{{ __('Excel (.xlsx)') }}</a>
                <a href="{{ route('dash.menu.export', 'csv') }}" class="block rounded-lg px-3 py-2 hover:bg-navy-50">{{ __('CSV') }}</a>
                <p class="px-3 pb-1 text-xs text-navy-500">{{ __('Same layout as the import, so you can edit and import it back.') }}</p>
            </div>
        </details>
        @if (\App\Services\Entitlements::current()->allows('printed_menu'))<a href="{{ route('dash.menu.print') }}" target="_blank" class="btn-ghost">🖨 {{ __('Printed menu') }}</a>@endif
        @if ($canAdd && $categories->isNotEmpty())<a href="{{ route('dash.items.create') }}" class="btn-primary">+ {{ __('Add item') }}</a>@endif
    </div>
</div>

@if ($extraLocales)
    @include('dashboard.menu.partials.translate-banner')
@endif

@if ($categories->isNotEmpty())
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-navy-700">
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
                        <summary class="btn-ghost min-h-9 cursor-pointer list-none px-3">{{ __('Edit') }}</summary>
                        <form method="post" action="{{ route('dash.categories.update', $category) }}" enctype="multipart/form-data" class="card absolute end-0 z-10 mt-2 w-80 space-y-3">
                            @csrf
                            <input class="field" name="name" value="{{ $category->name }}" required maxlength="80">
                            <input class="field" name="name_en" value="{{ $category->name_en }}" maxlength="80" dir="ltr" placeholder="{{ __('Name (English)') }}">
                            <input class="field py-2" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                            @if ($extraLocales)
                                @include('dashboard.menu.partials.translations', ['translations' => $categoryTranslations[$category->id] ?? [], 'descriptionMax' => 200, 'compact' => true])
                            @endif
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="size-4 accent-brand-600"> {{ __('Visible on the menu') }}</label>
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
                            @class(['badge min-h-9 px-3', 'bg-red-100 text-red-700' => $soldOutNow, 'bg-emerald-100 text-emerald-800' => !$soldOutNow])>
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
