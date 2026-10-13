@extends('layouts.dashboard')
@section('title', __('Delivery zones'))

@section('content')
<h1 class="page-title mb-1">{{ __('Delivery zones') }}</h1>
<p class="muted mb-4">{{ __('The call center picks the zone for each delivery: its fee is added and its minimum order is checked. A sub-area left empty uses its main area’s values.') }}</p>

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
                            <td class="font-semibold">{{ $z->label() }}</td>
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
