{{--
    The fields of one playlist slide. A new slide picks its type (the sections for the other types hide, [data-for]);
    an existing slide keeps its type. $slide is null for a new one.
--}}
@php
    $type = $slide?->type ?? array_key_first($types);
    $chosen = $slide?->category_ids ?? [];
    $images = $media->where('kind', 'image');
    $videos = $media->where('kind', 'video');
    $uid = $slide?->id ?? 'new';
    $split = isset($owner) && in_array($owner->zone_layout, ['side', 'halves'], true);
    $opt = fn (string $key) => old($key, $slide?->option($key === 'promo_ends_at' ? 'ends_at' : $key));
@endphp
@if ($slide)
    <input type="hidden" name="type" value="{{ $slide->type }}">
@else
    <fieldset class="sm:col-span-2">
        <legend class="field-label">{{ __('Slide type') }}</legend>
        <div class="flex flex-wrap gap-2" data-type-picker>
            @foreach ($types as $key => $label)
                <label class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                    <input type="radio" name="type" value="{{ $key }}" @checked($key === old('type', $type)) class="size-4 accent-brand-600"> {{ $label }}
                </label>
            @endforeach
        </div>
    </fieldset>
@endif

@if ($split)
    <div>
        <label class="field-label" for="zone-{{ $uid }}">{{ __('Part of the screen') }}</label>
        <select class="field" id="zone-{{ $uid }}" name="zone">
            <option value="main" @selected(($slide?->zone ?? 'main') === 'main')>{{ __('Main part') }}</option>
            <option value="side" @selected($slide?->zone === 'side')>{{ __('Side part') }}</option>
        </select>
    </div>
@endif

<div>
    <label class="field-label" for="duration-{{ $uid }}">{{ __('Seconds on screen') }}</label>
    <input class="field" id="duration-{{ $uid }}" type="number" name="duration" min="0" max="600" value="{{ $slide?->duration ?? 15 }}" inputmode="numeric">
    <p class="mt-1 text-xs text-navy-500" data-for="video">{{ __('0 on a video = until it ends.') }}</p>
</div>

