@extends('layouts.base')
@section('title', __('Choose a new password') . ' · EgHub')

@section('body')
<div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex justify-center"><x-logo :size="40" /></div>
        <div class="card">
            <h1 class="mb-5 text-xl font-extrabold">{{ __('Choose a new password') }}</h1>
            <x-flash />
            <form method="post" action="{{ route($prefix . '.password.update') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div>
                    <label class="field-label" for="email">{{ __('Email') }}</label>
                    <input class="field" id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email" dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="password">{{ __('New password') }}</label>
                    <input class="field" id="password" name="password" type="password" required minlength="8" autocomplete="new-password" dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="password_confirmation">{{ __('Confirm the new password') }}</label>
                    <input class="field" id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" dir="ltr">
                </div>
                <button class="btn-primary w-full">{{ __('Save new password') }}</button>
            </form>
        </div>
    </div>
</div>
@endsection
