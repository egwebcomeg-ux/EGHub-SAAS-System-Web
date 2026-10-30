{{-- the data of a place: shared by "add a place" and the place's edit form --}}
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div class="sm:col-span-2"><label class="field-label" for="pf-name">{{ __('Name of the place') }}</label>
        <input id="pf-name" class="field" name="name" value="{{ old('name', $p->name) }}" required maxlength="150">
        @error('name')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div><label class="field-label" for="pf-category">{{ __('Kind') }}</label>
        <select id="pf-category" class="field" name="category">
            @foreach (\App\Models\Prospect::CATEGORIES as $key => $l)<option value="{{ $key }}" @selected(old('category', $p->category) === $key)>{{ $l[2] }} {{ \App\Models\Lead::pick($l) }}</option>@endforeach
        </select></div>
    <div><label class="field-label" for="pf-phone">{{ __('Mobile') }}</label>
        <input id="pf-phone" class="field" name="phone" value="{{ old('phone', $p->phone) }}" dir="ltr" inputmode="tel" maxlength="20">
        @error('phone')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div><label class="field-label" for="pf-phone2">{{ __('Another number') }}</label>
        <input id="pf-phone2" class="field" name="phone2" value="{{ old('phone2', $p->phone2) }}" dir="ltr" inputmode="tel" maxlength="20"></div>
    <div><label class="field-label" for="pf-owner">{{ __('Owner or decision maker') }}</label>
        <input id="pf-owner" class="field" name="owner_name" value="{{ old('owner_name', $p->owner_name) }}" maxlength="100"></div>
    <div><label class="field-label" for="pf-gov">{{ __('Governorate') }}</label>
        <input id="pf-gov" class="field" name="governorate" value="{{ old('governorate', $p->governorate) }}" maxlength="60"></div>
    <div><label class="field-label" for="pf-city">{{ __('City') }}</label>
        <input id="pf-city" class="field" name="city" value="{{ old('city', $p->city) }}" maxlength="60" list="pf-cities">
        <datalist id="pf-cities">@foreach (\App\Services\Crm\PlaceImporter::PRESETS as $preset)<option value="{{ $preset[0] }}">@endforeach</datalist></div>
    <div><label class="field-label" for="pf-area">{{ __('Area or street') }}</label>
        <input id="pf-area" class="field" name="area" value="{{ old('area', $p->area) }}" maxlength="100"></div>
    <div class="sm:col-span-2"><label class="field-label" for="pf-address">{{ __('Address') }}</label>
        <input id="pf-address" class="field" name="address" value="{{ old('address', $p->address) }}" maxlength="255"></div>
    <div><label class="field-label">{{ __('Location on the map') }}</label>
        <div class="flex gap-2">
            <input class="field" name="lat" value="{{ old('lat', $p->lat) }}" placeholder="lat" dir="ltr" inputmode="decimal" aria-label="{{ __('Latitude') }}">
            <input class="field" name="lng" value="{{ old('lng', $p->lng) }}" placeholder="lng" dir="ltr" inputmode="decimal" aria-label="{{ __('Longitude') }}">
        </div>
        <button type="button" class="mt-1 text-xs font-bold text-brand-700" data-locate data-error="{{ __('Could not read your location. Allow location for this site.') }}">{{ __('Use my location (when you are at the place)') }}</button></div>
    <div><label class="field-label" for="pf-branches">{{ __('Branches') }}</label>
        <input id="pf-branches" class="field" type="number" min="1" name="branches" value="{{ old('branches', $p->branches) }}"></div>
    <div><label class="field-label" for="pf-system">{{ __('Current system') }}</label>
        <input id="pf-system" class="field" name="current_system" value="{{ old('current_system', $p->current_system) }}" maxlength="100"></div>
    <div><label class="field-label" for="pf-delivery">{{ __('Delivery') }}</label>
        @php($delivery = (string) old('has_delivery', $p->has_delivery === null ? '' : ($p->has_delivery ? '1' : '0')))
        <select id="pf-delivery" class="field" name="has_delivery">
            <option value="">{{ __('Not known') }}</option>
            <option value="1" @selected($delivery === '1')>{{ __('Yes') }}</option>
            <option value="0" @selected($delivery === '0')>{{ __('No') }}</option>
        </select></div>
    @if (auth('admin')->user()->allows('crm.manage'))
        <div><label class="field-label" for="pf-source">{{ __('Source') }}</label>
            <select id="pf-source" class="field" name="source">
                @foreach (\App\Models\Prospect::SOURCES as $key => $l)<option value="{{ $key }}" @selected(old('source', $p->source ?: 'manual') === $key)>{{ \App\Models\Lead::pick($l) }}</option>@endforeach
            </select></div>
    @endif
    <div class="sm:col-span-2 lg:col-span-3"><label class="field-label" for="pf-notes">{{ __('Notes') }}</label>
        <textarea id="pf-notes" class="field py-2" name="notes" rows="2">{{ old('notes', $p->notes) }}</textarea></div>
</div>
