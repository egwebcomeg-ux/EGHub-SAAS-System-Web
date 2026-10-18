@extends('layouts.dashboard', ['viteEntries' => ['resources/js/menu-editor.js']])
@section('title', $item->exists ? $item->name : __('Add item'))

@php
    $groups = old('groups', $item->exists ? $item->optionGroups->map(fn ($g) => [
        'name' => $g->name, 'name_en' => $g->name_en, 'type' => $g->type, 'is_required' => $g->is_required, 'max_choices' => $g->max_choices,
        'options' => $g->options->map(fn ($o) => ['name' => $o->name, 'name_en' => $o->name_en, 'price' => (float) $o->price])->all(),
    ])->all() : []);
@endphp

@section('content')
<x-dash.page-header :title="$item->exists ? __('Edit item') : __('Add item')">
    <x-slot:actions>
        <a href="{{ route('dash.menu') }}" class="btn-ghost">{{ __('Back to menu') }}</a>
    </x-slot:actions>
</x-dash.page-header>

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
                {{-- the badges of this kind of place first, the others under "more" (App\Support\ItemBadges) --}}
                @php
                    [$mainBadges, $moreBadges] = \App\Support\ItemBadges::forBusiness(\App\Support\BusinessType::current());
                    $pickedBadges = old('tags', $item->badges ?? []);
                    $moreOpen = array_intersect($moreBadges, $pickedBadges) !== [];
                @endphp
                @foreach (['main' => $mainBadges, 'more' => $moreBadges] as $group => $keys)
                    @if ($group === 'more' && $keys !== [])
                        <details class="mt-2" @if ($moreOpen) open @endif data-badges-more>
                        <summary class="cursor-pointer text-sm font-semibold text-navy-500">{{ __('More labels') }}</summary>
                    @endif
                    <div @class(['flex flex-wrap gap-2', 'mt-2' => $group === 'more'])>
                        @foreach ($keys as $key)
                            @php
                                $badge = \App\Support\ItemBadges::badge($key);
                            @endphp
                            <label class="flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                                <input type="checkbox" name="tags[]" value="{{ $key }}" class="sr-only" @checked(in_array($key, $pickedBadges, true))> {{ $badge['icon'] }} {{ $badge['label'] }}
                            </label>
                        @endforeach
                    </div>
                    @if ($group === 'more' && $keys !== [])
                        </details>
                    @endif
                @endforeach
            </fieldset>
            <fieldset>
                <legend class="field-label">{{ __('Spicy level') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (range(0, \App\Models\MenuItem::MAX_SPICY) as $level)
                        <label class="flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="spicy_level" value="{{ $level }}" class="sr-only" @checked((int) old('spicy_level', $item->spicy_level ?? 0) === $level)> {{ $level === 0 ? __('Not spicy') : str_repeat('🌶', $level) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset class="sm:col-span-2">
                <legend class="field-label">{{ __('Allergens') }}</legend>
                <p class="mb-2 text-xs text-navy-500">{{ __('Shown to guests when they open the item. Tick everything the dish contains.') }}</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Models\MenuItem::ALLERGENS as $key => [$icon, $ar, $en])
                        <label class="flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-navy-100 px-3 text-sm has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50">
                            <input type="checkbox" name="allergens[]" value="{{ $key }}" class="sr-only" @checked(in_array($key, old('allergens', $item->allergens ?? []), true))> {{ $icon }} {{ app()->getLocale() === 'en' ? $en : $ar }}
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

        @if ($extraLocales)
            @include('dashboard.menu.partials.translations', ['translations' => $translations, 'descriptionMax' => 500])
        @endif
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
        <section class="card space-y-3" data-video-field>
            <label class="field-label">{{ __('Video (optional)') }}</label>
            @if (\App\Services\Entitlements::current()->allows(\App\Services\ItemVideo::FEATURE))
                @if ($item->video)
                    <video src="/storage/{{ $item->video }}" class="aspect-video w-full rounded-xl bg-navy-900 object-cover" muted loop playsinline controls preload="metadata"></video>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_video" value="1" class="size-4 accent-brand-600"> {{ __('Remove video') }}</label>
                @endif
                <input class="field py-2" type="file" name="video" accept="video/mp4,video/webm">
                <p class="text-xs text-navy-500">{{ __('MP4 or WebM up to 15 MB, a few seconds is best. Guests see it muted and looping.') }}</p>
                <input class="field" name="video_url" value="{{ old('video_url', $item->video_url) }}" maxlength="300" dir="ltr" placeholder="{{ __('or a YouTube / Instagram / TikTok link') }}">
                @error('video')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                @error('video_url')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @else
                <p class="text-sm text-navy-500">{{ \App\Services\Entitlements::current()->upgradeMessage(\App\Services\ItemVideo::FEATURE) }}</p>
            @endif
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
            @php($soldOutUntil = $item->exists ? $item->soldOutUntil() : null)
            <label class="flex min-h-9 items-center gap-2"><input type="checkbox" name="is_sold_out" value="1" @checked(old('is_sold_out', $item->exists ? $item->isSoldOutNow() : false)) class="size-4 accent-brand-600" data-sold-out-check> {{ __('Sold out') }}</label>
            <div class="space-y-2 ps-6" data-sold-out-fields>
                <select class="field min-h-9 py-1" name="sold_out_until" data-sold-out-until>
                    <option value="" @selected(old('sold_out_until', $soldOutUntil ? 'custom' : '') === '')>{{ __('Until I say so') }}</option>
                    <option value="end_of_day" @selected(old('sold_out_until') === 'end_of_day')>{{ __('Back at the end of today') }}</option>
                    <option value="tomorrow" @selected(old('sold_out_until') === 'tomorrow')>{{ __('Back tomorrow morning (6 AM)') }}</option>
                    <option value="hours_2" @selected(old('sold_out_until') === 'hours_2')>{{ __('Back in 2 hours') }}</option>
                    <option value="custom" @selected(old('sold_out_until', $soldOutUntil ? 'custom' : '') === 'custom')>{{ __('Back at a time I pick…') }}</option>
                </select>
                <input type="datetime-local" class="field min-h-9 py-1" name="sold_out_until_at" value="{{ old('sold_out_until_at', $soldOutUntil ? local_time($soldOutUntil, 'Y-m-d\TH:i') : '') }}" dir="ltr" data-sold-out-until-at>
            </div>
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
