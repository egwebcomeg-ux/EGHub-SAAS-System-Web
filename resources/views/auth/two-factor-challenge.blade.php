{{-- Second login step (dashboard and platform admin): $action (POST), $login (back link), $dark (admin look). --}}
@extends('layouts.base')
@section('title', __('Verification code') . ' · EgHub')
@section('body_class', ($dark ? 'bg-navy-900' : 'bg-navy-50') . ' text-navy-900')

@section('body')
<div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex justify-center"><x-logo :light="$dark" :size="40" /></div>
        <div class="card">
            <h1 class="mb-1 text-xl font-extrabold">{{ __('Verification code') }}</h1>
            <p class="mb-5 text-sm text-navy-500">{{ __('Open your authenticator app and enter the 6-digit code, or use one of your recovery codes.') }}</p>
            <x-flash />
            <form method="post" action="{{ $action }}" class="space-y-4" data-two-factor-form>
                @csrf
                <div>
                    <label class="field-label" for="code">{{ __('Code') }}</label>
                    <input class="field text-center text-xl tracking-widest" id="code" name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" dir="ltr" maxlength="40">
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember_device" value="1" class="size-4 accent-brand-600"> {{ __('Remember this device for 30 days') }}</label>
                <button class="btn-primary w-full">{{ __('Continue') }}</button>
            </form>
            <a href="{{ $login }}" class="mt-4 block text-center text-sm text-navy-500 hover:text-navy-800">{{ __('Back to sign in') }}</a>
        </div>
    </div>
</div>
@endsection
