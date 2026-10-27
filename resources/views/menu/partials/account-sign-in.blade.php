{{--
    The ways into "حسابي" on a phone that does not know the guest yet (the menu's sheet, the /me page, the mobile
    question after Google): the one-tap ways on top, each only when it is set up on the platform (Google keys,
    the EgHub WhatsApp number with its verified webhook), «ادخل بالإيميل» (a link + a code by email), the email +
    password account folded away («ادخل» / «اعمل حساب» / «نسيت الباسورد؟»), then the mobile + a previous order number,
    then the old account link folded away. This partial is part of the cached menu page: nothing in it may depend on the visitor
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
    $showEmail = $showEmail ?? true;
    $emailErrors = $errors->getBag('email');
    $loginErrors = $errors->getBag('login');
    $registerErrors = $errors->getBag('register');
    $tab = $tab ?? null;
    $phoneCode = ($showPhoneCode ?? true) && \App\Services\Customers\SignIn\PhoneCodeSignIn::available();
@endphp
<div class="space-y-4" data-account-sign-in>
    @if ($phoneCode)
        {{-- «ادخل برقم موبايلك» first: a code by the place's own WhatsApp number or SMS, or another way (PhoneCodeController) --}}
        <div class="rounded-2xl border-2 border-[var(--menu-accent)]/30 p-4" data-phone-code data-start-url="{{ route('menu.account.phone-code') }}" data-verify-url="{{ route('menu.account.phone-code.verify') }}">
            <h3 class="flex items-center gap-2 text-lg font-extrabold">📱 {{ __('Sign in with your mobile') }}</h3>
            <p class="mt-1 text-sm m-muted">{{ __('Your orders and saved addresses are on your mobile number.') }}</p>
            <div data-phone-step="ask">
                <label class="mt-3 mb-1 block text-sm font-bold" for="acc-pc-phone-{{ $suffix }}">{{ __('Mobile number') }}</label>
                <input id="acc-pc-phone-{{ $suffix }}" class="field text-lg" maxlength="20" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="01xxxxxxxxx" data-phone-code-input>
                <button type="button" class="btn-primary mt-3 w-full min-h-12" data-phone-code-send>{{ __('Send me a code') }}</button>
            </div>
            <div class="hidden" data-phone-step="code" aria-live="polite">
                <p class="mt-3 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800" data-phone-code-sent></p>
                <label class="mt-3 mb-1 block text-sm font-bold" for="acc-pc-code-{{ $suffix }}">{{ __('The 6-digit code') }}</label>
                <input id="acc-pc-code-{{ $suffix }}" class="field text-center text-2xl font-black tracking-[.4em]" maxlength="6" inputmode="numeric" autocomplete="one-time-code" dir="ltr" placeholder="••••••" data-phone-code-code>
                <button type="button" class="btn-primary mt-3 w-full min-h-12" data-phone-code-verify>{{ __('Sign in') }}</button>
                <div class="mt-2 flex items-center justify-between gap-2 text-sm">
                    <button type="button" class="m-primary-text min-h-11 font-bold underline underline-offset-4 disabled:no-underline disabled:opacity-50" data-phone-code-resend disabled>{{ __('Resend the code') }} <span data-phone-code-timer></span></button>
                    <button type="button" class="m-muted min-h-11 font-semibold underline underline-offset-4" data-phone-code-change>{{ __('Change the number') }}</button>
                </div>
            </div>
            <div class="mt-3 hidden" data-phone-code-other>
                <p class="mb-2 text-sm font-bold" data-phone-code-other-title>{{ __('Send it another way') }}</p>
                <div class="grid gap-2" data-phone-code-alts></div>
            </div>
            <p class="mt-2 hidden text-sm font-semibold text-red-600" role="alert" data-phone-code-fail></p>
        </div>
    @endif
    @if ($phoneCode && ($google || $whatsapp || $showEmail || $showVerify))
        <div class="flex items-center gap-3 text-xs font-bold m-muted" aria-hidden="true"><span class="h-px flex-1 bg-current opacity-20"></span>{{ __('or') }}<span class="h-px flex-1 bg-current opacity-20"></span></div>
    @endif
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
        @if ($showVerify || $showEmail)
            <div class="flex items-center gap-3 text-xs font-bold m-muted" aria-hidden="true"><span class="h-px flex-1 bg-current opacity-20"></span>{{ __('or') }}<span class="h-px flex-1 bg-current opacity-20"></span></div>
        @endif
    @endif

    @if ($showEmail)
        {{-- «ادخل بالإيميل»: a link and a 6-digit code to the email (GuestEmailController::start / code) --}}
        <div class="rounded-2xl m-surface-2 p-4" data-email-sign-in data-start-url="{{ route('menu.account.email') }}" data-code-url="{{ route('menu.account.email.code') }}">
            <h3 class="flex items-center gap-2 font-extrabold">✉️ {{ __('Sign in with email') }}</h3>
            <p class="mt-1 text-sm m-muted">{{ __('We email you a link and a code. No password needed.') }}</p>
            @if ($emailErrors->any())<p class="mt-2 text-sm font-semibold text-red-600" role="alert" data-email-error>{{ $emailErrors->first() }}</p>@endif
            <div data-email-step="ask">
                <label class="mt-3 mb-1 block text-sm font-bold" for="acc-email-{{ $suffix }}">{{ __('Email') }}</label>
                <input id="acc-email-{{ $suffix }}" class="field" type="email" maxlength="190" inputmode="email" autocomplete="email" dir="ltr" placeholder="name@example.com" data-email-input>
                <button type="button" class="btn-primary mt-3 w-full min-h-12" data-email-send>{{ __('Send me a sign-in link') }}</button>
            </div>
            <div class="hidden" data-email-step="code" aria-live="polite">
                <p class="mt-3 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800" data-email-sent></p>
                <label class="mt-3 mb-1 block text-sm font-bold" for="acc-email-code-{{ $suffix }}">{{ __('Or type the 6-digit code from the email') }}</label>
                <input id="acc-email-code-{{ $suffix }}" class="field text-center text-2xl font-black tracking-[.4em]" maxlength="6" inputmode="numeric" autocomplete="one-time-code" dir="ltr" placeholder="••••••" data-email-code>
                <button type="button" class="btn-primary mt-3 w-full min-h-12" data-email-verify>{{ __('Sign in') }}</button>
                <button type="button" class="m-muted mt-2 min-h-11 w-full text-sm font-semibold underline underline-offset-4" data-email-again>{{ __('Use another email') }}</button>
            </div>
            <p class="mt-2 hidden text-sm font-semibold text-red-600" role="alert" data-email-fail></p>
        </div>

        {{-- an account with an email and a password, like the delivery apps: sign in, create one, forgot the password --}}
        <details class="group rounded-2xl m-surface-2 px-4" @if ($tab === 'login' || $tab === 'register' || $loginErrors->any() || $registerErrors->any() || session('ok')) open @endif data-password-box>
            <summary class="flex min-h-13 cursor-pointer list-none items-center justify-between gap-2 font-extrabold">
                <span>🔑 {{ __('Email and password') }}</span>
                <span class="m-muted text-xs font-bold group-open:hidden">{{ __('Sign in or create an account') }}</span>
            </summary>
            <div class="pb-4" data-password-tabs>
                <div class="mb-3 grid grid-cols-2 gap-1 rounded-xl bg-black/5 p-1 text-sm font-bold" role="tablist">
                    <button type="button" role="tab" class="min-h-10 rounded-lg aria-selected:bg-[var(--menu-surface)] aria-selected:shadow-sm" data-pw-tab="login" aria-selected="{{ $tab === 'register' || $registerErrors->any() ? 'false' : 'true' }}">{{ __('Sign in') }}</button>
                    <button type="button" role="tab" class="min-h-10 rounded-lg aria-selected:bg-[var(--menu-surface)] aria-selected:shadow-sm" data-pw-tab="register" aria-selected="{{ $tab === 'register' || $registerErrors->any() ? 'true' : 'false' }}">{{ __('Create an account') }}</button>
                </div>
                <div data-pw-panel="login" @class(['hidden' => $tab === 'register' || $registerErrors->any()])>
                    @if (session('ok'))<p class="mb-2 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800" role="status" data-forgot-sent>{{ session('ok') }}</p>@endif
                    <form method="post" action="{{ route('menu.account.login') }}" data-account-login>
                        @csrf
                        <label class="mb-1 block text-sm font-bold" for="acc-login-email-{{ $suffix }}">{{ __('Email') }}</label>
                        <input id="acc-login-email-{{ $suffix }}" class="field" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email" dir="ltr">
                        <label class="mt-3 mb-1 block text-sm font-bold" for="acc-login-pass-{{ $suffix }}">{{ __('Password') }}</label>
                        <input id="acc-login-pass-{{ $suffix }}" class="field" type="password" name="password" required maxlength="200" autocomplete="current-password" dir="ltr">
                        @if ($loginErrors->any())<p class="mt-2 text-sm font-semibold text-red-600" role="alert" data-login-error>{{ $loginErrors->first() }}</p>@endif
                        <button class="btn-primary mt-3 w-full min-h-12">{{ __('Sign in') }}</button>
                        <button type="submit" formaction="{{ route('menu.account.forgot') }}" formnovalidate class="m-primary-text mt-2 min-h-11 w-full text-sm font-bold underline underline-offset-4" data-forgot>{{ __('Forgot your password?') }}</button>
                    </form>
                </div>
                <div data-pw-panel="register" @class(['hidden' => ! ($tab === 'register' || $registerErrors->any())])>
                    <form method="post" action="{{ route('menu.account.register') }}" class="grid gap-3" data-account-register>
                        @csrf
                        <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Your name') }}</span>
                            <input class="field" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name"></label>
                        <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Mobile number') }}</span>
                            <input class="field" name="phone" value="{{ old('phone') }}" required maxlength="20" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="01xxxxxxxxx"></label>
                        <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Email') }}</span>
                            <input class="field" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email" dir="ltr"></label>
                        <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Password') }} <span class="m-muted font-semibold">({{ __('8 characters or more') }})</span></span>
                            <input class="field" type="password" name="password" required minlength="8" maxlength="200" autocomplete="new-password" dir="ltr"></label>
                        <label class="block"><span class="mb-1 block text-sm font-bold">{{ __('Confirm the password') }}</span>
                            <input class="field" type="password" name="password_confirmation" required minlength="8" maxlength="200" autocomplete="new-password" dir="ltr"></label>
                        @if ($registerErrors->any())<p class="text-sm font-semibold text-red-600" role="alert" data-register-error>{{ $registerErrors->first() }}</p>@endif
                        <button class="btn-primary w-full min-h-12">{{ __('Create my account') }}</button>
                    </form>
                </div>
            </div>
        </details>
        @if ($showVerify)
            <div class="flex items-center gap-3 text-xs font-bold m-muted" aria-hidden="true"><span class="h-px flex-1 bg-current opacity-20"></span>{{ __('or with your mobile') }}<span class="h-px flex-1 bg-current opacity-20"></span></div>
        @endif
    @endif

    @if ($showVerify)
        <form method="post" action="{{ route('menu.account.verify.order') }}" class="rounded-2xl m-surface-2 p-4" data-account-verify>
            @csrf
            <h3 class="flex items-center gap-2 font-extrabold">📱 {{ __('My mobile + a previous order number') }}</h3>
            <p class="mt-1 text-sm m-muted">{{ __('Any order of yours that was delivered or picked up in the last 90 days.') }}</p>
            <ul class="mt-2 grid gap-1 text-xs m-muted" data-order-number-help>
                <li>💬 {{ __('In the WhatsApp message the place sent you about the order') }}</li>
                <li>🧾 {{ __('On the receipt, next to «Order #»') }}</li>
                <li>📍 {{ __('At the top of the order tracking page') }}</li>
            </ul>
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

@if ($phoneCode)
    @once
        @push('scripts')
        <script>
        // «ادخل برقم موبايلك»: a code to the mobile (the place's own WhatsApp number or SMS), resend after a minute,
        // «ابعته بطريقة تانية» lists the other ways (another sender, «I send a code on WhatsApp», a link to my email)
        (function () {
            var oops = @json(__('Something went wrong. Try again.'));
            function headers() {
                var meta = document.querySelector('meta[name="csrf-token"]');
                var h = { Accept: 'application/json', 'Content-Type': 'application/json' };
                if (meta && meta.content) { h['X-CSRF-TOKEN'] = meta.content; }
                return h;
            }
            document.querySelectorAll('[data-phone-code]').forEach(function (box) {
                var input = box.querySelector('[data-phone-code-input]');
                var fail = box.querySelector('[data-phone-code-fail]');
                var resend = box.querySelector('[data-phone-code-resend]');
                var timer = box.querySelector('[data-phone-code-timer]');
                var phone = '';
                var channel = null;
                var tick = null;
                function step(name) { box.querySelectorAll('[data-phone-step]').forEach(function (el) { el.classList.toggle('hidden', el.dataset.phoneStep !== name); }); }
                function error(text) { fail.textContent = text || ''; fail.classList.toggle('hidden', !text); }
                function countdown(seconds) {
                    clearInterval(tick);
                    var left = Math.max(0, seconds | 0);
                    resend.disabled = left > 0;
                    timer.textContent = left > 0 ? '(' + left + ')' : '';
                    tick = setInterval(function () {
                        left -= 1;
                        timer.textContent = left > 0 ? '(' + left + ')' : '';
                        if (left <= 0) { resend.disabled = false; clearInterval(tick); }
                    }, 1000);
                }
                function alternatives(list, title) {
                    var other = box.querySelector('[data-phone-code-other]');
                    var holder = box.querySelector('[data-phone-code-alts]');
                    holder.innerHTML = '';
                    (list || []).forEach(function (alt) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'm-border min-h-11 w-full rounded-xl border px-3 text-sm font-bold';
                        b.textContent = alt.label;
                        b.dataset.phoneCodeAlt = alt.channel;
                        holder.appendChild(b);
                    });
                    if (title) { box.querySelector('[data-phone-code-other-title]').textContent = title; }
                    other.classList.toggle('hidden', !(list || []).length);
                }
                function send(button, wanted) {
                    phone = phone || input.value.trim();
                    if (!phone) { input.focus(); return; }
                    button.disabled = true;
                    error('');
                    fetch(box.dataset.startUrl, { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify({ phone: phone, channel: wanted || null }) })
                        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                        .then(function (res) {
                            var d = res.d;
                            if (!res.ok) { error(d.message || oops); alternatives(d.alternatives); return; }
                            if (d.status === 'sent') {
                                channel = d.channel;
                                box.querySelector('[data-phone-code-sent]').textContent = d.message;
                                step('code');
                                countdown(d.resend_in);
                                alternatives(d.alternatives, @json(__('Send it another way')));
                                box.querySelector('[data-phone-code-code]').focus();
                            } else if (d.status === 'emailed') {
                                step('code');
                                box.querySelector('[data-phone-code-sent]').textContent = d.message;
                                alternatives(d.alternatives, @json(__('Send it another way')));
                            } else {
                                alternatives(d.alternatives, d.message);
                                if (!(d.alternatives || []).length) { error(d.message); }
                            }
                        })
                        .catch(function () { error(oops); })
                        .finally(function () { button.disabled = false; });
                }
                box.querySelector('[data-phone-code-send]').addEventListener('click', function (e) { phone = input.value.trim(); send(e.currentTarget, null); });
                resend.addEventListener('click', function (e) { send(e.currentTarget, channel); });
                box.querySelector('[data-phone-code-change]').addEventListener('click', function () { phone = ''; step('ask'); alternatives([]); error(''); input.focus(); });
                box.addEventListener('click', function (e) {
                    var alt = e.target.closest('[data-phone-code-alt]');
                    if (!alt) { return; }
                    if (alt.dataset.phoneCodeAlt === 'reverse') {
                        // the free way: the guest sends a code to the EgHub WhatsApp number (the box below)
                        var wa = box.parentElement.querySelector('[data-wa-start]');
                        if (wa) { wa.scrollIntoView({ behavior: 'smooth', block: 'center' }); wa.click(); }
                        return;
                    }
                    var below = { order: '[data-account-verify]', email_sign_in: '[data-email-sign-in]' }[alt.dataset.phoneCodeAlt];
                    if (below) {
                        // further down the sheet: the mobile + a previous order number, or the email ways (number filled in)
                        var target = box.parentElement.querySelector(below);
                        if (target) {
                            var tel = target.querySelector('input[name="phone"]');
                            if (tel && !tel.value) { tel.value = phone; }
                            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            var first = target.querySelector('input:not([type=hidden]):not([value])') || target.querySelector('input');
                            if (first) { setTimeout(function () { first.focus(); }, 300); }
                        }
                        return;
                    }
                    send(alt, alt.dataset.phoneCodeAlt);
                });
                box.querySelector('[data-phone-code-verify]').addEventListener('click', function (e) {
                    var code = box.querySelector('[data-phone-code-code]').value.replace(/\s+/g, '');
                    if (!/^[0-9٠-٩]{6}$/.test(code)) { error(@json(__('Type the 6 digits of the code.'))); return; }
                    var button = e.currentTarget;
                    button.disabled = true;
                    fetch(box.dataset.verifyUrl, { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify({ phone: phone, code: code }) })
                        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                        .then(function (res) { if (!res.ok) { error(res.d.message || oops); return; } location.href = res.d.redirect; })
                        .catch(function () { error(oops); })
                        .finally(function () { button.disabled = false; });
                });
            });
        })();
        </script>
        @endpush
    @endonce
@endif

@if ($showEmail)
    @once
        @push('scripts')
        <script>
        // «ادخل بالإيميل»: ask for the link + code, then the code can be typed here (GuestEmailController); the email +
        // password box switches between «ادخل» and «اعمل حساب»
        (function () {
            function headers() {
                var meta = document.querySelector('meta[name="csrf-token"]');
                var h = { Accept: 'application/json', 'Content-Type': 'application/json' };
                if (meta && meta.content) { h['X-CSRF-TOKEN'] = meta.content; }
                return h;
            }
            var oops = @json(__('Something went wrong. Try again.'));
            document.querySelectorAll('[data-email-sign-in]').forEach(function (box) {
                var input = box.querySelector('[data-email-input]');
                var fail = box.querySelector('[data-email-fail]');
                var email = '';
                function step(name) { box.querySelectorAll('[data-email-step]').forEach(function (el) { el.classList.toggle('hidden', el.dataset.emailStep !== name); }); }
                function error(text) { fail.textContent = text || ''; fail.classList.toggle('hidden', !text); }
                function post(url, body, button) {
                    button.disabled = true;
                    error('');
                    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify(body) })
                        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                        .finally(function () { button.disabled = false; });
                }
                box.querySelector('[data-email-send]').addEventListener('click', function (e) {
                    email = input.value.trim();
                    if (!email || !input.checkValidity()) { input.reportValidity(); return; }
                    post(box.dataset.startUrl, { email: email }, e.currentTarget).then(function (res) {
                        if (!res.ok) { error(res.d.message || oops); return; }
                        box.querySelector('[data-email-sent]').textContent = res.d.message;
                        step('code');
                        box.querySelector('[data-email-code]').focus();
                    }).catch(function () { error(oops); });
                });
                box.querySelector('[data-email-verify]').addEventListener('click', function (e) {
                    var code = box.querySelector('[data-email-code]').value.replace(/\s+/g, '');
                    if (!/^[0-9٠-٩]{6}$/.test(code)) { error(@json(__('Type the 6 digits from the email.'))); return; }
                    post(box.dataset.codeUrl, { email: email, code: code }, e.currentTarget).then(function (res) {
                        if (!res.ok) { error(res.d.message || oops); return; }
                        location.href = res.d.redirect;
                    }).catch(function () { error(oops); });
                });
                box.querySelector('[data-email-again]').addEventListener('click', function () { step('ask'); error(''); input.focus(); });
            });
            document.querySelectorAll('[data-password-tabs]').forEach(function (tabs) {
                tabs.querySelectorAll('[data-pw-tab]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        tabs.querySelectorAll('[data-pw-tab]').forEach(function (b) { b.setAttribute('aria-selected', b === button ? 'true' : 'false'); });
                        tabs.querySelectorAll('[data-pw-panel]').forEach(function (p) { p.classList.toggle('hidden', p.dataset.pwPanel !== button.dataset.pwTab); });
                    });
                });
            });
        })();
        </script>
        @endpush
    @endonce
@endif

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
