@extends('layouts.admin')
@section('title', ($testimonial->exists ? __('Edit testimonial') : __('New testimonial')) . ' · EgHub Admin')

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-extrabold">{{ $testimonial->exists ? __('Edit testimonial') : __('New testimonial') }}</h1>
    <a href="{{ route('admin.testimonials') }}" class="btn-ghost">{{ __('All testimonials') }}</a>
</div>

@if ($errors->any())<div class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<form method="post" action="{{ $testimonial->exists ? route('admin.testimonials.update', $testimonial) : route('admin.testimonials.store') }}" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-3">
    @csrf
    <div class="space-y-4 lg:col-span-2">
        <div class="card space-y-3">
            <label class="block"><span class="field-label">{{ __('Owner name') }}</span><input class="field" name="name" value="{{ old('name', $testimonial->name) }}" maxlength="80" required></label>
            <label class="block"><span class="field-label">{{ __('Role and place (Arabic)') }}</span><input class="field" name="role" value="{{ old('role', $testimonial->role) }}" maxlength="120" placeholder="{{ __('Owner, Al-Zeny restaurant, Cairo') }}"></label>
            <label class="block"><span class="field-label">{{ __('Role and place (English, optional)') }}</span><input class="field" name="role_en" value="{{ old('role_en', $testimonial->role_en) }}" maxlength="120" dir="ltr"></label>
            <label class="block"><span class="field-label">{{ __('Their words (Arabic)') }}</span><textarea class="field" name="quote" rows="4" maxlength="600" required>{{ old('quote', $testimonial->quote) }}</textarea></label>
            <label class="block"><span class="field-label">{{ __('Their words (English, optional)') }}</span><textarea class="field" name="quote_en" rows="3" maxlength="600" dir="ltr">{{ old('quote_en', $testimonial->quote_en) }}</textarea></label>
            <p class="text-xs text-navy-500">{{ __('Write what the owner actually said. Never invent or polish a quote into something they did not say.') }}</p>
        </div>
    </div>

    <div class="space-y-4">
        <div class="card space-y-3">
            <label class="flex items-start gap-2 text-sm font-semibold"><input type="checkbox" name="consent" value="1" class="mt-0.5 size-4 accent-brand-600" @checked(old('consent', $testimonial->consent_at !== null))> {{ __('The owner agreed to show these words, their name and photo on eghub.app') }}</label>
            @if ($testimonial->consent_at)<p class="text-xs text-navy-500">{{ __('Consent recorded on :date', ['date' => local_time($testimonial->consent_at, 'Y/m/d')]) }}</p>@endif
            <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="is_published" value="1" class="size-4 accent-brand-600" @checked(old('is_published', $testimonial->is_published))> {{ __('Show on the home page') }}</label>
            <label class="block"><span class="field-label">{{ __('Rating (optional)') }}</span>
                <select class="field" name="rating">
                    <option value="">—</option>
                    @foreach ([5, 4, 3] as $stars)<option value="{{ $stars }}" @selected((int) old('rating', $testimonial->rating) === $stars)>{{ str_repeat('★', $stars) }}</option>@endforeach
                </select></label>
            <label class="block"><span class="field-label">{{ __('Restaurant (optional)') }}</span>
                <input class="field" name="tenant_id" value="{{ old('tenant_id', $testimonial->tenant_id) }}" list="testimonial-restaurants" dir="ltr" placeholder="elzeny">
                <datalist id="testimonial-restaurants">@foreach ($restaurants as $slug)<option value="{{ $slug }}">@endforeach</datalist></label>
            <p class="text-xs text-navy-500">{{ __('With a restaurant, the quote links to its live menu.') }}</p>
            <label class="block"><span class="field-label">{{ __('Order (smaller first)') }}</span><input class="field" type="number" name="sort" value="{{ old('sort', $testimonial->sort) }}" min="-999" max="999" dir="ltr"></label>
            <button class="btn-primary w-full">{{ __('Save') }}</button>
        </div>
        <div class="card space-y-3">
            <span class="field-label">{{ __('Owner or restaurant photo') }}</span>
            @if ($testimonial->photo)
                <img src="/storage/{{ $testimonial->photo }}" alt="" class="size-20 rounded-full object-cover">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_photo" value="1" class="size-4 accent-brand-600"> {{ __('Remove the photo') }}</label>
            @endif
            <input class="field" type="file" name="photo" accept="image/*">
            <p class="text-xs text-navy-500">{{ __('Square works best; saved as WebP up to 400px. Without a photo the first letter of the name is shown.') }}</p>
        </div>
    </div>
</form>
@endsection
