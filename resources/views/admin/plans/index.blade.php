@extends('layouts.admin')
@section('title', __('Plans & features') . ' · EgMenu Admin')

@section('content')
<h1 class="mb-1 text-2xl font-extrabold">{{ __('Plans & features') }}</h1>
<p class="mb-4 text-sm text-navy-500">{{ __('Which plan unlocks what. Click a plan to change its price, features and limits; changes apply to all its restaurants immediately.') }}</p>

<div class="card overflow-x-auto">
    <table class="table-base">
        <thead>
            <tr>
                <th></th>
                @foreach ($plans as $p)
                    <th class="text-center">
                        <a href="{{ route('admin.plans.edit', $p) }}" class="text-base font-extrabold text-brand-700 hover:underline">{{ $p->displayName() }}</a>
                        <div class="font-normal">{{ money($p->monthly_price) }} {{ __('EGP / month') }}</div>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach (\App\Models\Plan::LIMITS as $key => $_)
                <tr>
                    <td class="font-semibold">{{ \App\Models\Plan::label($key) }}</td>
                    @foreach ($plans as $p)
                        @php($v = $matrix[$p->id]['limits'][$key])
                        <td class="text-center font-semibold">{{ $v === null ? '∞' : $v }}</td>
                    @endforeach
                </tr>
            @endforeach
            @foreach (\App\Models\Plan::GROUPS as $group => $gl)
                <tr><td colspan="{{ $plans->count() + 1 }}" class="bg-navy-50 text-xs font-bold text-navy-500">{{ app()->getLocale() === 'en' ? $gl[1] : $gl[0] }}</td></tr>
                @foreach (\App\Models\Plan::FEATURES as $key => $f)
                    @continue($f[2] !== $group)
                    <tr>
                        <td>{{ \App\Models\Plan::label($key) }}</td>
                        @foreach ($plans as $p)
                            <td class="text-center">{!! $matrix[$p->id]['features'][$key] ? '<span class="text-emerald-600">✓</span>' : '<span class="text-navy-200">—</span>' !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
@endsection
