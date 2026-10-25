@extends('layouts.base')
@section('title', __('Create your free account') . ' · EgHub')
@section('share_card', 'register')
@section('body_class', 'bg-gradient-to-b from-navy-50 via-white to-navy-50 text-navy-900')

@push('head')
    <meta name="description" content="{{ __('Your QR menu is free, forever. Add ordering, loyalty and the rest when you need them.') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="EgHub">
    <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_EG' : 'en_US' }}">
    <meta property="og:title" content="@yield('title')">
    <meta property="og:description" content="{{ __('Your QR menu is free, forever. Add ordering, loyalty and the rest when you need them.') }}">
    <meta property="og:url" content="{{ route('register') }}">
    @include('landing.partials.share-image')
@endpush

@section('body')
<div class="mx-auto max-w-xl px-4 py-10">
    <a href="{{ route('landing') }}" class="mb-8 flex justify-center" aria-label="EgHub"><x-logo :size="44" /></a>
    <div class="card rounded-3xl p-6 shadow-xl shadow-navy-900/5 sm:p-8">
        <span class="badge bg-emerald-50 text-emerald-800">{{ __('Free plan · no card') }}</span>
        <h1 class="mt-2 text-2xl font-extrabold sm:text-3xl">{{ __('Create your free account') }}</h1>
        <p class="mb-6 text-sm text-navy-500">{{ __('Your account starts on the free plan and stays free as long as you like. On top, you get :days days of the Complete plan to try ordering, the loyalty card and the rest. No card needed.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</p>
        <x-flash />
        @php($social = $social ?? null)
        @if ($social)
            @php($providerName = \App\Services\Auth\SocialProviders::label($social['provider']))
            <div class="mb-5 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" data-social-signup="{{ $social['provider'] }}">
                <span>{{ __('You are signing up with your :provider account. Just add your restaurant details.', ['provider' => $providerName]) }}</span>
                <a href="{{ route('register', ['social' => 'cancel']) }}" class="font-semibold text-emerald-800 underline">{{ __('Use another email') }}</a>
            </div>
        @else
            <x-social-buttons />
        @endif
        <form method="post" action="{{ route('register') }}" class="grid gap-4 sm:grid-cols-2" data-signup>
            @csrf
            <div class="sm:col-span-2">
                <label class="field-label" for="name">{{ __('Restaurant / cafe name') }}</label>
                <input class="field" id="name" name="name" value="{{ old('name') }}" required maxlength="80">
            </div>
            <div class="sm:col-span-2">
                <label class="field-label" for="slug">{{ __('Your menu link') }}</label>
                <div class="flex items-center gap-1" dir="ltr">
                    <span class="shrink-0 text-sm text-navy-500">{{ central_domain() }}/</span>
                    <input class="field" id="slug" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9\-]{3,30}" maxlength="30" placeholder="my-cafe" autocomplete="off">
                </div>
                <p class="mt-1 text-xs" data-slug-hint>{{ __('3-30 lowercase English letters, numbers or hyphens.') }}</p>
            </div>
            <div>
                <label class="field-label" for="owner_name">{{ __('Your name') }}</label>
                <input class="field" id="owner_name" name="owner_name" value="{{ old('owner_name', $social['name'] ?? '') }}" required maxlength="80">
            </div>
            <div>
                <label class="field-label" for="phone">{{ __('Mobile') }}</label>
                <input class="field" id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx" autocomplete="tel">
                <p class="mt-1 text-xs text-navy-500">{{ __('Confirmed on WhatsApp with one ready message — no codes to type.') }}</p>
            </div>
            <div class="sm:col-span-2">
                <label class="field-label" for="email">{{ __('Email') }}</label>
                @if ($social)
                    <input class="field bg-navy-50 text-navy-600" id="email" name="email" type="email" value="{{ $social['email'] }}" readonly aria-readonly="true" dir="ltr">
                    <p class="mt-1 text-xs text-navy-500">{{ __('No password needed: you will sign in with :provider.', ['provider' => $providerName]) }}</p>
                @else
                    <input class="field" id="email" name="email" type="email" value="{{ old('email') }}" required dir="ltr">
                @endif
            </div>
            @unless ($social)
                <div>
                    <label class="field-label" for="password">{{ __('Password') }}</label>
                    <input class="field" id="password" name="password" type="password" required minlength="8" autocomplete="new-password" dir="ltr">
                </div>
                <div>
                    <label class="field-label" for="password_confirmation">{{ __('Confirm password') }}</label>
                    <input class="field" id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" dir="ltr">
                </div>
            @endunless
            <fieldset class="sm:col-span-2">
                <legend class="field-label">{{ __('Type of place') }}</legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach (\App\Support\BusinessType::TYPES as $key => $label)
                        <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-navy-100 px-2 text-center text-sm font-semibold has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="business_type" value="{{ $key }}" required class="sr-only" @checked(old('business_type', 'cafe') === $key)>
                            {{ \App\Support\BusinessType::emoji($key) }} {{ app()->getLocale() === 'en' ? $label[1] : $label[0] }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @include('landing.partials.signup-menu-setup')
            <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="terms" value="1" required class="mt-1 size-4 accent-brand-600"> {{ __('I agree to the EgHub terms of service.') }}</label>
            <button class="btn-primary min-h-12 text-base shadow-lg shadow-brand-600/25 sm:col-span-2">{{ __('Create my menu') }}</button>
        </form>
    </div>
    <ul class="mt-5 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm text-navy-600">
        @foreach ([__('No card'), __('No commission'), __('Confirm by WhatsApp, no codes'), __('Pay by InstaPay or Vodafone Cash')] as $point)
            <li class="flex items-center gap-1.5"><x-landing.icon name="check" class="size-4 text-emerald-600" />{{ $point }}</li>
        @endforeach
    </ul>
    <p class="mt-4 text-center text-sm text-navy-500">{{ __('Already have an account?') }} <a class="font-semibold text-brand-700" href="{{ platform_url('app', '/login') }}">{{ __('Sign in') }}</a></p>
</div>

<script>
    // Suggest a link from the name and check it live.
    (() => {
        const form = document.querySelector('[data-signup]');
        const slug = form.querySelector('#slug');
        const hint = form.querySelector('[data-slug-hint]');
        let touched = slug.value !== '';
        let timer;
        slug.addEventListener('input', () => { touched = true; check(); });
        form.querySelector('#name').addEventListener('input', (e) => {
            if (touched) return;
            slug.value = e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30);
            check();
        });
        function check() {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                if (slug.value.length < 3) return;
                const res = await fetch(@js(route('register.check')) + '?slug=' + encodeURIComponent(slug.value));
                const data = await res.json();
                hint.textContent = data.ok ? data.url : data.message;
                hint.className = 'mt-1 text-xs ' + (data.ok ? 'text-emerald-700' : 'text-red-600');
            }, 350);
        }
    })();
</script>
@endsection

@push('scripts')
    @include('partials.platform-pixel')
@endpush
