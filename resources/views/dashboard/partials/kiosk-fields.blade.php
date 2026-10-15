@php
    $o = $k?->options() ?? \App\Models\Kiosk::DEFAULTS;
    $types = old('order_types', $o['order_types']);
@endphp
<label class="block">
    <span class="field-label">{{ __('Name') }}</span>
    <input class="field" name="name" value="{{ old('name', $k?->name) }}" required maxlength="60" placeholder="{{ __('e.g. Counter tablet') }}">
</label>
<label class="block">
    <span class="field-label">{{ __('Branch') }}</span>
    <select class="field" name="branch_id" required>
        @foreach ($branches as $b)
            <option value="{{ $b->id }}" @selected((int) old('branch_id', $k?->branch_id) === $b->id)>{{ tr($b->name, $b->name_en) }}</option>
        @endforeach
    </select>
</label>
<fieldset class="block">
    <span class="field-label">{{ __('Order types') }}</span>
    <div class="flex flex-wrap gap-4 text-sm">
        <label class="flex items-center gap-2"><input type="checkbox" name="order_types[]" value="takeaway" @checked(in_array('takeaway', $types, true)) class="size-4 accent-brand-600"> {{ __('Takeaway') }}</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="order_types[]" value="dine_in" @checked(in_array('dine_in', $types, true)) class="size-4 accent-brand-600"> {{ __('Dine-in (guest writes the table number)') }}</label>
    </div>
</fieldset>
<label class="block">
    <span class="field-label">{{ __('Back to the start screen after (seconds without a touch)') }}</span>
    <input class="field" type="number" name="idle_seconds" min="15" max="600" value="{{ old('idle_seconds', $o['idle_seconds']) }}" required>
</label>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="require_phone" value="1" @checked(old('require_phone', $o['require_phone'])) class="size-4 accent-brand-600"> {{ __('Ask for the mobile number') }}</label>
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="print_number" value="1" @checked(old('print_number', $o['print_number'])) class="size-4 accent-brand-600"> {{ __('Print the order number (printer connected to the tablet)') }}</label>
