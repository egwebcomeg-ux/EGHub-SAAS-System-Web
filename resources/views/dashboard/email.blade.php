{{-- "Confirm your email" (Dashboard\EmailVerificationController): $user, $email (masked). Never a gate. --}}
@extends('layouts.dashboard')
@section('title', __('Confirm your email'))

@section('content')
<x-dash.page-header :title="__('Confirm your email')" :help="__('So that password resets, invoices and alerts reach you, and nobody else.')" />

<div class="grid max-w-5xl gap-4 lg:grid-cols-5" data-email-verify>
    <section class="card space-y-4 lg:col-span-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm text-navy-500">{{ __('Your email') }}</div>
                <div class="text-xl font-extrabold" dir="ltr">{{ $user->email }}</div>
            </div>
            <span class="badge bg-amber-100 text-amber-800">{{ __('Not confirmed yet') }}</span>
        </div>
        <p class="text-sm text-navy-700">{{ __('We sent a 6-digit code to :email. It may take a minute; check the spam folder too.', ['email' => $email]) }}</p>
        <form method="post" action="{{ route('dash.email.verify') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-40 flex-1">
                <label class="field-label" for="code">{{ __('Code from the email') }}</label>
                <input class="field text-center text-xl tracking-widest" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="12">
            </div>
            <button class="btn-primary">{{ __('Confirm') }}</button>
        </form>
        <form method="post" action="{{ route('dash.email.resend') }}">
            @csrf
            <button class="text-sm text-navy-500 underline">{{ __('Send the code again') }}</button>
        </form>
    </section>

    <aside class="card space-y-2 text-sm text-navy-700 lg:col-span-2">
        <h2 class="section-title">{{ __('Wrong email?') }}</h2>
        <p>{{ __('Ask the restaurant owner to correct it on the Team page, or contact EgHub support.') }}</p>
    </aside>
</div>
@endsection
