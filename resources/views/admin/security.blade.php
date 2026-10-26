@extends('layouts.admin')
@section('title', __('Account security') . ' · EgHub Admin')

@section('content')
<h1 class="page-title mb-1">{{ __('Account security') }}</h1>
<p class="muted mb-4" dir="ltr">{{ $user->email }}</p>

<section class="card mb-4 max-w-5xl" id="password" data-password-form>
    <h2 class="section-title">{{ __('Your password') }}</h2>
    <form method="post" action="{{ route('admin.security.password') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
        @csrf
        <label class="block"><span class="field-label">{{ __('Current password') }}</span>
            <input class="field" name="current_password" type="password" autocomplete="current-password" required dir="ltr">
            @error('current_password')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label class="block"><span class="field-label">{{ __('New password') }}</span>
            <input class="field" name="password" type="password" autocomplete="new-password" minlength="10" required dir="ltr">
            @error('password')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label class="block"><span class="field-label">{{ __('Repeat the new password') }}</span>
            <input class="field" name="password_confirmation" type="password" autocomplete="new-password" minlength="10" required dir="ltr"></label>
        <div class="sm:col-span-3"><button class="btn-primary">{{ __('Save the new password') }}</button></div>
    </form>
</section>

@include('auth.two-factor-settings')
@endsection
