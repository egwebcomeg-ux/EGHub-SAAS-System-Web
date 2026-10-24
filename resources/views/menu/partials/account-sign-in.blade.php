{{--
    The two ways into "حسابي" on a phone that does not know the guest yet (the menu's sheet and the /me page):
    the account link itself, or the mobile + the number of a previous delivered order. Errors come back in their own
    bags so each form shows its own message; the answer never says whether a mobile is a guest here.
--}}
@php
    $linkErrors = $errors->getBag('link');
    $verifyErrors = $errors->getBag('verify');
@endphp
<div class="space-y-4" data-account-sign-in>
    <form method="get" action="{{ route('menu.account.open') }}" class="rounded-2xl m-surface-2 p-4" data-account-open-link>
        <h3 class="flex items-center gap-2 font-extrabold">🔗 {{ __('I have my account link') }}</h3>
        <p class="mt-1 text-sm m-muted">{{ __('Paste the link you saved or sent to yourself on WhatsApp.') }}</p>
        <label class="sr-only" for="acc-link-{{ $idSuffix ?? 'p' }}">{{ __('Account link') }}</label>
        <input id="acc-link-{{ $idSuffix ?? 'p' }}" class="field mt-3" name="link" required maxlength="500" dir="ltr" inputmode="url" autocomplete="off" placeholder="https://…/me/…">
        @if ($linkErrors->any())<p class="mt-2 text-sm font-semibold text-red-600" role="alert" data-link-error>{{ $linkErrors->first() }}</p>@endif
        <button class="btn-primary mt-3 w-full min-h-12">{{ __('Open my account') }}</button>
    </form>

    <form method="post" action="{{ route('menu.account.verify.order') }}" class="rounded-2xl m-surface-2 p-4" data-account-verify>
        @csrf
        <h3 class="flex items-center gap-2 font-extrabold">📱 {{ __('My mobile + a previous order number') }}</h3>
        <p class="mt-1 text-sm m-muted">{{ __('The number of any order of yours that was delivered or picked up in the last 90 days (on the receipt or the order page).') }}</p>
        <label class="mt-3 mb-1 block text-sm font-bold" for="acc-phone-{{ $idSuffix ?? 'p' }}">{{ __('Mobile number') }}</label>
        <input id="acc-phone-{{ $idSuffix ?? 'p' }}" class="field" name="phone" value="{{ old('phone') }}" required maxlength="20" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="01xxxxxxxxx">
        <label class="mt-3 mb-1 block text-sm font-bold" for="acc-number-{{ $idSuffix ?? 'p' }}">{{ __('Order number') }}</label>
        <input id="acc-number-{{ $idSuffix ?? 'p' }}" class="field" name="number" required maxlength="12" inputmode="numeric" autocomplete="off" dir="ltr" placeholder="#1234">
        @if ($verifyErrors->any())<p class="mt-2 text-sm font-semibold text-red-600" role="alert" data-verify-error>{{ $verifyErrors->first() }}</p>@endif
        <button class="btn-primary mt-3 w-full min-h-12">{{ __('Open my account') }}</button>
    </form>
    <p class="text-center text-xs m-muted">{{ __('Keep your account link to yourself: whoever has it can see your orders.') }}</p>
</div>
