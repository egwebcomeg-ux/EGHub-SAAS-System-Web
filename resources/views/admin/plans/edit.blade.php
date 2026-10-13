@extends('layouts.admin')
@section('title', $plan->displayName() . ' · EgMenu Admin')

@section('content')
<h1 class="mb-4 text-2xl font-extrabold">{{ __('Edit plan') }}: {{ $plan->displayName() }}</h1>

<form method="post" action="{{ route('admin.plans.update', $plan) }}" class="space-y-4">
    @csrf
    <div class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><label class="field-label">{{ __('Name (Arabic)') }}</label><input class="field" name="name" value="{{ old('name', $plan->name) }}" required></div>
        <div><label class="field-label">{{ __('Name (English)') }}</label><input class="field" name="name_en" value="{{ old('name_en', $plan->name_en) }}" dir="ltr"></div>
        <div><label class="field-label">{{ __('Monthly price (EGP)') }}</label><input class="field" name="monthly_price" type="number" step="0.01" min="0" value="{{ old('monthly_price', $plan->monthly_price) }}" required></div>
        <div><label class="field-label">{{ __('Crossed-out price (optional)') }}</label><input class="field" name="original_price" type="number" step="0.01" min="0" value="{{ old('original_price', $plan->original_price) }}"></div>
        <div class="sm:col-span-2"><label class="field-label">{{ __('Tagline (Arabic)') }}</label><input class="field" name="tagline" value="{{ old('tagline', $plan->tagline) }}"></div>
        <div class="sm:col-span-2"><label class="field-label">{{ __('Tagline (English)') }}</label><input class="field" name="tagline_en" value="{{ old('tagline_en', $plan->tagline_en) }}" dir="ltr"></div>
        <div><label class="field-label">{{ __('6-month discount %') }}</label><input class="field" name="discount_6" type="number" step="0.5" min="0" max="90" value="{{ $plan->cycle_discounts['6'] ?? 0 }}"></div>
        <div><label class="field-label">{{ __('Yearly discount %') }}</label><input class="field" name="discount_12" type="number" step="0.5" min="0" max="90" value="{{ $plan->cycle_discounts['12'] ?? 0 }}"></div>
        <div><label class="field-label">{{ __('Order on pricing page') }}</label><input class="field" name="sort_order" type="number" value="{{ $plan->sort_order }}"></div>
        <div class="flex flex-col justify-end gap-2 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" name="is_public" value="1" @checked($plan->is_public) class="size-4 accent-brand-600"> {{ __('Shown on pricing page') }}</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_featured" value="1" @checked($plan->is_featured) class="size-4 accent-brand-600"> {{ __('Most popular') }}</label>
        </div>
    </div>

    <div class="card">
        <h2 class="mb-3 text-lg font-bold">{{ __('Limits') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (\App\Models\Plan::LIMITS as $key => $_)
                @php($v = $rights['limits'][$key])
                <div>
                    <label class="field-label">{{ \App\Models\Plan::label($key) }}</label>
                    <input class="field" name="limits[{{ $key }}]" type="number" min="0" value="{{ $v ?? '' }}">
                    <label class="mt-1 flex items-center gap-2 text-xs"><input type="checkbox" name="unlimited[{{ $key }}]" value="1" @checked($v === null) class="size-4 accent-brand-600"> {{ __('Unlimited') }}</label>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card">
        <h2 class="mb-3 text-lg font-bold">{{ __('Features') }}</h2>
        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            @foreach (\App\Models\Plan::GROUPS as $group => $gl)
                <fieldset>
                    <legend class="mb-2 text-sm font-bold text-navy-500">{{ app()->getLocale() === 'en' ? $gl[1] : $gl[0] }}</legend>
                    @foreach (\App\Models\Plan::FEATURES as $key => $f)
                        @continue($f[2] !== $group)
                        <label class="flex min-h-9 items-center gap-2 text-sm"><input type="checkbox" name="features[]" value="{{ $key }}" @checked($rights['features'][$key]) class="size-4 accent-brand-600"> {{ \App\Models\Plan::label($key) }}</label>
                    @endforeach
                </fieldset>
            @endforeach
        </div>
    </div>

    <div class="flex gap-2">
        <button class="btn-primary">{{ __('Save plan') }}</button>
        <a href="{{ route('admin.plans') }}" class="btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
