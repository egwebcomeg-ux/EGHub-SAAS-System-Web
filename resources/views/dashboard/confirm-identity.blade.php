{{-- "Is it really you?" before a sensitive action (Dashboard\ConfirmIdentityController): $byEmail, $email (masked). --}}
@extends('layouts.dashboard')
@section('title', __('Confirm it is you'))

@section('content')
<x-dash.page-header :title="__('Confirm it is you')" :help="__('This action takes your restaurant\'s data out or changes something hard to undo, so we check it is really you first. Once confirmed, it is not asked again for 15 minutes.')" />

<section class="card max-w-xl space-y-4" data-confirm-identity>
    @if ($byEmail)
        <p class="text-sm text-navy-700">{{ __('We sent a 6-digit code to :email. It may take a minute; check the spam folder too.', ['email' => $email]) }}</p>
        <form method="post" action="{{ route('dash.confirm.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-40 flex-1">
                <label class="field-label" for="code">{{ __('Code from the email') }}</label>
                <input class="field text-center text-xl tracking-widest" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="12">
            </div>
            <button class="btn-primary">{{ __('Confirm') }}</button>
        </form>
        <form method="post" action="{{ route('dash.confirm.resend') }}">
            @csrf
            <button class="text-sm text-navy-500 underline">{{ __('Send the code again') }}</button>
        </form>
    @else
        <form method="post" action="{{ route('dash.confirm.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-48 flex-1">
                <label class="field-label" for="password">{{ __('Your password') }}</label>
                <input class="field" id="password" name="password" type="password" required autofocus autocomplete="current-password" dir="ltr">
            </div>
            <button class="btn-primary">{{ __('Confirm') }}</button>
        </form>
    @endif
    <a href="{{ url()->previous(route('dash.home')) }}" class="block text-sm text-navy-500 hover:text-navy-800">{{ __('Cancel') }}</a>
</section>
@endsection
