{{-- the company's rep the new vouchers are handed to: written on each of them --}}
<div class="grid gap-3 sm:grid-cols-2">
    <label class="block"><span class="field-label">{{ __('Handed to (the company\'s rep)') }}</span>
        <input class="field" name="handed_to" value="{{ old('handed_to') }}" maxlength="100" list="corporate-reps" data-handed-to></label>
    <label class="block"><span class="field-label">{{ __('Rep mobile') }}</span>
        <input class="field" name="handed_to_phone" value="{{ old('handed_to_phone') }}" maxlength="20" dir="ltr" inputmode="tel"></label>
</div>
@if (! empty($reps) && count($reps) > 0)
    <datalist id="corporate-reps">
        @foreach ($reps as $name)
            <option value="{{ $name }}"></option>
        @endforeach
    </datalist>
@endif
<p class="-mt-1 text-xs text-navy-500">{{ __('Optional: who from the company received these vouchers. It shows next to each voucher and in the export.') }}</p>
