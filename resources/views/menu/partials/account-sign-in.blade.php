{{--
    The ways into "حسابي" on a phone that does not know the guest yet (the menu's sheet, the /me page, the mobile
    question after Google): the one-tap ways on top, each only when it is set up on the platform (Google keys,
    the EgHub WhatsApp number with its verified webhook), then the mobile + a previous order number, then the old
    account link folded away. This partial is part of the cached menu page: nothing in it may depend on the visitor
    (errors only come back to the uncached /me page). Another provider (Apple) is one more button in the first block
    once App\Services\Customers\SignIn\GuestOAuth::PROVIDERS has it.
--}}
@php
    $linkErrors = $errors->getBag('link');
    $verifyErrors = $errors->getBag('verify');
    $googleErrors = $errors->getBag('google');
    $suffix = $idSuffix ?? 'p';
    $google = ! ($hideGoogle ?? false) && \App\Services\Customers\SignIn\GuestOAuth::enabled('google');
    $whatsapp = \App\Services\Customers\SignIn\WhatsAppSignIn::available();
    $showVerify = $showVerify ?? true;
@endphp
<div class="space-y-4" data-account-sign-in>
    @if ($google || $whatsapp)
        <div class="grid gap-3" data-sign-in-quick>
            @if ($googleErrors->any())<p class="rounded-2xl bg-red-50 p-3 text-sm font-semibold text-red-700" role="alert" data-google-error>{{ $googleErrors->first() }}</p>@endif
            @if ($google)
                <a href="{{ route('menu.account.google') }}" rel="nofollow" class="flex min-h-13 w-full items-center justify-center gap-3 rounded-2xl border border-black/10 bg-white px-4 text-base font-bold text-[#1f1f1f] shadow-sm transition active:scale-[.98] dark:border-white/15" data-sign-in-google>
                    <svg class="size-5 shrink-0" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                    {{ __('Sign in with Google') }}
                </a>
            @endif
            @if ($whatsapp)
                <div data-wa-sign-in data-start-url="{{ route('menu.account.whatsapp') }}" data-status-url="{{ route('menu.account.whatsapp.status') }}">
                    <button type="button" class="flex min-h-13 w-full items-center justify-center gap-3 rounded-2xl bg-[#25D366] px-4 text-base font-bold text-[#0b3d1f] shadow-sm transition active:scale-[.98] disabled:opacity-60" data-wa-start>
                        <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.7.3-.2.3-.9.9-.9 2.2s1 2.6 1.1 2.7c.1.2 1.9 2.9 4.6 4 1.7.7 2.4.8 3.2.7.5-.1 1.5-.6 1.8-1.2.2-.6.2-1.1.1-1.2l-.4-.3Z"/></svg>
                        <span data-wa-start-label>{{ __('Sign in with WhatsApp') }}</span>
                    </button>
                    <div class="mt-3 hidden rounded-2xl border-2 border-dashed border-[#25D366]/60 p-4 text-center" data-wa-panel aria-live="polite">
                        <p class="text-sm m-muted">{{ __('Tap the button: WhatsApp opens with your code in a ready message. Send it as it is, then come back here.') }}</p>
                        <p class="mt-2 text-xs font-bold m-muted">{{ __('Your code') }}</p>
                        <p class="text-3xl font-black tracking-[.3em]" dir="ltr" data-wa-code></p>
                        <a href="#" target="_blank" rel="noopener" class="mt-3 flex min-h-12 w-full items-center justify-center rounded-2xl bg-[#25D366] px-4 font-bold text-[#0b3d1f]" data-wa-open>{{ __('Send the message on WhatsApp') }}</a>
                        <p class="mt-3 flex items-center justify-center gap-2 text-sm font-semibold" data-wa-state="pending">
                            <span class="inline-block size-2.5 animate-pulse rounded-full bg-[#25D366]" aria-hidden="true"></span>{{ __('Waiting for your message…') }}
                        </p>
                        <p class="mt-3 hidden text-sm font-semibold text-emerald-700" data-wa-state="ok">{{ __('Signed in. Opening your account…') }}</p>
                        <p class="mt-3 hidden text-sm font-semibold text-red-600" data-wa-state="expired">{{ __('The code expired. Ask for a new one.') }}</p>
                        <p class="mt-3 hidden text-sm font-semibold text-red-600" role="alert" data-wa-state="error"></p>
                    </div>
                </div>
            @endif
        </div>
        @if ($showVerify)
            <div class="flex items-center gap-3 text-xs font-bold m-muted" aria-hidden="true"><span class="h-px flex-1 bg-current opacity-20"></span>{{ __('or') }}<span class="h-px flex-1 bg-current opacity-20"></span></div>
        @endif
    @endif

    @if ($showVerify)
        <form method="post" action="{{ route('menu.account.verify.order') }}" class="rounded-2xl m-surface-2 p-4" data-account-verify>
            @csrf
            <h3 class="flex items-center gap-2 font-extrabold">📱 {{ __('My mobile + a previous order number') }}</h3>
            <p class="mt-1 text-sm m-muted">{{ __('The number of any order of yours that was delivered or picked up in the last 90 days (on the receipt or the order page).') }}</p>
            <label class="mt-3 mb-1 block text-sm font-bold" for="acc-phone-{{ $suffix }}">{{ __('Mobile number') }}</label>
            <input id="acc-phone-{{ $suffix }}" class="field" name="phone" value="{{ old('phone') }}" required maxlength="20" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="01xxxxxxxxx">
            <label class="mt-3 mb-1 block text-sm font-bold" for="acc-number-{{ $suffix }}">{{ __('Order number') }}</label>
            <input id="acc-number-{{ $suffix }}" class="field" name="number" required maxlength="12" inputmode="numeric" autocomplete="off" dir="ltr" placeholder="#1234">
            @if ($verifyErrors->any())<p class="mt-2 text-sm font-semibold text-red-600" role="alert" data-verify-error>{{ $verifyErrors->first() }}</p>@endif
            <button class="btn-primary mt-3 w-full min-h-12">{{ __('Open my account') }}</button>
        </form>

        {{-- the saved account link (/me/{token}) still opens when tapped from WhatsApp; pasting it is folded away here --}}
        <details class="group text-center" @if ($linkErrors->any()) open @endif data-account-open-link>
            <summary class="m-muted inline-flex min-h-11 cursor-pointer list-none items-center gap-1 text-sm font-semibold underline underline-offset-4">🔗 {{ __('I have my account link') }}</summary>
            <form method="get" action="{{ route('menu.account.open') }}" class="mt-2 rounded-2xl m-surface-2 p-4 text-start">
                <label class="mb-1 block text-sm font-bold" for="acc-link-{{ $suffix }}">{{ __('Paste the link you saved or sent to yourself on WhatsApp.') }}</label>
                <input id="acc-link-{{ $suffix }}" class="field" name="link" required maxlength="500" dir="ltr" inputmode="url" autocomplete="off" placeholder="https://…/me/…">
                @if ($linkErrors->any())<p class="mt-2 text-sm font-semibold text-red-600" role="alert" data-link-error>{{ $linkErrors->first() }}</p>@endif
                <button class="btn-ghost mt-3 w-full min-h-12">{{ __('Open my account') }}</button>
            </form>
        </details>
    @endif
