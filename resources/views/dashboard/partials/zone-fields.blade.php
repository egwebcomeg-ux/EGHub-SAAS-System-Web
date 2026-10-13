@php
    $mains = $zones->whereNull('parent_id')->reject(fn ($m) => $z && $m->id === $z->id);
@endphp
<select class="field" name="branch_id" required>
    @foreach ($branches as $b)<option value="{{ $b->id }}" @selected($z?->branch_id === $b->id)>{{ $b->name }}</option>@endforeach
</select>
<select class="field" name="parent_id">
    <option value="">{{ __('Main area') }}</option>
    @foreach ($mains as $m)<option value="{{ $m->id }}" @selected($z?->parent_id === $m->id)>{{ __('Inside :area', ['area' => $m->name]) }}</option>@endforeach
</select>
<input class="field" name="name" value="{{ $z?->name }}" required maxlength="80" placeholder="{{ __('Area name, e.g. Nasr City') }}">
<div class="grid grid-cols-3 gap-2">
    <input class="field" name="fee" type="number" step="0.5" min="0" value="{{ $z?->fee !== null ? (float) $z->fee : '' }}" placeholder="{{ __('Fee') }}">
    <input class="field" name="min_order" type="number" step="1" min="0" value="{{ $z?->min_order !== null ? (float) $z->min_order : '' }}" placeholder="{{ __('Minimum') }}">
    <input class="field" name="estimated_minutes" type="number" min="5" max="300" value="{{ $z?->estimated_minutes }}" placeholder="{{ __('Minutes') }}">
</div>
