@extends('layouts.admin')
@section('title', __('New restaurant') . ' · EgMenu Admin')

@section('content')
<h1 class="mb-1 text-2xl font-extrabold">{{ __('New restaurant') }}</h1>
<p class="mb-4 max-w-3xl text-sm text-navy-500">{{ __('For a prospect: we make the place, a temporary password the owner changes at the first sign-in, and a welcome kit to send them (a printable page, an email and a WhatsApp message).') }}</p>
<form method="post" action="{{ route('admin.tenants.store') }}" class="card grid max-w-3xl gap-4 sm:grid-cols-2" data-prospect-form>
    @csrf
    <div>
        <label class="field-label" for="name">{{ __('Place name') }}</label>
        <input class="field" name="name" id="name" value="{{ old('name') }}" required maxlength="100">
        @error('name')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div>
        <label class="field-label" for="slug">{{ __('Menu link') }}</label>
        <div class="flex items-center gap-1" dir="ltr">
            <span class="shrink-0 text-sm text-navy-500">{{ central_domain() }}/</span>
            <input class="field" name="slug" id="slug" value="{{ old('slug') }}" required pattern="[a-z0-9\-]{3,30}" placeholder="my-cafe">
        </div>
        @error('slug')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div class="sm:col-span-2">
        <span class="field-label">{{ __('Kind of place') }}</span>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
            @foreach (\App\Support\BusinessType::TYPES as $key => $labels)
                <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-navy-100 px-3 text-sm font-semibold has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                    <input type="radio" name="business_type" value="{{ $key }}" @checked(old('business_type', \App\Support\BusinessType::DEFAULT) === $key) class="accent-brand-600">
                    <span>{{ \App\Support\BusinessType::emoji($key) }} {{ \App\Support\BusinessType::label($key) }}</span>
                </label>
            @endforeach
        </div>
    </div>
    <div>
        <label class="field-label" for="owner_name">{{ __('Owner name') }}</label>
        <input class="field" name="owner_name" id="owner_name" value="{{ old('owner_name') }}" required maxlength="100">
    </div>
    <div>
        <label class="field-label" for="phone">{{ __('Owner mobile') }}</label>
        <input class="field" name="phone" id="phone" value="{{ old('phone') }}" dir="ltr" placeholder="01xxxxxxxxx" inputmode="tel">
        <p class="mt-1 text-xs text-navy-500">{{ __('They can sign in with it, and the WhatsApp button sends the kit to it.') }}</p>
        @error('phone')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div class="sm:col-span-2">
        <label class="field-label" for="email">{{ __('Owner email') }} <span class="font-normal text-navy-500">({{ __('optional') }})</span></label>
        <input class="field" name="email" id="email" type="email" value="{{ old('email') }}" dir="ltr" maxlength="120" data-owner-email>
        <p class="mt-1 text-xs text-navy-500" data-placeholder-hint>{{ __('Empty: the sign-in email becomes :email (a placeholder, never mailed) until the owner sets a real one.', ['email' => 'slug@' . \App\Services\WelcomeKit::PLACEHOLDER_DOMAIN]) }}</p>
        @error('email')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div>
        <label class="field-label" for="plan">{{ __('Plan') }}</label>
        <select class="field" name="plan" id="plan">
            @foreach ($plans as $p)
                <option value="{{ $p->code }}" @selected(old('plan', \App\Services\TenantProvisioner::TRIAL_PLAN) === $p->code)>{{ $p->displayName() }} — {{ $p->isContactSales() ? __('Contact sales') : money($p->monthly_price) . ' ' . __('EGP') }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="field-label" for="trial_days">{{ __('Trial length (days)') }}</label>
        <input class="field" name="trial_days" id="trial_days" type="number" min="0" max="90" value="{{ old('trial_days', \App\Services\TenantProvisioner::TRIAL_DAYS) }}" dir="ltr">
        <p class="mt-1 text-xs text-navy-500">{{ __('0 = no trial: the plan starts active.') }}</p>
        @error('trial_days')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <label class="flex items-start gap-2 text-sm sm:col-span-2">
        <input type="hidden" name="demo" value="0">
        <input type="checkbox" name="demo" value="1" @checked(old('demo', '1') === '1') class="mt-0.5 size-4 accent-brand-600" data-demo-toggle>
        <span><b>{{ __('Fill demo data for the other modules') }}</b>
            <span class="block text-xs text-navy-500">{{ __('Sample orders of the last 7 days, guests, a loyalty card, a coupon, an offer, a booking and more (as the plan allows), all marked "(تجربة)". The owner clears them in one click.') }}</span></span>
    </label>
    <div class="rounded-xl bg-navy-50 p-3 text-sm text-navy-600 sm:col-span-2">
        {{ __('Their menu file (Excel, PDF or photos) is imported later from the dashboard: Menu → Import.') }}
        <a href="{{ platform_url('app', '/menu/import') }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 underline">{{ __('Open the import page') }}</a>
    </div>
    <div class="sm:col-span-2"><button class="btn-primary">{{ __('Create the restaurant and its welcome kit') }}</button></div>
</form>
@endsection
