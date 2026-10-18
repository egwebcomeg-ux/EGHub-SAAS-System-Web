@extends('layouts.dashboard')
@section('title', __('Kitchen stations'))

@section('content')
<div class="dash-head">
    <div>
        <h1 class="page-title">{{ __('Kitchen stations') }}</h1>
        <p class="dash-help">{{ __('Each station gets its own kitchen screen with only its items, e.g. grill, drinks, desserts.') }}</p>
    </div>
    <a href="{{ route('dash.kitchen') }}" class="btn-ghost">{{ __('Open kitchen display') }}</a>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <section class="card space-y-3">
        <h2 class="section-title">{{ __('Stations') }}</h2>
        <ul class="divide-y divide-navy-50">
            @forelse ($stations as $s)
                <li class="flex items-center justify-between py-2">
                    <a href="{{ route('dash.kitchen', ['station' => $s->id]) }}" class="font-semibold text-brand-700">{{ $s->name }}</a>
                    <form method="post" action="{{ route('dash.stations.delete', $s) }}" onsubmit="return confirm(@js(__('Delete this station?')))">@csrf<button class="min-h-10 px-2 text-sm text-red-600">{{ __('Delete') }}</button></form>
                </li>
            @empty
                <li class="py-3 muted">{{ __('No stations yet. Without stations every kitchen screen shows everything.') }}</li>
            @endforelse
        </ul>
        <form method="post" action="{{ route('dash.stations.store') }}" class="flex gap-2">
            @csrf
            <input class="field" name="name" required maxlength="60" placeholder="{{ __('e.g. Grill') }}">
            <button class="btn-primary">{{ __('Add') }}</button>
        </form>
    </section>

    @if ($stations->isNotEmpty())
        <form method="post" action="{{ route('dash.stations.assign') }}" class="card space-y-2 lg:col-span-2">
            @csrf
            <h2 class="section-title mb-2">{{ __('Which station prepares each category?') }}</h2>
            @foreach ($categories as $c)
                <label class="flex min-h-11 items-center justify-between gap-3">
                    <span class="font-semibold">{{ $c->name }}</span>
                    <select class="field max-w-56" name="stations[{{ $c->id }}]">
                        <option value="">{{ __('All screens') }}</option>
                        @foreach ($stations as $s)<option value="{{ $s->id }}" @selected($c->kitchen_station_id === $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                </label>
            @endforeach
            <button class="btn-primary">{{ __('Save') }}</button>
        </form>
    @endif
</div>
@endsection
