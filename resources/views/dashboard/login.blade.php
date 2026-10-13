@extends('layouts.base')
@section('title', __('Restaurant login') . ' · EgMenu')

@section('body')
<div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex justify-center"><x-logo :size="40" /></div>
        <div class="card">
            <h1 class="mb-1 text-xl font-extrabold">{{ __('Restaurant login') }}</h1>
            <p class="mb-5 text-sm text-navy-500">{{ __('Sign in with your email or mobile number.') }}</p>
            <x-flash />
            <form method="post" action="{{ route('dash.login') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="field-label" for="login">{{ __('Email or mobile') }}</label>
                    <input class="field" id="login" name="login" value="{{ old('login') }}" required autofocus autocomplete="username" dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="password">{{ __('Password') }}</label>
                    <input class="field" id="password" name="password" type="password" required autocomplete="current-password" dir="ltr">
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1" class="size-4 accent-brand-600"> {{ __('Remember me') }}</label>
                <button class="btn-primary w-full">{{ __('Sign in') }}</button>
            </form>
            <a href="{{ route('dash.password.request') }}" class="mt-4 block text-center text-sm text-brand-700">{{ __('Forgot your password?') }}</a>
        </div>
        <div class="mt-4 text-center text-sm">
            <a class="text-navy-500 hover:text-navy-800" href="?lang={{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</a>
        </div>
    </div>
</div>
@endsection
