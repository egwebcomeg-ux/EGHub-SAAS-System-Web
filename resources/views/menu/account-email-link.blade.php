@extends('layouts.base')
@section('title', __('Sign in') . ' | ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@push('head')
@include('menu.partials.theme')
@endpush

@section('body')
{{-- the emailed sign-in link (GuestEmailController::link): one button posts it, so a mail scanner opening links never uses it up --}}
<main class="mx-auto max-w-md px-4 pb-10 pt-6" data-email-link-page>
    @include('menu.partials.account-head', ['title' => ($confirm ?? false) ? __('Confirm your email') : __('Sign in')])
    @if ($valid)
        <form method="post" action="{{ ($confirm ?? false) ? route('menu.account.email.confirm.save', $token) : route('menu.account.email.redeem', $token) }}" class="m-surface rounded-3xl p-5 text-center shadow-sm" data-email-link-form>
            @csrf
            <p class="text-4xl" aria-hidden="true">✉️</p>
            <p class="mt-2 font-bold">{{ ($confirm ?? false) ? __('One tap to confirm your email.') : __('One tap and you are in your account on this phone.') }}</p>
            <button class="btn-primary mt-4 w-full min-h-12" data-email-link-go>{{ ($confirm ?? false) ? __('Confirm my email') : __('Sign in to my account') }}</button>
        </form>
        <script>
            // a real visitor goes straight in; a mail scanner that only fetches the page never posts it
            (function () { var f = document.querySelector('[data-email-link-form]'); if (f && !document.hidden) { setTimeout(function () { f.requestSubmit ? f.requestSubmit() : f.submit(); }, 400); } })();
        </script>
    @else
        <div class="m-surface rounded-3xl p-5 text-center shadow-sm" data-email-link-expired>
            <p class="text-4xl" aria-hidden="true">⌛</p>
            <p class="mt-2 font-bold">{{ __('This link was used or expired. Ask for a new one.') }}</p>
            <a href="{{ route('menu.account.sign-in') }}" class="btn-primary mt-4 w-full min-h-12">{{ __('Sign in') }}</a>
        </div>
    @endif
    <a href="{{ route('menu.show') }}" class="btn-ghost mt-5 w-full min-h-12">{{ __('Back to the menu') }}</a>
</main>
@endsection
