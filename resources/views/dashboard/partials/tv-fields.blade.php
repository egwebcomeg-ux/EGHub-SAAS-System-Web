@php
    $chosen = $s?->category_ids ?? [];
@endphp
<div>
    <label class="field-label">{{ __('Screen name') }}</label>
    <input class="field" name="name" value="{{ $s?->name }}" required maxlength="60" placeholder="{{ __('e.g. Above the counter') }}">
</div>
@if ($branches->count() > 1)
    <div>
        <label class="field-label">{{ __('Branch prices') }}</label>
        <select class="field" name="branch_id">
            <option value="">{{ __('Main branch') }}</option>
            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($s?->branch_id === $b->id)>{{ $b->name }}</option>@endforeach
        </select>
    </div>
@endif
<div>
    <label class="field-label">{{ __('Layout') }}</label>
    <select class="field" name="layout">
        <option value="all" @selected(($s?->layout ?? 'all') === 'all')>{{ __('Whole menu on one page') }}</option>
        <option value="rotate" @selected($s?->layout === 'rotate')>{{ __('Pages that take turns') }}</option>
    </select>
</div>
<div class="grid grid-cols-2 gap-2">
    <div>
        <label class="field-label">{{ __('Columns') }}</label>
        <input class="field" type="number" name="columns" min="1" max="4" value="{{ $s?->columns ?? 3 }}">
    </div>
    <div>
        <label class="field-label">{{ __('Seconds per page') }}</label>
        <input class="field" type="number" name="rotate_seconds" min="5" max="120" value="{{ $s?->rotate_seconds ?? 12 }}">
    </div>
</div>
<div>
    <label class="field-label">{{ __('Colors') }}</label>
    <select class="field" name="theme">
        <option value="dark" @selected(($s?->theme ?? 'dark') === 'dark')>{{ __('Dark') }}</option>
        <option value="light" @selected($s?->theme === 'light')>{{ __('Light') }}</option>
    </select>
</div>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="show_images" value="1" @checked($s?->show_images ?? true) class="size-4 accent-brand-600"> {{ __('Show item photos') }}</label>
<fieldset @class(["sm:col-span-2" => $s])>
    <legend class="field-label">{{ __('Categories (none ticked = all)') }}</legend>
    <div class="flex flex-wrap gap-2">
        @foreach ($categories as $c)
            <label class="flex min-h-9 items-center gap-2 rounded-lg border border-navy-100 px-3 text-sm">
                <input type="checkbox" name="category_ids[]" value="{{ $c->id }}" @checked(in_array($c->id, $chosen)) class="size-4 accent-brand-600"> {{ $c->name }}
            </label>
        @endforeach
    </div>
</fieldset>
