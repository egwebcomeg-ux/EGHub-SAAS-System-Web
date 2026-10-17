{{-- The rules shared by a single voucher and a batch: value, limits, dates, qualifying items. --}}
<div class="grid gap-3 sm:grid-cols-2">
    <fieldset class="sm:col-span-2">
        <legend class="field-label">{{ __('Discount type') }}</legend>
        <div class="grid grid-cols-2 gap-2">
            @foreach (['percent' => __('Percent %'), 'amount' => __('Amount (EGP)')] as $type => $label)
                <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-navy-200 text-sm font-semibold has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                    <input type="radio" name="type" value="{{ $type }}" class="sr-only" @checked(old('type', 'percent') === $type) data-voucher-type>{{ $label }}
                </label>
            @endforeach
        </div>
    </fieldset>
    <label class="block"><span class="field-label">{{ __('Value') }}</span>
        <input class="field" name="value" type="number" min="1" step="0.5" required value="{{ old('value') }}" dir="ltr"></label>
    <label class="block" data-percent-only><span class="field-label">{{ __('Max discount (EGP, optional)') }}</span>
        <input class="field" name="max_discount" type="number" min="1" step="1" value="{{ old('max_discount') }}" dir="ltr"></label>
    <label class="block"><span class="field-label">{{ __('Minimum order (EGP)') }}</span>
        <input class="field" name="min_order" type="number" min="0" step="1" value="{{ old('min_order', 0) }}" dir="ltr"></label>
    <label class="block"><span class="field-label">{{ __('Starts (optional)') }}</span>
        <input class="field" name="starts_at" type="date" value="{{ old('starts_at') }}" dir="ltr"></label>
    <label class="block"><span class="field-label">{{ __('Ends (optional)') }}</span>
        <input class="field" name="expires_at" type="date" value="{{ old('expires_at') }}" dir="ltr"></label>
    <label class="block"><span class="field-label">{{ __('Note (only you see it)') }}</span>
        <input class="field" name="note" maxlength="200" value="{{ old('note') }}"></label>
    <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-2">
        <input type="checkbox" name="once_per_customer" value="1" @checked(old('once_per_customer', true)) class="size-4 accent-brand-600"> {{ __('Once per guest (asks for the mobile number)') }}
    </label>
    <details class="text-sm sm:col-span-2">
        <summary class="cursor-pointer font-semibold text-navy-600">{{ __('Only on some categories or items (optional)') }}</summary>
        <p class="muted mt-1">{{ __('The discount is then taken off those lines only.') }}</p>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
            @foreach ($categories as $c)
                <label class="flex items-center gap-1.5 font-semibold"><input type="checkbox" name="categories[]" value="{{ $c->id }}" @checked(in_array($c->id, old('categories', []))) class="size-4 accent-brand-600"> {{ tr($c->name, $c->name_en) }}</label>
            @endforeach
        </div>
        <div class="mt-2 flex max-h-48 flex-wrap gap-x-4 gap-y-1 overflow-y-auto">
            @foreach ($items as $i)
                <label class="flex items-center gap-1.5"><input type="checkbox" name="items[]" value="{{ $i->id }}" @checked(in_array($i->id, old('items', []))) class="size-4 accent-brand-600"> {{ tr($i->name, $i->name_en) }}</label>
            @endforeach
        </div>
    </details>
</div>
