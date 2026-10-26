@extends('layouts.base')
@section('title', __('Sign-in link expired'))

@section('body')
{{-- eghub.app/auth/google/... without a valid restaurant to return to (App\Http\Controllers\Platform\GuestOAuthController) --}}
<main class="mx-auto grid min-h-screen max-w-md place-items-center px-4 text-center" data-guest-sign-in-lost>
    <div class="rounded-3xl bg-white p-6 shadow-sm">
        <h1 class="text-xl font-black">{{ __('Sign-in link expired') }}</h1>
        <p class="mt-2 text-sm text-navy-700">{{ __('Go back to the menu and tap «Sign in with Google» again.') }}</p>
    </div>
</main>
@endsection
