@extends('layouts.admin')
@section('title', $plan->displayName() . ' · EgMenu Admin')

@section('content')
<a href="{{ route('admin.plans') }}" class="text-sm font-semibold text-navy-500 hover:text-navy-800">← {{ __('Plans') }}</a>
<h1 class="page-title mt-1 mb-4">{{ __('Edit plan') }}: {{ $plan->displayName() }}</h1>

<form method="post" action="{{ route('admin.plans.update', $plan) }}" class="space-y-4" data-plan-form>
    @csrf
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="card grid gap-4 sm:grid-cols-2">
            <h2 class="section-title sm:col-span-2">{{ __('Name & tagline') }}</h2>
            <div><label class="field-label" for="p-name">{{ __('Name (Arabic)') }}</label><input class="field" id="p-name" name="name" value="{{ old('name', $plan->name) }}" required></div>
            <div><label class="field-label" for="p-name-en">{{ __('Name (English)') }}</label><input class="field" id="p-name-en" name="name_en" value="{{ old('name_en', $plan->name_en) }}" dir="ltr"></div>
            <div class="sm:col-span-2"><label class="field-label" for="p-tag">{{ __('Tagline (Arabic)') }}</label><input class="field" id="p-tag" name="tagline" value="{{ old('tagline', $plan->tagline) }}"></div>
            <div class="sm:col-span-2"><label class="field-label" for="p-tag-en">{{ __('Tagline (English)') }}</label><input class="field" id="p-tag-en" name="tagline_en" value="{{ old('tagline_en', $plan->tagline_en) }}" dir="ltr"></div>
        </section>

        <section class="card grid gap-4 sm:grid-cols-2">
            <h2 class="section-title sm:col-span-2">{{ __('Price & pricing page') }}</h2>
            <div><label class="field-label" for="p-price">{{ __('Monthly price (EGP)') }}</label><input class="field" id="p-price" name="monthly_price" type="number" step="0.01" min="0" value="{{ old('monthly_price', $plan->monthly_price) }}" required></div>
            <div><label class="field-label" for="p-orig">{{ __('Crossed-out price (optional)') }}</label><input class="field" id="p-orig" name="original_price" type="number" step="0.01" min="0" value="{{ old('original_price', $plan->original_price) }}"></div>
            <div><label class="field-label" for="p-d6">{{ __('6-month discount %') }}</label><input class="field" id="p-d6" name="discount_6" type="number" step="0.5" min="0" max="90" value="{{ $plan->cycle_discounts['6'] ?? 0 }}"></div>
            <div><label class="field-label" for="p-d12">{{ __('Yearly discount %') }}</label><input class="field" id="p-d12" name="discount_12" type="number" step="0.5" min="0" max="90" value="{{ $plan->cycle_discounts['12'] ?? 0 }}"></div>
            <div><label class="field-label" for="p-sort">{{ __('Order on pricing page') }}</label><input class="field" id="p-sort" name="sort_order" type="number" value="{{ $plan->sort_order }}"></div>
            <div class="flex flex-col justify-end gap-1 text-sm">
                <label class="flex min-h-10 items-center gap-2"><input type="checkbox" name="is_public" value="1" @checked($plan->is_public) class="size-5 accent-brand-600"> {{ __('Shown on pricing page') }}</label>
                <label class="flex min-h-10 items-center gap-2"><input type="checkbox" name="is_featured" value="1" @checked($plan->is_featured) class="size-5 accent-brand-600"> {{ __('Most popular') }}</label>
                <label class="flex min-h-10 items-center gap-2"><input type="checkbox" name="contact_sales" value="1" @checked($plan->contact_sales) class="size-5 accent-brand-600" data-contact-sales-toggle> {{ __('Contact sales (no public price, assigned from here only)') }}</label>
            </div>
        </section>
    </div>

    <section class="card">
        <h2 class="section-title mb-3">{{ __('Limits') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (\App\Models\Plan::LIMITS as $key => $_)
                @php($v = $rights['limits'][$key])
                <div class="rounded-xl border border-navy-100 p-3" data-limit>
                    <label class="field-label" for="l-{{ $key }}">{{ \App\Models\Plan::label($key) }}</label>
                    <input class="field" id="l-{{ $key }}" name="limits[{{ $key }}]" type="number" min="0" value="{{ $v ?? '' }}" @disabled($v === null) data-limit-input>
                    <label class="mt-2 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" name="unlimited[{{ $key }}]" value="1" @checked($v === null) class="size-4 accent-brand-600" data-limit-unlimited> {{ __('Unlimited') }}</label>
                </div>
            @endforeach
        </div>
    </section>

    <section class="card">
        <h2 class="section-title mb-3">{{ __('Features') }}</h2>
        @include('admin.plans.feature-picker', ['name' => 'features[]', 'selected' => array_keys(array_filter($rights['features']))])
    </section>

    <div class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-t border-navy-100 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
        <button class="btn-primary">{{ __('Save plan') }}</button>
        <a href="{{ route('admin.plans') }}" class="btn-ghost">{{ __('Cancel') }}</a>
        <span class="ms-auto hidden text-xs text-navy-500 sm:block">{{ __('Applies to every restaurant on this plan right away.') }}</span>
    </div>
</form>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-limit]').forEach((box) => {
        const input = box.querySelector('[data-limit-input]');
        box.querySelector('[data-limit-unlimited]').addEventListener('change', (e) => { input.disabled = e.target.checked; if (!e.target.checked) { input.focus(); } });
    });
</script>
@endpush
