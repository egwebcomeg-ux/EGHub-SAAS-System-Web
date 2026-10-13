@extends('layouts.base')
@section('title', __('Start your free trial') . ' · EgMenu')
@section('body_class', 'bg-navy-50 text-navy-900')

@section('body')
<div class="mx-auto max-w-xl px-4 py-10">
    <a href="{{ route('landing') }}" class="mb-6 flex justify-center"><x-logo :size="40" /></a>
    <div class="card">
        <h1 class="text-2xl font-extrabold">{{ __('Start your free trial') }}</h1>
        <p class="mb-5 text-sm text-navy-500">{{ __(':days days of the Pro plan, no card needed.', ['days' => \App\Services\TenantProvisioner::TRIAL_DAYS]) }}</p>
        <x-flash />
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
                    <input class="field" id="slug" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9-]{3,30}" maxlength="30" placeholder="my-cafe" autocomplete="off">
                </div>
                <p class="mt-1 text-xs" data-slug-hint>{{ __('3-30 lowercase English letters, numbers or hyphens.') }}</p>
            </div>
            <div>
                <label class="field-label" for="owner_name">{{ __('Your name') }}</label>
                <input class="field" id="owner_name" name="owner_name" value="{{ old('owner_name') }}" required maxlength="80">
            </div>
            <div>
                <label class="field-label" for="phone">{{ __('Mobile') }}</label>
                <input class="field" id="phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" dir="ltr" placeholder="01xxxxxxxxx">
            </div>
            <div class="sm:col-span-2">
                <label class="field-label" for="email">{{ __('Email') }}</label>
                <input class="field" id="email" name="email" type="email" value="{{ old('email') }}" required dir="ltr">
            </div>
            <div>
                <label class="field-label" for="password">{{ __('Password') }}</label>
                <input class="field" id="password" name="password" type="password" required minlength="8" autocomplete="new-password" dir="ltr">
            </div>
            <div>
                <label class="field-label" for="password_confirmation">{{ __('Confirm password') }}</label>
                <input class="field" id="password_confirmation" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" dir="ltr">
            </div>
            <label class="flex items-start gap-2 text-sm sm:col-span-2"><input type="checkbox" name="terms" value="1" required class="mt-1 size-4 accent-brand-600"> {{ __('I agree to the EgMenu terms of service.') }}</label>
            <button class="btn-primary sm:col-span-2">{{ __('Create my menu') }}</button>
        </form>
    </div>
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
