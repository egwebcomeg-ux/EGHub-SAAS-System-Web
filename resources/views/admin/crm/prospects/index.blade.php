@extends('layouts.admin')
@section('title', __('Places') . ' · EgHub Admin')

@section('content')
@php
    $f = $filters;
    $canManage = $admin->allows('crm.manage');
    $link = fn (array $extra) => route('admin.crm.prospects', array_filter([...$f, ...$extra], fn ($v) => $v !== '' && $v !== 'score'));
    $callers = $team->where('seat', 'caller');
    $fields = $team->where('seat', 'field');
@endphp
@include('admin.crm.partials.tabs')

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="page-title">{{ __('Places') }}</h1>
        <p class="muted">{{ $canManage ? __('Restaurants, cafes, bakeries and sweets shops that do not use EgHub yet. Best recommended first.') : __('The places given to you. Visit the best recommended first.') }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($admin->allows('crm.import'))<a href="{{ route('admin.crm.import') }}" class="btn-ghost">{{ __('Import from the map') }}</a>@endif
        <a href="#new-place" class="btn-primary" data-new-place>{{ __('Add a place') }}</a>
    </div>
</div>

<div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4" data-prospect-counts>
    @foreach (['new' => 'text-brand-700', 'appointment' => 'text-violet-700', 'trial' => 'text-teal-700', 'won' => 'text-emerald-700'] as $stage => $tone)
        <a href="{{ $link(['stage' => $stage]) }}" class="card block p-3 hover:shadow-md">
            <div class="text-xs font-semibold text-navy-500 sm:text-sm">{{ \App\Models\Lead::pick(\App\Models\Prospect::STAGES[$stage]) }}</div>
            <div class="mt-0.5 text-2xl font-black {{ $counts[$stage] > 0 ? $tone : 'text-navy-300' }}">{{ number_format($counts[$stage]) }}</div>
        </a>
    @endforeach
</div>

@if ($recommended->isNotEmpty())
    <section class="card mb-4" data-recommended-calls>
        <div class="mb-2 flex items-baseline justify-between gap-2">
            <h2 class="section-title">{{ __('Recommended calls') }}</h2>
            <span class="text-xs text-navy-500">{{ __('The system picks: follow-ups due, then the best places nobody called') }}</span>
        </div>
        <ul class="divide-y divide-navy-50">
            @foreach ($recommended as $p)
                <li class="flex items-center gap-3 py-2">
                    <a href="{{ route('admin.crm.prospects.show', $p) }}" class="min-w-0 flex-1">
                        <span class="font-bold text-navy-900">{{ $p->categoryEmoji() }} {{ $p->name }}</span>
                        <span class="block truncate text-xs text-navy-500">{{ $p->place() ?: '—' }} · {{ __('Score :n', ['n' => $p->score]) }}
                            @if ($p->next_follow_up_at) · {{ __('Follow-up :at', ['at' => local_time($p->next_follow_up_at, 'd/m h:i A')]) }}@endif</span>
                    </a>
                    <a href="tel:{{ $p->phone }}" class="btn border border-emerald-200 bg-emerald-50 text-emerald-800" dir="ltr">{{ $p->phone }}</a>
                </li>
            @endforeach
        </ul>
    </section>
@endif

<form method="get" class="card mb-4 grid gap-2 sm:grid-cols-6" data-prospect-filters>
    <input class="field sm:col-span-2" name="q" value="{{ $f['q'] }}" placeholder="{{ __('Name, area or phone') }}">
    <select class="field" name="stage">
        <option value="">{{ __('Every stage') }}</option>
        <option value="open" @selected($f['stage'] === 'open')>{{ __('Still open') }}</option>
        @foreach (\App\Models\Prospect::STAGES as $key => $l)<option value="{{ $key }}" @selected($f['stage'] === $key)>{{ \App\Models\Lead::pick($l) }}</option>@endforeach
    </select>
    <select class="field" name="category">
        <option value="">{{ __('Every kind') }}</option>
        @foreach (\App\Models\Prospect::CATEGORIES as $key => $l)<option value="{{ $key }}" @selected($f['category'] === $key)>{{ $l[2] }} {{ \App\Models\Lead::pick($l) }}</option>@endforeach
    </select>
    <select class="field" name="city">
        <option value="">{{ __('Every city') }}</option>
        @foreach ($cities as $city)<option value="{{ $city }}" @selected($f['city'] === $city)>{{ $city }}</option>@endforeach
    </select>
    <select class="field" name="sort">
        @foreach (['score' => __('Best recommended'), 'newest' => __('Newest'), 'follow_up' => __('Follow-up date'), 'name' => __('Name')] as $key => $label)
            <option value="{{ $key }}" @selected($f['sort'] === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <div class="flex flex-wrap items-center gap-3 sm:col-span-6">
        <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="mine" value="1" @checked($f['mine'] === '1') class="size-5 accent-brand-600"> {{ __('Mine only') }}</label>
        <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="view" value="map" @checked($f['view'] === 'map') class="size-5 accent-brand-600"> {{ __('Show on the map') }}</label>
        <button class="btn-navy ms-auto">{{ __('Filter') }}</button>
    </div>
</form>

@if ($f['view'] === 'map')
    @include('partials.leaflet')
    <div class="card mb-4 p-2"><div id="prospects-map" class="h-[65vh] w-full rounded-xl" data-pins='@json($pins)'></div></div>
@else
    <form method="post" action="{{ route('admin.crm.prospects.bulk') }}" data-bulk-form>
        @csrf
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead class="bg-navy-50 text-start text-xs text-navy-500">
                    <tr>
                        @if ($canManage)<th class="p-3"><span class="sr-only">{{ __('Select') }}</span></th>@endif
                        <th class="p-3 text-start">{{ __('Place') }}</th>
                        <th class="p-3 text-start">{{ __('Stage') }}</th>
                        <th class="p-3 text-start">{{ __('Score') }}</th>
                        <th class="p-3 text-start">{{ __('Phone') }}</th>
                        <th class="p-3 text-start">{{ __('Team') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-navy-50">
                    @forelse ($prospects as $p)
                        <tr data-prospect-row="{{ $p->id }}">
                            @if ($canManage)<td class="p-3"><input type="checkbox" name="ids[]" value="{{ $p->id }}" class="size-5 accent-brand-600" aria-label="{{ $p->name }}"></td>@endif
                            <td class="p-3">
                                <a href="{{ route('admin.crm.prospects.show', $p) }}" class="font-bold text-navy-900 hover:underline">{{ $p->categoryEmoji() }} {{ $p->name }}</a>
                                <div class="text-xs text-navy-500">{{ $p->place() ?: '—' }} · {{ $p->sourceLabel() }}</div>
                            </td>
                            <td class="p-3"><span class="badge {{ $p->stageClasses() }}">{{ $p->stageLabel() }}</span></td>
                            <td class="p-3 font-black {{ $p->score >= 70 ? 'text-emerald-700' : ($p->score >= 45 ? 'text-navy-800' : 'text-navy-400') }}">{{ $p->score }}</td>
                            <td class="p-3" dir="ltr">{{ $p->phone ?: '—' }}</td>
                            <td class="p-3 text-xs text-navy-600">{{ $p->caller?->name ?? '—' }} / {{ $p->field?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-navy-500">{{ __('No places yet. Import them from the map or add one.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($canManage && $prospects->isNotEmpty())
            <div class="card mt-3 flex flex-wrap items-end gap-2" data-bulk-assign>
                <div><label class="field-label" for="bulk-caller">{{ __('Give the ticked places to (caller)') }}</label>
                    <select id="bulk-caller" name="caller_id" class="field"><option value="">—</option>@foreach ($callers as $m)<option value="{{ $m['admin']->id }}">{{ $m['admin']->name }}</option>@endforeach</select></div>
                <div><label class="field-label" for="bulk-field">{{ __('and the field rep') }}</label>
                    <select id="bulk-field" name="field_id" class="field"><option value="">—</option>@foreach ($fields as $m)<option value="{{ $m['admin']->id }}">{{ $m['admin']->name }}</option>@endforeach</select></div>
                <button class="btn-navy">{{ __('Assign') }}</button>
                @error('ids')<p class="field-error w-full">{{ $message }}</p>@enderror
            </div>
        @endif
    </form>
    <div class="mt-3">{{ $prospects->links() }}</div>
@endif

{{-- a new place: the caller types one she heard of, the field rep adds the one in front of him --}}
<form method="post" action="{{ route('admin.crm.prospects.store') }}" id="new-place" class="card mt-6 space-y-3" data-new-place-form>
    @csrf
    <h2 class="section-title">{{ __('Add a place') }}</h2>
    @include('admin.crm.prospects.fields', ['p' => new \App\Models\Prospect(['category' => 'restaurant', 'governorate' => 'الغربية', 'city' => 'طنطا'])])
    <button class="btn-primary">{{ __('Save the place') }}</button>
</form>
@endsection

@push('scripts')
<script>
    (() => {
        const el = document.getElementById('prospects-map');
        if (el && window.L) {
            const pins = JSON.parse(el.dataset.pins || '[]');
            const map = L.map(el).setView([30.7865, 31.0004], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
            const group = L.featureGroup();
            pins.forEach((p) => {
                const color = p.score >= 70 ? '#047857' : (p.score >= 45 ? '#131D3D' : '#94a3b8');
                const marker = L.circleMarker([p.lat, p.lng], { radius: 8, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1 });
                const a = document.createElement('a');
                a.href = p.url;
                a.textContent = `${p.emoji} ${p.name} · ${p.stage} · ${p.score}`;
                marker.bindPopup(a);
                group.addLayer(marker);
            });
            group.addTo(map);
            if (pins.length) map.fitBounds(group.getBounds().pad(0.1));
        }
        // "use my location" fills the pin of the place being added
        document.querySelectorAll('[data-locate]').forEach((button) => button.addEventListener('click', () => {
            const form = button.closest('form');
            navigator.geolocation?.getCurrentPosition((pos) => {
                form.querySelector('[name=lat]').value = pos.coords.latitude.toFixed(7);
                form.querySelector('[name=lng]').value = pos.coords.longitude.toFixed(7);
            }, () => alert(button.dataset.error), { enableHighAccuracy: true, timeout: 15000 });
        }));
    })();
</script>
@endpush
