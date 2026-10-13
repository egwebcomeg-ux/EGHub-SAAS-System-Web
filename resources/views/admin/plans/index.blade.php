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
<h2 class="mt-8 mb-1 text-xl font-extrabold">{{ __('Add-ons') }}</h2>
<p class="mb-4 text-sm text-navy-500">{{ __('Sold on top of any plan. A pack unlocks the features ticked below; extra branch/staff raise a limit.') }}</p>
<form method="post" action="{{ route('admin.addons.update') }}" class="space-y-4">
    @csrf
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($addons as $a)
            <div class="card space-y-3">
                <div class="flex items-center justify-between">
                    <span class="badge {{ $a->isFeaturePack() ? 'bg-brand-100 text-brand-700' : 'bg-navy-100 text-navy-700' }}">{{ $a->isFeaturePack() ? __('Feature pack') : __('Extra limit') }}</span>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="addons[{{ $a->id }}][is_active]" value="1" @checked($a->is_active) class="size-4 accent-brand-600"> {{ __('On sale') }}</label>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <input class="field" name="addons[{{ $a->id }}][name]" value="{{ $a->name }}" required>
                    <input class="field" name="addons[{{ $a->id }}][name_en]" value="{{ $a->name_en }}" dir="ltr">
                    <input class="field" name="addons[{{ $a->id }}][monthly_price]" type="number" step="1" min="0" value="{{ $a->monthly_price }}" required>
                </div>
                <input class="field" name="addons[{{ $a->id }}][description]" value="{{ $a->description }}" placeholder="{{ __('Description') }}">
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="addons[{{ $a->id }}][per_branch]" value="1" @checked($a->per_branch) class="size-4 accent-brand-600"> {{ __('Priced per branch (40% off each extra branch)') }}</label>
                    <label class="flex items-center gap-2">{{ __('Available from plan') }}
                        <select class="field min-h-9 w-auto py-0" name="addons[{{ $a->id }}][min_plan]">
                            <option value="">{{ __('Any plan') }}</option>
                            @foreach (\App\Models\Plan::query()->orderBy('sort_order')->get() as $pl)<option value="{{ $pl->code }}" @selected($a->min_plan === $pl->code)>{{ $pl->displayName() }}</option>@endforeach
                        </select>
                    </label>
                </div>
                @if ($a->isFeaturePack())
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach (\App\Models\Plan::FEATURES as $key => $f)
                            <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" name="addons[{{ $a->id }}][features][]" value="{{ $key }}" @checked(in_array($key, $a->features ?? [], true)) class="size-3.5 accent-brand-600"> {{ \App\Models\Plan::label($key) }}</label>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    <button class="btn-primary">{{ __('Save add-ons') }}</button>
</form>
@endsection
