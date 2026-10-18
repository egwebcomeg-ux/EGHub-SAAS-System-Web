<div><label class="field-label">{{ __('Name (Arabic)') }}</label><input class="field" name="name" value="{{ $b?->name }}" required maxlength="80"></div>
<div><label class="field-label">{{ __('Name (English)') }}</label><input class="field" name="name_en" value="{{ $b?->name_en }}" maxlength="80" dir="ltr"></div>
<div><label class="field-label">{{ __('Address') }}</label><input class="field" name="address" value="{{ $b?->address }}" maxlength="200"></div>
<div><label class="field-label">{{ __('Working hours') }}</label><input class="field" name="working_hours" value="{{ $b?->working_hours }}" maxlength="100" placeholder="{{ __('e.g. 9 AM – 1 AM') }}"></div>
<div><label class="field-label">{{ __('Phone') }}</label><input class="field" name="phone" value="{{ $b?->phone }}" dir="ltr"></div>
<div><label class="field-label">{{ __('WhatsApp') }}</label><input class="field" name="whatsapp" value="{{ $b?->whatsapp }}" dir="ltr"></div>
<div class="sm:col-span-2"><label class="field-label">{{ __('Google Maps link') }}</label><input class="field" name="maps_url" value="{{ $b?->maps_url }}" dir="ltr"></div>
<div class="grid grid-cols-2 gap-2 sm:col-span-2" data-branch-point>
    <div><label class="field-label">{{ __('Latitude') }}</label><input class="field" name="lat" value="{{ $b?->lat }}" inputmode="decimal" dir="ltr" placeholder="30.0444" data-lat></div>
    <div><label class="field-label">{{ __('Longitude') }}</label><input class="field" name="lng" value="{{ $b?->lng }}" inputmode="decimal" dir="ltr" placeholder="31.2357" data-lng></div>
    <div class="col-span-2 flex flex-wrap items-center gap-2 text-xs text-navy-500">
        <button type="button" class="btn-ghost min-h-9 px-3" data-point-from-link>📍 {{ __('Take the location from the link') }}</button>
        <span data-point-note>{{ __('Used for "nearest branch" on the menu. Paste the full Google Maps link (with @lat,lng), not the short one.') }}</span>
    </div>
</div>
@once
    <script>
        // reads "@lat,lng", "!3dlat!4dlng" or "?q=lat,lng" from the Google Maps link of the same branch form
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-point-from-link]');
            if (!btn) return;
            const form = btn.closest('form');
            const url = decodeURIComponent(form.querySelector('[name=maps_url]').value || '');
            const n = '(-?\\d{1,3}\\.\\d+)';
            const m = url.match(new RegExp(`!3d${n}!4d${n}`)) || url.match(new RegExp(`@${n},${n}`)) || url.match(new RegExp(`[?&](?:q|ll|query|destination|center|daddr)=${n},\\s*${n}`));
            const note = form.querySelector('[data-point-note]');
            if (!m) { note.textContent = @json(__('No coordinates in this link. Open the place on Google Maps on a computer and copy the address bar.')); return; }
            form.querySelector('[data-lat]').value = Number(m[1]).toFixed(6);
            form.querySelector('[data-lng]').value = Number(m[2]).toFixed(6);
            note.textContent = '✓';
        });
        // pasting a full link fills empty coordinates by itself
        document.addEventListener('change', (e) => {
            if (e.target.name !== 'maps_url') return;
            const form = e.target.closest('form');
            if (!form.querySelector('[data-lat]')?.value) form.querySelector('[data-point-from-link]')?.click();
        });
    </script>
@endonce