{{-- menu --}}
<div class="contents" data-for="menu">
    <div>
        <label class="field-label">{{ __('Layout') }}</label>
        <select class="field" name="layout">
            <option value="list" @selected(($slide?->layout ?? 'list') === 'list')>{{ __('List in columns') }}</option>
            <option value="grid" @selected($slide?->layout === 'grid')>{{ __('Photo cards') }}</option>
            <option value="featured" @selected($slide?->layout === 'featured')>{{ __('One featured item') }}</option>
        </select>
    </div>
    <div>
        <label class="field-label">{{ __('Columns') }}</label>
        <input class="field" type="number" name="columns" min="1" max="4" value="{{ $slide?->columns ?? 3 }}" inputmode="numeric">
    </div>
    <div>
        <label class="field-label">{{ __('Featured item (for "One featured item")') }}</label>
        <select class="field" name="item_id">
            <option value="">{{ __('The first item with a photo') }}</option>
            @foreach ($items as $it)<option value="{{ $it->id }}" @selected($slide?->item_id === $it->id)>{{ $it->name }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="field-label">{{ __('Beside the menu (split screen)') }}</label>
        <select class="field" name="side_media_id">
            <option value="">{{ __('Nothing, the menu fills the screen') }}</option>
            @foreach ($media as $m)<option value="{{ $m->id }}" @selected($slide?->side_media_id === $m->id)>{{ $m->isVideo() ? '🎬' : '🖼' }} {{ $m->name ?: '#'.$m->id }}</option>@endforeach
        </select>
    </div>
    <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" name="show_images" value="1" @checked($slide?->show_images ?? true) class="size-4 accent-brand-600"> {{ __('Show item photos') }}</label>
    <fieldset class="sm:col-span-2">
        <legend class="field-label">{{ __('Categories (none ticked = all)') }}</legend>
        <div class="flex flex-wrap gap-2">
            @foreach ($categories as $c)
                <label class="flex min-h-9 items-center gap-2 rounded-lg border border-navy-100 px-3 text-sm">
                    <input type="checkbox" name="category_ids[]" value="{{ $c->id }}" @checked(in_array($c->id, $chosen)) class="size-4 accent-brand-600"> {{ $c->name }}
                </label>
            @endforeach
        </div>
    </fieldset>
</div>

{{-- picture / video from the library --}}
<div data-for="image">
    <label class="field-label">{{ __('Picture') }}</label>
    <select class="field" name="image_id">
        <option value="">{{ $images->isEmpty() ? __('Upload a picture below first') : __('Choose a picture') }}</option>
        @foreach ($images as $m)<option value="{{ $m->id }}" @selected($slide?->media_id === $m->id)>{{ $m->name ?: '#'.$m->id }}</option>@endforeach
    </select>
</div>
<div data-for="video">
    <label class="field-label">{{ __('Video') }}</label>
    <select class="field" name="video_id">
        <option value="">{{ $videos->isEmpty() ? __('Upload a video below first') : __('Choose a video') }}</option>
        @foreach ($videos as $m)<option value="{{ $m->id }}" @selected($slide?->media_id === $m->id)>{{ $m->name ?: '#'.$m->id }}</option>@endforeach
    </select>
</div>

{{-- offer --}}
@if (isset($types['offer']))
    <div data-for="offer">
        <label class="field-label">{{ __('Offer') }}</label>
        <select class="field" name="offer_id">
            <option value="">{{ __('Any offer that is running') }}</option>
            @foreach ($offers as $o)<option value="{{ $o->id }}" @selected($slide?->offer_id === $o->id)>{{ $o->title }} ({{ $o->discount_percent }}%){{ $o->is_active ? '' : ' · '.__('Stopped') }}</option>@endforeach
        </select>
        <p class="mt-1 text-xs text-navy-500">{{ __('Shows only while the offer runs, then the screen skips it.') }}</p>
    </div>
@endif

{{-- a ready design: the template picker with live previews, then the item or the words --}}
<div class="space-y-3 sm:col-span-2" data-for="promo">
    <fieldset data-design-picker>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <legend class="field-label">{{ __('Design') }}</legend>
            <div class="flex gap-1 text-xs" role="group" aria-label="{{ __('Preview') }}">
                <button type="button" class="btn-ghost min-h-8 px-2 aria-pressed:bg-navy-100" data-orientation="landscape" aria-pressed="{{ $portraitPreview ? 'false' : 'true' }}">{{ __('Landscape') }}</button>
                <button type="button" class="btn-ghost min-h-8 px-2 aria-pressed:bg-navy-100" data-orientation="portrait" aria-pressed="{{ $portraitPreview ? 'true' : 'false' }}">{{ __('Portrait') }}</button>
            </div>
        </div>
        <p class="mb-2 text-xs text-navy-500">{{ __('Each design takes your logo and colours by itself. The previews show what you type below.') }}</p>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            @foreach ($designs as $key => [$label, $hint])
                <label class="group block cursor-pointer rounded-xl border-2 border-navy-100 p-1.5 has-[:checked]:border-brand-500" data-design-option="{{ $key }}">
                    <input type="radio" name="template" value="{{ $key }}" @checked(old('template', $slide?->template ?? 'spotlight') === $key) class="sr-only">
                    <span @class(['relative block overflow-hidden rounded-lg bg-navy-900', 'aspect-[9/16]' => $portraitPreview, 'aspect-video' => ! $portraitPreview]) data-design-box>
                        <iframe data-design="{{ $key }}" data-src="{{ route('dash.tv.design', ['key' => $key]) }}" src="{{ route('dash.tv.design', ['key' => $key]) . ($portraitPreview ? '&o=portrait' : '') }}"
                            loading="lazy" tabindex="-1" aria-hidden="true" title="{{ $label }}"
                            class="pointer-events-none absolute left-0 top-0 origin-top-left border-0" style="width: {{ $portraitPreview ? 540 : 960 }}px; height: {{ $portraitPreview ? 960 : 540 }}px"></iframe>
                    </span>
                    <span class="mt-1 block text-sm font-bold">{{ $label }}</span>
                    <span class="block text-xs leading-snug text-navy-500">{{ $hint }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="field-label" for="promo-item-{{ $uid }}">{{ __('Item (its name, photo and live price)') }}</label>
            <select class="field" id="promo-item-{{ $uid }}" name="promo_item_id">
                <option value="">{{ __('No item: I type the words and the price') }}</option>
                @foreach ($items as $it)<option value="{{ $it->id }}" @selected((int) old('promo_item_id', $slide?->type === 'promo' ? $slide->item_id : 0) === $it->id)>{{ $it->name }} · {{ money($it->price) }}</option>@endforeach
            </select>
            <p class="mt-1 text-xs text-navy-500">{{ __('With an item the price follows the menu, and the slide hides by itself while the item is sold out.') }}</p>
        </div>
        <div><label class="field-label">{{ __('Title') }}</label><input class="field" name="title" maxlength="80" value="{{ $opt('title') }}" placeholder="{{ __('Leave empty to use the item name') }}"></div>
        <div><label class="field-label">{{ __('Title in English (optional)') }}</label><input class="field" name="title_en" maxlength="80" dir="ltr" value="{{ $opt('title_en') }}"></div>
        <div class="sm:col-span-2"><label class="field-label">{{ __('Short line (optional)') }}</label><input class="field" name="subtitle" maxlength="140" value="{{ $opt('subtitle') }}" placeholder="{{ __('e.g. Burger + fries + drink') }}"></div>
        <div><label class="field-label">{{ __('Price (without an item)') }}</label><input class="field" name="price" type="number" step="0.01" min="0" inputmode="decimal" value="{{ $opt('price') }}"></div>
        <div><label class="field-label">{{ __('Old price, crossed out (optional)') }}</label><input class="field" name="was" type="number" step="0.01" min="0" inputmode="decimal" value="{{ $opt('was') }}"></div>
        <div><label class="field-label">{{ __('Label (optional)') }}</label><input class="field" name="badge" maxlength="30" value="{{ $opt('badge') }}" placeholder="{{ __('e.g. New, Today only') }}"></div>
        <div>
            <label class="field-label">{{ __('Picture without an item (optional)') }}</label>
            <select class="field" name="promo_image_id">
                <option value="">{{ __('No picture') }}</option>
                @foreach ($images as $m)<option value="{{ $m->id }}" @selected((int) old('promo_image_id', $slide?->type === 'promo' ? $slide->media_id : 0) === $m->id)>{{ $m->name ?: '#'.$m->id }}</option>@endforeach
            </select>
        </div>
        <fieldset class="sm:col-span-2">
            <legend class="field-label">{{ __('Background') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach (['dark' => __('Dark'), 'light' => __('White'), 'brand' => __('Your main colour')] as $bg => $bgLabel)
                    <label class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-navy-100 px-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                        <input type="radio" name="bg" value="{{ $bg }}" @checked(old('bg', $slide?->option('bg', 'dark') ?? 'dark') === $bg) class="size-4 accent-brand-600"> {{ $bgLabel }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="sm:col-span-2">
            <label class="field-label">{{ __('Count down to (optional)') }}</label>
            <input class="field" type="datetime-local" name="promo_ends_at" value="{{ $opt('promo_ends_at') ? str_replace(' ', 'T', $opt('promo_ends_at')) : '' }}">
            <p class="mt-1 text-xs text-navy-500">{{ __('The design shows the time left, and the slide stops showing when it ends. Cairo time.') }}</p>
        </div>
    </div>
</div>

{{-- ready orders --}}
@if (isset($types['ready']))
    <div data-for="ready">
        <label class="field-label">{{ __('Branch') }}</label>
        <select class="field" name="branch_id">
            <option value="">{{ __('The screen\'s branch') }}</option>
            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($slide?->branch_id === $b->id)>{{ tr($b->name, $b->name_en) }}</option>@endforeach
        </select>
        <p class="mt-1 text-xs text-navy-500">{{ __('Big order numbers ready for pickup, readable from across the shop. Numbers only, no names.') }}</p>
    </div>
@endif

@include('dashboard.partials.tv-hours', ['model' => $slide, 'wide' => true])
@if ($slide)
    <label class="flex min-h-10 items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($slide->is_active) class="size-4 accent-brand-600"> {{ __('Show this slide') }}</label>
@endif
