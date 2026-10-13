<input class="field" name="name" value="{{ $i?->name }}" required maxlength="80" placeholder="{{ __('Name, e.g. Coffee beans') }}">
<select class="field" name="unit" required>
    @foreach (\App\Models\Ingredient::UNITS as $key => $label)
        <option value="{{ $key }}" @selected($i?->unit === $key)>{{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}</option>
    @endforeach
</select>
<div class="grid grid-cols-2 gap-2">
    <div>
        <label class="field-label">{{ __('Cost per unit') }}</label>
        <input class="field" name="cost_per_unit" type="number" step="0.0001" min="0" value="{{ $i ? (float) $i->cost_per_unit : '' }}">
    </div>
    <div>
        <label class="field-label">{{ __('Alert under') }}</label>
        <input class="field" name="min_stock" type="number" step="0.001" min="0" value="{{ $i ? (float) $i->min_stock : '' }}">
    </div>
</div>