</div>

@if ($whatsapp)
    @once
        @push('scripts')
        <script>
        // «ادخل بواتساب»: ask for a code, show it with the wa.me link, then ask every few seconds whether the platform's
        // webhook saw the message (App\Http\Controllers\Menu\GuestSignInController)
        (function () {
            document.querySelectorAll('[data-wa-sign-in]').forEach(function (box) {
                var start = box.querySelector('[data-wa-start]');
                var panel = box.querySelector('[data-wa-panel]');
                var timer = null;
                var expiresAt = 0;
                function show(state, text) {
                    panel.querySelectorAll('[data-wa-state]').forEach(function (el) { el.classList.toggle('hidden', el.dataset.waState !== state); });
                    if (text) { panel.querySelector('[data-wa-state="' + state + '"]').textContent = text; }
                }
                function expired() {
                    show('expired');
                    box.querySelector('[data-wa-start-label]').textContent = @json(__('New code'));
                    start.disabled = false;
                }
                function stop() { if (timer) { clearTimeout(timer); timer = null; } }
                function poll() {
                    stop();
                    fetch(box.dataset.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.status === 'ok') { show('ok'); location.href = d.redirect; return; }
                            if (d.status !== 'pending' || Date.now() > expiresAt) { expired(); return; }
                            timer = setTimeout(poll, 3000);
                        })
                        .catch(function () { timer = setTimeout(poll, 5000); });
                }
                start.addEventListener('click', function () {
                    start.disabled = true;
                    stop();
                    var meta = document.querySelector('meta[name="csrf-token"]');
                    var headers = { Accept: 'application/json' };
                    if (meta && meta.content) { headers['X-CSRF-TOKEN'] = meta.content; }
                    fetch(box.dataset.startUrl, { method: 'POST', credentials: 'same-origin', headers: headers })
                        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                        .then(function (res) {
                            panel.classList.remove('hidden');
                            if (!res.ok) { show('error', res.d.message || @json(__('Something went wrong. Try again.'))); start.disabled = false; return; }
                            panel.querySelector('[data-wa-code]').textContent = res.d.code;
                            panel.querySelector('[data-wa-open]').href = res.d.url;
                            expiresAt = Date.now() + res.d.expires_in * 1000;
                            show('pending');
                            timer = setTimeout(poll, 3000);
                        })
                        .catch(function () { panel.classList.remove('hidden'); show('error', @json(__('Something went wrong. Try again.'))); start.disabled = false; });
                });
                document.addEventListener('visibilitychange', function () { if (!document.hidden && timer) { poll(); } });
            });
        })();
        </script>
        @endpush
    @endonce
@endif
