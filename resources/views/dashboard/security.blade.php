@extends('layouts.dashboard')
@section('title', __('Account security'))

@section('content')
<x-dash.page-header :title="__('Account security')" />
<p class="muted mb-4" dir="ltr">{{ $user->email ?? $user->phone }}</p>

{{-- the account's own password; a welcome-kit owner lands here until they pick one (InitializeTenancyFromUser) --}}
<section @class(['card mb-4 max-w-5xl', 'border-2 border-brand-200' => $user->must_change_password]) id="password" data-password-form>
    <h2 class="section-title">{{ __('Your password') }}</h2>
    @if ($user->must_change_password)
        <p class="mt-1 rounded-xl bg-brand-50 p-3 text-sm font-semibold text-brand-800" data-must-change>{{ __('You signed in with a temporary password. Choose your own to open the rest of your dashboard.') }}</p>
    @else
        <p class="mt-1 text-sm text-navy-500">{{ __('At least 8 characters with letters and numbers.') }}</p>
    @endif
    <form method="post" action="{{ route('dash.security.password') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
        @csrf
        <label class="block"><span class="field-label">{{ $user->must_change_password ? __('Temporary password') : __('Current password') }}</span>
            <input class="field" name="current_password" type="password" autocomplete="current-password" required dir="ltr">
            @error('current_password')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label class="block"><span class="field-label">{{ __('New password') }}</span>
            <input class="field" name="password" type="password" autocomplete="new-password" minlength="8" required dir="ltr">
            @error('password')<span class="field-error">{{ $message }}</span>@enderror</label>
        <label class="block"><span class="field-label">{{ __('Repeat the new password') }}</span>
            <input class="field" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required dir="ltr"></label>
        <div class="sm:col-span-3"><button class="btn-primary">{{ __('Save the new password') }}</button></div>
    </form>
</section>

@include('auth.two-factor-settings')

@if (\App\Http\Controllers\Dashboard\SecurityController::mayHavePin($user))
    {{-- manager PIN: approves an expired corporate voucher or a refund on the cashier's device without signing the cashier out --}}
    <section class="card mt-4 max-w-5xl" data-manager-pin>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="section-title">{{ __('Manager PIN') }}</h2>
                <p class="mt-1 text-sm text-navy-500">{{ __('4 to 6 digits you type on the cashier\'s screen to approve an expired corporate voucher or a refund, without the cashier signing out. Keep it to yourself.') }}</p>
            </div>
            @if ($user->manager_pin)
                <span class="badge bg-emerald-100 text-emerald-800" data-pin-state="on">✓ {{ __('Set') }}</span>
            @else
                <span class="badge bg-navy-100 text-navy-600" data-pin-state="off">{{ __('Not set') }}</span>
            @endif
        </div>
        <form method="post" action="{{ route('dash.security.pin') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
            @csrf
            <label class="block"><span class="field-label">{{ __('New PIN') }}</span>
                <input class="field text-lg tracking-[.4em]" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="new-password" dir="ltr" required></label>
            <label class="block"><span class="field-label">{{ __('Repeat the PIN') }}</span>
                <input class="field text-lg tracking-[.4em]" name="pin_confirmation" type="password" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="new-password" dir="ltr" required></label>
            <label class="block"><span class="field-label">{{ __('Your password') }}</span>
                <input class="field" name="current_password" type="password" autocomplete="current-password" required></label>
            <div class="flex flex-wrap gap-2 sm:col-span-3">
                <button class="btn-primary">{{ $user->manager_pin ? __('Change the PIN') : __('Save the PIN') }}</button>
            </div>
        </form>
        @if ($user->manager_pin)
            <form method="post" action="{{ route('dash.security.pin') }}" class="mt-3 flex flex-wrap items-end gap-2 border-t border-navy-100 pt-3">
                @csrf
                <input type="hidden" name="remove" value="1">
                <label class="block"><span class="field-label">{{ __('Your password') }}</span>
                    <input class="field" name="current_password" type="password" autocomplete="current-password" required></label>
                <button class="btn-ghost text-red-700">{{ __('Remove the PIN') }}</button>
            </form>
        @endif
    </section>
@endif
@endsection
