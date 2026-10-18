@extends('layouts.dashboard', ['viteEntries' => ['resources/js/zones-map.js']])
@section('title', __('Delivery zones'))

@section('content')
<h1 class="page-title mb-1">{{ __('Delivery zones') }}</h1>
<p class="muted mb-4">{{ __('Where you deliver and for how much. The guest ordering online (and the call center) picks the area: its fee is added and its minimum order is checked. A sub-area left empty uses its main area’s values.') }}</p>

{{-- how the areas are set: typed by hand (default, simplest) or drawn on the map --}}
<form method="post" action="{{ route('dash.zones.mode') }}" class="mb-4 grid gap-3 sm:grid-cols-2" data-zone-mode>
    @csrf
    @foreach (['manual' => ['✍️', __('By hand'), __('Type each main area, then its streets or sub-areas, with the fee you want. No map needed.')], 'map' => ['🗺️', __('Draw on the map'), __('Draw each area’s borders on the map; a guest or the call center can pin the address and the area is picked automatically.')]] as $key => [$icon, $title, $text])
        <button name="mode" value="{{ $key }}" @class(['card flex items-start gap-3 text-start transition', 'ring-2 ring-brand-600' => $mode === $key, 'opacity-80 hover:opacity-100' => $mode !== $key])>
            <span class="text-2xl" aria-hidden="true">{{ $icon }}</span>
            <span><span class="block font-extrabold">{{ $title }} @if ($mode === $key)<span class="badge bg-brand-50 text-brand-700">{{ __('In use') }}</span>@endif</span><span class="mt-1 block text-sm text-navy-500">{{ $text }}</span></span>
        </button>
    @endforeach
</form>

@include('partials.leaflet')
@if ($mode === 'map' && $zones->isNotEmpty())
    <section class="card mb-4 space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="section-title me-auto">{{ __('Areas on the map') }}</h2>
            <select class="field min-h-9 w-auto py-0" data-draw-zone>
                <option value="">{{ __('Draw the area of…') }}</option>
                @foreach ($zones as $z)<option value="{{ $z->id }}">{{ $z->label() }}{{ $z->polygon ? ' ✓' : '' }}</option>@endforeach
            </select>
        </div>
        <div class="hidden flex-wrap items-center gap-2 text-sm" data-draw-tools>
            <span class="text-navy-600">{{ __('Click the corners of the area on the map.') }} <b data-points>0</b> {{ __('points') }}</span>
            <button type="button" class="btn-ghost min-h-9 px-3" data-undo>{{ __('Undo point') }}</button>
            <button type="button" class="btn-ghost min-h-9 px-3" data-clear>{{ __('Clear area') }}</button>
            <button type="button" class="btn-primary min-h-9 px-4" data-save-area>{{ __('Save area') }}</button>
        </div>
        <div id="zones-map" class="h-[420px] overflow-hidden rounded-xl border border-navy-100" dir="ltr"></div>
        <p class="text-xs text-navy-500">{{ __('With drawn areas, the call center can pin the customer on the map and the zone is picked automatically.') }}</p>
        <form method="post" class="hidden" data-area-form>@csrf</form>
    </section>
    @php
        $mapData = [
            'center' => $center,
            'saveUrl' => url('/delivery-zones/__ID__/area'),
            'zones' => $zones->map(fn ($z) => ['id' => $z->id, 'name' => $z->label(), 'polygon' => $z->polygon])->values(),
        ];
    @endphp
    <script type="application/json" id="zones-map-data">{!! json_encode($mapData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endif

<div class="grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        @foreach ($branches as $branch)
            @php
                $branchZones = $zones->where('branch_id', $branch->id);
            @endphp
            <section class="card overflow-x-auto">
                <h2 class="section-title mb-2">{{ $branch->name }}</h2>
                <table class="table-base">
                    <thead><tr><th>{{ __('Area') }}</th><th>{{ __('Delivery fee') }}</th><th>{{ __('Minimum order') }}</th><th>{{ __('Minutes') }}</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($branchZones as $z)
                        <tr @class(['opacity-50' => !$z->is_active])>
                            <td @class(['font-semibold', 'ps-6 text-navy-600' => $z->parent_id])>{{ $z->parent_id ? '↳ '.$z->name : $z->label() }}@if ($z->polygon) <span class="text-xs text-emerald-700" title="{{ __('Area drawn on the map') }}">📍</span>@endif</td>
                            <td>{{ money($z->effective('fee') ?? 0) }}@if ($z->fee === null && $z->parent) <span class="text-xs text-navy-400">({{ __('inherited') }})</span>@endif</td>
                            <td>{{ $z->effective('min_order') !== null ? money($z->effective('min_order')) : '—' }}</td>
                            <td>{{ $z->effective('estimated_minutes') ?? '—' }}</td>
                            <td>
                                <details class="relative">
                                    <summary class="btn-ghost min-h-9 cursor-pointer list-none px-3">{{ __('Edit') }}</summary>
                                    <form method="post" action="{{ route('dash.zones.update', $z) }}" class="card absolute end-0 z-10 mt-2 w-80 space-y-2">
                                        @csrf
                                        @include('dashboard.partials.zone-fields', ['z' => $z])
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($z->is_active) class="size-4 accent-brand-600"> {{ __('Active') }}</label>
                                        <button class="btn-primary w-full">{{ __('Save') }}</button>
                                    </form>
                                </details>
                                <form method="post" action="{{ route('dash.zones.delete', $z) }}" class="mt-1" onsubmit="return confirm(@js(__('Delete this zone?')))">@csrf<button class="text-xs text-red-600">{{ __('Delete') }}</button></form>
                            </td>
                        </tr>
                        @if ($mode === 'manual' && ! $z->parent_id)
                            <tr class="bg-navy-50/50">
                                <td colspan="5" class="py-2">
                                    <form method="post" action="{{ route('dash.zones.store') }}" class="flex flex-wrap items-center gap-2 ps-6" data-add-street>
                                        @csrf
                                        <input type="hidden" name="branch_id" value="{{ $z->branch_id }}">
                                        <input type="hidden" name="parent_id" value="{{ $z->id }}">
                                        <span class="text-sm text-navy-500">↳</span>
                                        <input class="field min-h-9 w-48 py-1" name="name" required maxlength="80" placeholder="{{ __('Street or sub-area in :area', ['area' => $z->name]) }}">
                                        <input class="field min-h-9 w-24 py-1" name="fee" type="number" step="0.5" min="0" placeholder="{{ __('Fee') }}" title="{{ __('Empty = the main area’s fee') }}">
                                        <button class="btn-ghost min-h-9 px-3">+ {{ __('Add street') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-navy-500">{{ __('No zones yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </section>
        @endforeach
    </div>
    <form method="post" action="{{ route('dash.zones.store') }}" class="card h-fit space-y-2">
        @csrf
        <h2 class="section-title">{{ __('Add zone') }}</h2>
        @include('dashboard.partials.zone-fields', ['z' => null])
        <button class="btn-primary w-full">{{ __('Add zone') }}</button>
    </form>
</div>
@endsection
