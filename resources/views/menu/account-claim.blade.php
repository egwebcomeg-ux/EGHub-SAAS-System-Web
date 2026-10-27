@extends('layouts.base')
@section('title', __('Your account') . ' | ' . (tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name))
@section('body_class', 'm-surface-2 m-text')

@push('head')
@include('menu.partials.theme')
@endpush

@section('body')
{{-- «ادعي عملاءك»: a guest the place already knows finishes their account (GuestEmailController::claim); private, one use --}}
<main class="mx-auto max-w-md px-4 pb-10 pt-6" data-claim-page>
    @if ($customer)
        @php $first = trim(explode(' ', trim((string) $customer->name))[0] ?? ''); @endphp
        @include('menu.partials.account-head', ['title' => $first !== '' ? __('Hi :name 👋', ['name' => $first]) : __('Hi 👋')])
        <div class="m-surface rounded-3xl p-5 shadow-sm">
            <p class="font-bold">{{ __('Your account here is ready: order from your phone in a few taps.') }}</p>
            @if ($address)
                <div class="mt-3 rounded-2xl m-surface-2 p-3 text-sm" data-claim-address>
                    <p class="font-extrabold">📍 {{ __('Your saved address') }}</p>
                    <p class="m-muted mt-1">{{ $address['zone'] !== '' ? $address['zone'].' — ' : '' }}{{ $address['text'] }}</p>
                </div>
            @endif
            @if ($google)
                <form method="post" action="{{ route('menu.account.claim.google', $token) }}" class="mt-4">
                    @csrf
                    <button class="flex min-h-13 w-full items-center justify-center gap-3 rounded-2xl border border-black/10 bg-white px-4 text-base font-bold text-[#1f1f1f] shadow-sm" data-claim-google>
                        <svg class="size-5 shrink-0" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                        {{ __('Continue with Google') }}
                    </button>
                </form>
                <div class="my-4 flex items-center gap-3 text-xs font-bold m-muted" aria-hidden="true"><span class="h-px flex-1 bg-current opacity-20"></span>{{ __('or choose a password') }}<span class="h-px flex-1 bg-current opacity-20"></span></div>
            @endif
            <form method="post" action="{{ route('menu.account.claim.save', $token) }}" class="mt-4 grid gap-3" data-claim-form>
                @csrf
                @if ($ref)<input type="hidden" name="ref" value="{{ $ref }}">@endif
                <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Email') }}</span>
                    <input class="field" type="email" name="email" value="{{ old('email', $customer->email) }}" required maxlength="190" autocomplete="email" dir="ltr"></label>
                @error('email')<p class="text-sm font-semibold text-red-600" role="alert" data-claim-error>{{ $message }}</p>@enderror
                <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Password') }} <span class="m-muted font-semibold">({{ __('8 characters or more') }})</span></span>
                    <input class="field" type="password" name="password" required minlength="8" maxlength="200" autocomplete="new-password" dir="ltr"></label>
                <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Confirm the password') }}</span>
                    <input class="field" type="password" name="password_confirmation" required minlength="8" maxlength="200" autocomplete="new-password" dir="ltr"></label>
                @error('password')<p class="text-sm font-semibold text-red-600" role="alert">{{ $message }}</p>@enderror
                <button class="btn-primary w-full min-h-12">{{ __('Save and open the menu') }}</button>
            </form>
        </div>
    @else
        @include('menu.partials.account-head', ['title' => __('Your account')])
        <div class="m-surface rounded-3xl p-5 text-center shadow-sm" data-claim-expired>
            <p class="text-4xl" aria-hidden="true">⌛</p>
            <p class="mt-2 font-bold">{{ __('This link was used or expired. Sign in another way, or ask the place for a new link.') }}</p>
            <a href="{{ route('menu.account.sign-in') }}" class="btn-primary mt-4 w-full min-h-12">{{ __('Sign in') }}</a>
        </div>
    @endif
    <a href="{{ route('menu.show') }}" class="btn-ghost mt-5 w-full min-h-12">{{ __('Back to the menu') }}</a>
    @include('menu.partials.powered-by', ['class' => 'mt-6 m-muted'])
</main>
@endsection
