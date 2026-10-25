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
