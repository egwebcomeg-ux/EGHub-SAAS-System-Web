@extends('layouts.base')
@section('title', 'EgMenu Admin')
@section('body_class', 'bg-navy-900 text-navy-900')

@section('body')
<div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex justify-center"><x-logo :light="true" :size="40" /></div>
        <div class="card">
            <h1 class="mb-4 text-xl font-extrabold">{{ __('EgWeb admin') }}</h1>
            <x-flash />
            <form method="post" action="{{ route('admin.login') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="field-label" for="email">{{ __('Email') }}</label>
                    <input class="field" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="password">{{ __('Password') }}</label>
                    <input class="field" id="password" name="password" type="password" required dir="ltr">
                </div>
                <button class="btn-navy w-full">{{ __('Sign in') }}</button>
            </form>
            <a href="{{ route('admin.password.request') }}" class="mt-4 block text-center text-sm text-navy-600">{{ __('Forgot your password?') }}</a>
        </div>
    </div>
</div>
@endsection
