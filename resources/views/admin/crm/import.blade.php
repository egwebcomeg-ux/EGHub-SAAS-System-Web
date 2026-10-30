@extends('layouts.admin')
@section('title', __('Import places') . ' · EgHub Admin')

@section('content')
@include('partials.leaflet')
@include('admin.crm.partials.tabs')

<div class="mb-4">
    <h1 class="page-title">{{ __('Import places') }}</h1>
    <p class="muted">{{ __('Bring restaurants, cafes, bakeries and sweets shops of an area into the system. Each one gets a recommendation score, and places already in the system are skipped.') }}</p>
    <p class="mt-1 text-sm text-navy-600">{{ __(':n places in the system so far.', ['n' => number_format($total)]) }}</p>
</div>

<form method="post" action="{{ route('admin.crm.import.map') }}" class="card mb-4 space-y-3" data-map-import>
    @csrf
    <h2 class="section-title">{{ __('From the map (OpenStreetMap)') }}</h2>
    <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
        <div><label class="field-label" for="im-preset">{{ __('A ready area, or move the map and import what you see') }}</label>
            <select id="im-preset" name="preset" class="field" data-preset>
                <option value="">{{ __('What the map shows now') }}</option>
                @foreach ($presets as $key => [$name, $gov, $s, $w, $n, $e])
                    <option value="{{ $key }}" data-box="{{ $s }},{{ $w }},{{ $n }},{{ $e }}" @selected(old('preset') === $key)>{{ $name }} ({{ $gov }})</option>
                @endforeach
            </select></div>
        <div class="grid grid-cols-2 gap-2 sm:w-72">
            <div><label class="field-label" for="im-city">{{ __('City') }}</label><input id="im-city" name="city" class="field" value="{{ old('city', 'طنطا') }}"></div>
            <div><label class="field-label" for="im-gov">{{ __('Governorate') }}</label><input id="im-gov" name="governorate" class="field" value="{{ old('governorate', 'الغربية') }}"></div>
        </div>
    </div>
    <div id="import-map" class="h-[50vh] w-full rounded-xl ring-1 ring-navy-100"></div>
    <input type="hidden" name="south"><input type="hidden" name="west"><input type="hidden" name="north"><input type="hidden" name="east">
    <fieldset class="flex flex-wrap gap-3">
        <legend class="field-label">{{ __('Kinds of places') }}</legend>
        @foreach (\App\Models\Prospect::CATEGORIES as $key => $l)
            @continue($key === 'other')
            <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="categories[]" value="{{ $key }}" checked class="size-5 accent-brand-600"> {{ $l[2] }} {{ \App\Models\Lead::pick($l) }}</label>
        @endforeach
    </fieldset>
    @error('map')<p class="field-error">{{ $message }}</p>@enderror
    @error('categories')<p class="field-error">{{ $message }}</p>@enderror
    <p class="text-xs text-navy-500">{{ __('OpenStreetMap does not have every place or every phone number. The field rep adds what is missing from the street, and a file from Google Maps can be imported below.') }}</p>
    <button class="btn-primary" data-import-button>{{ __('Import this area') }}</button>
</form>

<form method="post" action="{{ route('admin.crm.import.csv') }}" enctype="multipart/form-data" class="card space-y-3" data-csv-import>
    @csrf
    <h2 class="section-title">{{ __('From a file (CSV)') }}</h2>
    <p class="text-sm text-navy-600">{{ __('The first row names the columns: name, phone, address, category, lat, lng, city. A Google Maps export (title, phoneNumber, latitude, longitude) works as it is.') }}</p>
    <div class="grid gap-3 sm:grid-cols-3">
        <input type="file" name="file" accept=".csv,text/csv" required class="field py-2 sm:col-span-1">
        <input name="city" class="field" placeholder="{{ __('City when the file has none') }}" value="طنطا">
        <input name="governorate" class="field" placeholder="{{ __('Governorate') }}" value="الغربية">
    </div>
    @error('file')<p class="field-error">{{ $message }}</p>@enderror
    <button class="btn-navy">{{ __('Import the file') }}</button>
</form>
@endsection

@push('scripts')
<script>
    (() => {
        const el = document.getElementById('import-map');
        if (!el || !window.L) return;
        const form = el.closest('form');
        const map = L.map(el).setView([30.7865, 31.0004], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
        let box = null;
        const sync = () => {
            const b = map.getBounds();
            form.south.value = b.getSouth().toFixed(5); form.west.value = b.getWest().toFixed(5);
            form.north.value = b.getNorth().toFixed(5); form.east.value = b.getEast().toFixed(5);
        };
        map.on('moveend', sync);
        sync();
        const preset = form.querySelector('[data-preset]');
        preset.addEventListener('change', () => {
            box?.remove();
            const option = preset.selectedOptions[0];
            if (!option.dataset.box) return;
            const [s, w, n, e] = option.dataset.box.split(',').map(Number);
            box = L.rectangle([[s, w], [n, e]], { color: '#F37623', weight: 2, fill: false }).addTo(map);
            map.fitBounds(box.getBounds());
            form.city.value = option.textContent.split(' (')[0].trim();
            form.governorate.value = option.textContent.split('(')[1].replace(')', '').trim();
        });
        form.addEventListener('submit', () => { form.querySelector('[data-import-button]').disabled = true; });
    })();
</script>
@endpush
