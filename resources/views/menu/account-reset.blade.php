@extends('layouts.base')
@section('title', __('Choose a new password') . ' | ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@push('head')
@include('menu.partials.theme')
@endpush

@section('body')
{{-- «نسيت الباسورد؟»: the emailed link (GuestEmailController::resetForm), one use --}}
<main class="mx-auto max-w-md px-4 pb-10 pt-6" data-reset-page>
    @include('menu.partials.account-head', ['title' => __('Choose a new password')])
    @if ($valid)
        <form method="post" action="{{ route('menu.account.reset.save', $token) }}" class="m-surface grid gap-3 rounded-3xl p-5 shadow-sm" data-reset-form>
            @csrf
            <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('New password') }} <span class="m-muted font-semibold">({{ __('8 characters or more') }})</span></span>
                <input class="field" type="password" name="password" required minlength="8" maxlength="200" autocomplete="new-password" dir="ltr"></label>
            <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Confirm the password') }}</span>
                <input class="field" type="password" name="password_confirmation" required minlength="8" maxlength="200" autocomplete="new-password" dir="ltr"></label>
            @error('password')<p class="text-sm font-semibold text-red-600" role="alert">{{ $message }}</p>@enderror
            <button class="btn-primary w-full min-h-12">{{ __('Save and sign in') }}</button>
        </form>
    @else
        <div class="m-surface rounded-3xl p-5 text-center shadow-sm" data-reset-expired>
            <p class="text-4xl" aria-hidden="true">⌛</p>
            <p class="mt-2 font-bold">{{ __('This link was used or expired. Ask for a new one.') }}</p>
            <a href="{{ route('menu.account.sign-in', ['tab' => 'login']) }}" class="btn-primary mt-4 w-full min-h-12">{{ __('Forgot your password?') }}</a>
        </div>
    @endif
    <a href="{{ route('menu.show') }}" class="btn-ghost mt-5 w-full min-h-12">{{ __('Back to the menu') }}</a>
</main>
@endsection
