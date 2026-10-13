<div><label class="field-label">{{ __('Name (Arabic)') }}</label><input class="field" name="name" value="{{ $b?->name }}" required maxlength="80"></div>
<div><label class="field-label">{{ __('Name (English)') }}</label><input class="field" name="name_en" value="{{ $b?->name_en }}" maxlength="80" dir="ltr"></div>
<div><label class="field-label">{{ __('Address') }}</label><input class="field" name="address" value="{{ $b?->address }}" maxlength="200"></div>
<div><label class="field-label">{{ __('Working hours') }}</label><input class="field" name="working_hours" value="{{ $b?->working_hours }}" maxlength="100" placeholder="{{ __('e.g. 9 AM – 1 AM') }}"></div>
<div><label class="field-label">{{ __('Phone') }}</label><input class="field" name="phone" value="{{ $b?->phone }}" dir="ltr"></div>
<div><label class="field-label">{{ __('WhatsApp') }}</label><input class="field" name="whatsapp" value="{{ $b?->whatsapp }}" dir="ltr"></div>
<div class="sm:col-span-2"><label class="field-label">{{ __('Google Maps link') }}</label><input class="field" name="maps_url" value="{{ $b?->maps_url }}" dir="ltr"></div>
