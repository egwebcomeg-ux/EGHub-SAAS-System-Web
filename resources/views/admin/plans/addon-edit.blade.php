@extends('layouts.admin')
@section('title', $addon->displayName() . ' · EgHub Admin')

@section('content')
@php($field = fn (string $name) => "addons[{$addon->id}][$name]")
<a href="{{ route('admin.addons') }}" class="text-sm font-semibold text-navy-500 hover:text-navy-800">← {{ __('Add-ons') }}</a>
<div class="mt-1 mb-4 flex flex-wrap items-center gap-2">
    <h1 class="page-title">{{ __('Edit add-on') }}: {{ $addon->displayName() }}</h1>
    <span class="badge {{ $addon->isFeaturePack() ? 'bg-brand-100 text-brand-700' : 'bg-navy-100 text-navy-700' }}">{{ $addon->isFeaturePack() ? __('Feature pack') : ($addon->isCreditPack() ? __('WhatsApp credits') . ' · ' . number_format((int) $addon->credits) : __('Extra limit')) }}</span>
</div>

{{-- saved through the same route as the old all-in-one form, with only this add-on --}}
<form method="post" action="{{ route('admin.addons.update') }}" class="space-y-4" data-addon-form>
    @csrf
    <input type="hidden" name="return" value="addons">
    <section class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div><label class="field-label" for="a-name">{{ __('Name (Arabic)') }}</label><input class="field" id="a-name" name="{{ $field('name') }}" value="{{ old("addons.{$addon->id}.name", $addon->name) }}" required maxlength="60"></div>
        <div><label class="field-label" for="a-name-en">{{ __('Name (English)') }}</label><input class="field" id="a-name-en" name="{{ $field('name_en') }}" value="{{ old("addons.{$addon->id}.name_en", $addon->name_en) }}" maxlength="60" dir="ltr"></div>
        <div><label class="field-label" for="a-price">{{ $addon->isCreditPack() ? __('Price (EGP, once)') : __('Monthly price (EGP)') }}</label><input class="field" id="a-price" name="{{ $field('monthly_price') }}" type="number" step="1" min="0" value="{{ old("addons.{$addon->id}.monthly_price", $addon->monthly_price) }}" required></div>
        <div class="sm:col-span-2 lg:col-span-3"><label class="field-label" for="a-desc">{{ __('Description') }}</label><input class="field" id="a-desc" name="{{ $field('description') }}" value="{{ old("addons.{$addon->id}.description", $addon->description) }}" maxlength="200"></div>
        <div>
            <label class="field-label" for="a-min">{{ __('Available from plan') }}</label>
            <select class="field" id="a-min" name="{{ $field('min_plan') }}">
                <option value="">{{ __('Any plan') }}</option>
                @foreach ($plans as $pl)<option value="{{ $pl->code }}" @selected($addon->min_plan === $pl->code)>{{ $pl->displayName() }}</option>@endforeach
            </select>
        </div>
        <div class="flex flex-col justify-end gap-2 text-sm sm:col-span-1 lg:col-span-2">
            <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="{{ $field('is_active') }}" value="1" @checked($addon->is_active) class="size-5 accent-brand-600"> {{ __('On sale') }}</label>
            <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="{{ $field('per_branch') }}" value="1" @checked($addon->per_branch) class="size-5 accent-brand-600"> {{ __('Priced per branch (40% off each extra branch)') }}</label>
        </div>
    </section>

    @if ($addon->isFeaturePack())
        <section class="card">
            <h2 class="section-title">{{ __('Features in this pack') }}</h2>
            <p class="muted mb-3">{{ __('A restaurant that buys the pack gets these features on top of its plan.') }}</p>
            @include('admin.plans.feature-picker', ['name' => $field('features') . '[]', 'selected' => $addon->features ?? []])
        </section>
    @endif

    <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap gap-2 border-t border-navy-100 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
        <button class="btn-primary">{{ __('Save add-on') }}</button>
        <a href="{{ route('admin.addons') }}" class="btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
