@extends('layouts.admin')
@section('title', __('New restaurant') . ' · EgMenu Admin')

@section('content')
<h1 class="mb-4 text-2xl font-extrabold">{{ __('New restaurant') }}</h1>
<form method="post" action="{{ route('admin.tenants.store') }}" class="card grid max-w-3xl gap-4 sm:grid-cols-2">
    @csrf
    <div>
        <label class="field-label">{{ __('Restaurant name') }}</label>
        <input class="field" name="name" value="{{ old('name') }}" required>
    </div>
    <div>
        <label class="field-label">{{ __('Menu link') }}</label>
        <div class="flex items-center gap-1" dir="ltr">
            <span class="text-sm text-navy-500">{{ central_domain() }}/</span>
            <input class="field" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9-]{3,30}" placeholder="my-cafe">
        </div>
        @error('slug')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div>
        <label class="field-label">{{ __('Owner name') }}</label>
        <input class="field" name="owner_name" value="{{ old('owner_name') }}" required>
    </div>
    <div>
        <label class="field-label">{{ __('Owner email') }}</label>
        <input class="field" name="email" type="email" value="{{ old('email') }}" required dir="ltr">
    </div>
    <div>
        <label class="field-label">{{ __('Mobile') }}</label>
        <input class="field" name="phone" value="{{ old('phone') }}" dir="ltr" placeholder="01xxxxxxxxx">
    </div>
    <div>
        <label class="field-label">{{ __('Password') }}</label>
        <input class="field" name="password" type="text" value="{{ old('password') }}" required minlength="8" dir="ltr">
    </div>
    <div>
        <label class="field-label">{{ __('Plan') }}</label>
        <select class="field" name="plan">
            @foreach ($plans as $p)
                <option value="{{ $p->code }}" @selected(old('plan', 'pro') === $p->code)>{{ $p->displayName() }} — {{ money($p->monthly_price) }} {{ __('EGP') }}</option>
            @endforeach
        </select>
    </div>
    <label class="flex items-center gap-2 self-end text-sm"><input type="checkbox" name="trial" value="1" checked class="size-4 accent-brand-600"> {{ __(':days-day free trial', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</label>
    <div class="sm:col-span-2"><button class="btn-primary">{{ __('Create restaurant') }}</button></div>
</form>
@endsection
