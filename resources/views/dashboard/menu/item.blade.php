@extends('layouts.dashboard', ['viteEntries' => ['resources/js/menu-editor.js']])
@section('title', $item->exists ? $item->name : __('Add item'))

@php
    $groups = old('groups', $item->exists ? $item->optionGroups->map(fn ($g) => [
        'name' => $g->name, 'name_en' => $g->name_en, 'type' => $g->type, 'is_required' => $g->is_required, 'max_choices' => $g->max_choices,
        'options' => $g->options->map(fn ($o) => ['name' => $o->name, 'name_en' => $o->name_en, 'price' => (float) $o->price])->all(),
    ])->all() : []);
@endphp

@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="text-2xl font-extrabold">{{ $item->exists ? __('Edit item') : __('Add item') }}</h1>
    <a href="{{ route('dash.menu') }}" class="btn-ghost">{{ __('Back to menu') }}</a>
</div>

<form method="post" action="{{ $item->exists ? route('dash.items.update', $item) : route('dash.items.store') }}" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-3">
    @csrf
    <div class="space-y-4 lg:col-span-2">
        <section class="card grid gap-4 sm:grid-cols-2">
            <div><label class="field-label">{{ __('Name (Arabic)') }}</label><input class="field" name="name" value="{{ old('name', $item->name) }}" required maxlength="100"></div>
            <div><label class="field-label">{{ __('Name (English)') }}</label><input class="field" name="name_en" value="{{ old('name_en', $item->name_en) }}" maxlength="100" dir="ltr"></div>
            <div><label class="field-label">{{ __('Description (Arabic)') }}</label><textarea class="field min-h-20 py-2" name="description" maxlength="500">{{ old('description', $item->description) }}</textarea></div>
            <div><label class="field-label">{{ __('Description (English)') }}</label><textarea class="field min-h-20 py-2" name="description_en" maxlength="500" dir="ltr">{{ old('description_en', $item->description_en) }}</textarea></div>
            <div>
                <label class="field-label">{{ __('Category') }}</label>
                <select class="field" name="category_id" required>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected((int) old('category_id', $item->category_id) === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="field-label">{{ __('Price') }} ({{ __('EGP') }})</label><input class="field" name="price" type="number" step="0.01" min="0" value="{{ old('price', $item->price) }}" required></div>
                <div><label class="field-label">{{ __('Calories') }}</label><input class="field" name="calories" type="number" min="0" value="{{ old('calories', $item->calories) }}"></div>
            </div>
            <fieldset>
                <legend class="field-label">{{ __('Labels on the menu') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Models\MenuItem::TAGS as $key => [$icon, $ar, $en])
                        <label class="flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="checkbox" name="tags[]" value="{{ $key }}" class="sr-only" @checked(in_array($key, old('tags', $item->badges ?? []), true))> {{ $icon }} {{ app()->getLocale() === 'en' ? $en : $ar }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </section>

        <section class="card">
            <div class="mb-1 flex items-center justify-between">
                <h2 class="text-lg font-bold">{{ __('Sizes & extras') }}</h2>
                <button type="button" class="btn-ghost min-h-9 px-3" data-add-group>+ {{ __('Add group') }}</button>
            </div>
            <p class="mb-4 text-sm text-navy-500">{{ __('Example: "Size" (choose one, required) with Small +0 / Large +15, or "Extras" (choose many) with Extra shot +10.') }}</p>
            <div class="space-y-4" data-groups data-initial='@json(array_values($groups))'></div>
        </section>
    </div>

    <div class="space-y-4">
        <section class="card space-y-3">
            <label class="field-label">{{ __('Photo') }}</label>
            @if ($item->image)
                <img src="/storage/{{ $item->image }}" alt="" class="aspect-square w-full rounded-xl object-cover">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_image" value="1" class="size-4 accent-brand-600"> {{ __('Remove photo') }}</label>
            @endif
            <input class="field py-2" type="file" name="image" accept="image/jpeg,image/png,image/webp">
            <p class="text-xs text-navy-500">{{ __('JPG, PNG or WebP up to 5 MB. Converted automatically to a light WebP.') }}</p>
        </section>
        @if ($upsell)
            <section class="card space-y-3">
                <h2 class="section-title">{{ __('Goes well with') }}</h2>
                <p class="muted">{{ __('Suggested to the guest right after adding this item (up to 6).') }}</p>
                <select class="field min-h-28 py-1" name="pairings[]" multiple size="6">
                    @foreach ($allItems as $other)
                        <option value="{{ $other->id }}" @selected($item->exists && $item->pairings->contains('id', $other->id))>{{ $other->name }}</option>
                    @endforeach
                </select>
            </section>
            <section class="card space-y-2">
                <h2 class="section-title">{{ __('Combo contents') }}</h2>
                <p class="muted">{{ __('Make this item a combo: the menu shows what is inside and how much the guest saves.') }}</p>
                @php($parts = $item->exists ? $item->comboParts->values() : collect())
                @for ($c = 0; $c < 4; $c++)
                    <div class="grid grid-cols-[1fr_5rem] gap-2">
                        <select class="field" name="combo[{{ $c }}][item_id]">
                            <option value="">—</option>
                            @foreach ($allItems as $other)<option value="{{ $other->id }}" @selected(($parts[$c]->id ?? null) === $other->id)>{{ $other->name }} ({{ money($other->price) }})</option>@endforeach
                        </select>
                        <input class="field" type="number" min="1" max="10" name="combo[{{ $c }}][qty]" value="{{ $parts[$c]->pivot->quantity ?? 1 }}">
                    </div>
                @endfor
            </section>
        @endif
        <section class="card space-y-2 text-sm">
            <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active ?? true)) class="size-4 accent-brand-600"> {{ __('Visible on the menu') }}</label>
            <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="is_sold_out" value="1" @checked(old('is_sold_out', $item->is_sold_out)) class="size-4 accent-brand-600"> {{ __('Sold out') }}</label>
            <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $item->is_featured)) class="size-4 accent-brand-600"> {{ __('Featured') }}</label>
        </section>
        <button class="btn-primary w-full">{{ __('Save item') }}</button>
    </div>
