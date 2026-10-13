@extends('layouts.base')
@section('title', __('Forgot your password?') . ' · EgMenu')

@section('body')
<div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex justify-center"><x-logo :size="40" /></div>
        <div class="card">
            <h1 class="mb-1 text-xl font-extrabold">{{ __('Forgot your password?') }}</h1>
            <p class="mb-5 text-sm text-navy-500">{{ __('Enter the email of your account and we will send you a link to choose a new password.') }}</p>
            <x-flash />
            <form method="post" action="{{ route($prefix . '.password.email') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="field-label" for="email">{{ __('Email') }}</label>
                    <input class="field" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" dir="ltr">
                </div>
                <button class="btn-primary w-full">{{ __('Send reset link') }}</button>
            </form>
            @if ($prefix === 'dash')
                <p class="mt-4 text-xs text-navy-500">{{ __('Do you sign in with your mobile number? Ask the restaurant owner to set a new password for you from Users & roles.') }}</p>
            @endif
        </div>
        <div class="mt-4 text-center text-sm"><a class="text-navy-500 hover:text-navy-800" href="{{ route($prefix . '.login') }}">{{ __('Back to sign in') }}</a></div>
    </div>
</div>
@endsection
