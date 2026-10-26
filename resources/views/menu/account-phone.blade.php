@extends('layouts.base')
@php
    $brand = tr($settings['brand_name'], $settings['brand_name_en']) ?: tenant()->name;
    $taken = $errors->has('phone') && old('phone') && normalize_phone((string) old('phone')) !== null;
@endphp
@section('title', __('My account') . ' | ' . $brand)
@section('body_class', 'm-surface-2 m-text')

@push('head')
@include('menu.partials.theme')
@endpush

@section('body')
{{-- asked once after «الدخول بجوجل» when the guest has no mobile here (App\Http\Controllers\Menu\GuestSignInController) --}}
<main class="mx-auto max-w-md px-4 pb-10 pt-6" data-account-phone-page>
    <header class="mb-5 flex items-center gap-3">
        @if ($settings['logo'])<img src="/storage/{{ $settings['logo'] }}" alt="" class="size-12 shrink-0 rounded-2xl bg-white object-contain p-1 shadow-sm">@endif
        <div class="min-w-0">
            <div class="truncate text-sm font-bold m-muted">{{ $brand }}</div>
            <h1 class="text-2xl font-black">{{ $customer->name !== '' ? __('Welcome, :name', ['name' => $customer->name]) : __('Welcome') }}</h1>
        </div>
    </header>

    <form method="post" action="{{ route('menu.account.phone.save') }}" class="m-surface rounded-3xl p-5 shadow-sm" data-account-phone>
        @csrf
        <h2 class="text-lg font-extrabold">{{ __('One last thing: your mobile') }}</h2>
        <p class="mt-1 text-sm m-muted">{{ __('The place needs it for your orders and to reach you about them.') }}</p>
        <label class="mt-4 mb-1 block text-sm font-bold" for="acc-phone-new">{{ __('Mobile number') }}</label>
        <input id="acc-phone-new" class="field" name="phone" value="{{ old('phone') }}" required maxlength="20" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="01xxxxxxxxx">
        @error('phone')<p class="mt-2 text-sm font-semibold text-red-600" role="alert" data-phone-error>{{ $message }}</p>@enderror
        <button class="btn-primary mt-4 w-full min-h-12">{{ __('Save') }}</button>
        <a href="{{ route('menu.show') }}" class="btn-ghost mt-2 w-full min-h-12" data-phone-later>{{ __('Later') }}</a>
    </form>

    @if ($taken)
        {{-- the mobile is another guest's here: proving it links this Google sign-in to that account --}}
        <div class="mt-5" data-phone-prove>
            @include('menu.partials.account-sign-in', ['idSuffix' => 'prove', 'hideGoogle' => true])
        </div>
    @endif
    @include('menu.partials.powered-by', ['class' => 'mt-6 m-muted'])
</main>
@endsection