</form>

@if ($item->exists)
    <form method="post" action="{{ route('dash.items.delete', $item) }}" class="mt-4" onsubmit="return confirm(@js(__('Delete this item?')))">
        @csrf<button class="btn-ghost text-red-600">{{ __('Delete item') }}</button>
    </form>
@endif

<template id="group-template">
    <div class="rounded-xl border border-navy-100 p-4" data-group>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <input class="field" data-name="name" placeholder="{{ __('Group name, e.g. Size') }}" required maxlength="60">
            <input class="field" data-name="name_en" placeholder="{{ __('Name (English)') }}" maxlength="60" dir="ltr">
            <select class="field" data-name="type">
                <option value="single">{{ __('Choose one') }}</option>
                <option value="multiple">{{ __('Choose many') }}</option>
            </select>
            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" data-name="is_required" value="1" class="size-4 accent-brand-600"> {{ __('Required') }}</label>
                <input class="field w-20" data-name="max_choices" type="number" min="1" max="20" placeholder="{{ __('Max') }}" title="{{ __('Max choices') }}">
            </div>
        </div>
        <div class="mt-3 space-y-2" data-options></div>
        <div class="mt-3 flex gap-2">
            <button type="button" class="btn-ghost min-h-9 px-3" data-add-option>+ {{ __('Add choice') }}</button>
            <button type="button" class="btn-ghost min-h-9 px-3 text-red-600" data-remove-group>{{ __('Remove group') }}</button>
        </div>
    </div>
</template>
<template id="option-template">
    <div class="grid grid-cols-[1fr_1fr_6rem_auto] gap-2" data-option>
        <input class="field" data-name="name" placeholder="{{ __('Choice, e.g. Large') }}" required maxlength="60">
        <input class="field" data-name="name_en" placeholder="{{ __('Name (English)') }}" maxlength="60" dir="ltr">
        <input class="field" data-name="price" type="number" step="0.01" min="0" placeholder="+0">
        <button type="button" class="btn-ghost min-h-11 px-3 text-red-600" data-remove-option aria-label="{{ __('Remove') }}">×</button>
    </div>
</template>
@endsection
