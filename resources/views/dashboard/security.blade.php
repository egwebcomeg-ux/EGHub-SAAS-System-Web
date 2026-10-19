@extends('layouts.dashboard')
@section('title', __('Account security'))

@section('content')
<x-dash.page-header :title="__('Account security')" />
<p class="muted mb-4" dir="ltr">{{ $user->email ?? $user->phone }}</p>
@include('auth.two-factor-settings')

@if ($user->can('vouchers.exception') && \App\Services\Corporate\CorporateVouchers::enabled())
    {{-- manager PIN: approves an expired corporate voucher on the cashier's device without signing the cashier out --}}
    <section class="card mt-4 max-w-5xl" data-manager-pin>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="section-title">{{ __('Manager PIN') }}</h2>
                <p class="mt-1 text-sm text-navy-500">{{ __('4 to 6 digits you type on the cashier\'s screen to approve an expired corporate voucher, without the cashier signing out. Keep it to yourself.') }}</p>
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
