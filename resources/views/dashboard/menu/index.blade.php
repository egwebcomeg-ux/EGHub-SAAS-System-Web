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
        @if ($canAdd && $categories->isNotEmpty())<a href="{{ route('dash.items.create') }}" class="btn-primary">+ {{ __('Add item') }}</a>@endif
    </div>
</div>

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
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="size-4 accent-brand-600"> {{ __('Visible on the menu') }}</label>
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
                        <button type="button" data-toggle="{{ route('dash.items.toggle', $item) }}" data-field="sold_out"
                            data-on="{{ __('Sold out') }}" data-off="{{ __('Available') }}"
                            @class(['badge min-h-9 px-3', 'bg-red-100 text-red-700' => $item->is_sold_out, 'bg-emerald-100 text-emerald-800' => !$item->is_sold_out])>
                            {{ $item->is_sold_out ? __('Sold out') : __('Available') }}
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
