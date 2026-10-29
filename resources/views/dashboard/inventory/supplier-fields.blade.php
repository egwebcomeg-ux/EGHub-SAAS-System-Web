<input class="field" name="name" value="{{ $s?->name }}" required maxlength="120" placeholder="{{ __('Supplier name') }}">
<input class="field" name="phone" value="{{ $s?->phone }}" maxlength="30" dir="ltr" inputmode="tel" placeholder="{{ __('Phone') }}">
<textarea class="field" name="notes" maxlength="500" rows="2" placeholder="{{ __('Notes (what they sell, payment terms…)') }}">{{ $s?->notes }}</textarea>
