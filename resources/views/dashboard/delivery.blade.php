@extends('layouts.dashboard', ['viteEntries' => ['resources/js/zones-map.js']])
@section('title', __('Delivery zones'))

@section('content')
<h1 class="page-title mb-1">{{ __('Delivery zones') }}</h1>
<p class="muted mb-4">{{ __('The call center picks the zone for each delivery: its fee is added and its minimum order is checked. A sub-area left empty uses its main area’s values.') }}</p>

@include('partials.leaflet')
@if ($zones->isNotEmpty())
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
                            <td class="font-semibold">{{ $z->label() }}@if ($z->polygon) <span class="text-xs text-emerald-700" title="{{ __('Area drawn on the map') }}">📍</span>@endif</td>
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
