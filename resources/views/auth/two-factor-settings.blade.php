{{--
    Two-factor section of the security page (dashboard and platform admin).
    $user, $pending (secret issued, not confirmed), $secret, $qr (SVG), $recoveryCodes (flashed once), $routes (prefix), $required
--}}
<div class="grid max-w-5xl gap-4 lg:grid-cols-5" data-two-factor>
    <section class="card lg:col-span-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="section-title">{{ __('Two-factor authentication') }}</h2>
                <p class="mt-1 text-sm text-navy-500">{{ __('After your password, a 6-digit code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy) is asked for.') }}</p>
            </div>
            @if ($user->hasTwoFactorEnabled())
                <span class="badge bg-emerald-100 text-emerald-800" data-two-factor-state="on">✓ {{ __('Turned on') }}</span>
            @else
                <span class="badge bg-navy-100 text-navy-600" data-two-factor-state="off">{{ __('Turned off') }}</span>
            @endif
        </div>

        @if ($required)
            <div class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800" data-two-factor-required>
                {{ __('Your restaurant requires two-factor authentication. Set it up to continue.') }}
            </div>
        @endif

        @if ($recoveryCodes)
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4" data-recovery-codes>
                <b>{{ __('Your recovery codes') }}</b>
                <p class="mt-1 text-sm text-emerald-900">{{ __('Each code signs you in once when your phone is not at hand. They are shown only now: save or print them.') }}</p>
                <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm" dir="ltr">
                    @foreach ($recoveryCodes as $code)
                        <li class="rounded bg-white px-2 py-1">{{ $code }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-ghost mt-3 min-h-9 px-3" data-copy="{{ implode("\n", $recoveryCodes) }}">{{ __('Copy') }}</button>
            </div>
        @endif

        @if ($user->hasTwoFactorEnabled())
            <p class="mt-4 text-sm text-navy-700">
                {{ __('Enabled on :date.', ['date' => local_time($user->two_factor_confirmed_at, 'Y/m/d')]) }}
                · {{ __(':n recovery codes left', ['n' => $user->twoFactorRecoveryCodesLeft()]) }}
            </p>
            <form method="post" action="{{ route($routes . 'security.disable') }}" class="mt-4 flex flex-wrap items-end gap-3 rounded-xl bg-navy-50 p-4">
                @csrf
                <div class="min-w-48 flex-1">
                    <label class="field-label" for="password">{{ __('Current password') }}</label>
                    <input class="field" id="password" name="password" type="password" required autocomplete="current-password" dir="ltr">
                </div>
                <button class="btn-danger">{{ __('Turn off') }}</button>
            </form>
        @elseif ($pending)
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-navy-100 bg-white p-3 text-center">
                    <div class="mx-auto w-52 [&_svg]:h-auto [&_svg]:w-full" data-two-factor-qr>{!! $qr !!}</div>
                    <div class="mt-2 text-xs text-navy-500">{{ __('Cannot scan? Type this key in the app:') }}</div>
                    <code class="break-all text-xs" dir="ltr">{{ $secret }}</code>
                </div>
                <form method="post" action="{{ route($routes . 'security.confirm') }}" class="space-y-3">
                    @csrf
                    <ol class="list-inside list-decimal space-y-1 text-sm text-navy-700">
                        <li>{{ __('Install an authenticator app on your phone.') }}</li>
                        <li>{{ __('Scan the QR code, or type the key.') }}</li>
                        <li>{{ __('Enter the 6 digits the app shows.') }}</li>
                    </ol>
                    <div>
                        <label class="field-label" for="code">{{ __('Code from the app') }}</label>
                        <input class="field text-center text-xl tracking-widest" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="12">
                    </div>
                    <button class="btn-primary w-full">{{ __('Confirm and turn on') }}</button>
                </form>
            </div>
            <form method="post" action="{{ route($routes . 'security.enable') }}" class="mt-3 text-end">
                @csrf
                <button class="text-sm text-navy-500 underline">{{ __('Start over with a new key') }}</button>
            </form>
        @else
            <form method="post" action="{{ route($routes . 'security.enable') }}" class="mt-4">
                @csrf
                <button class="btn-primary">{{ __('Turn on two-factor authentication') }}</button>
            </form>
        @endif
    </section>

    <aside class="card space-y-2 text-sm text-navy-700 lg:col-span-2">
        <h2 class="section-title">{{ __('Why turn it on?') }}</h2>
        <p>{{ __('Even if someone learns your password, they cannot sign in without the code on your phone.') }}</p>
        <p>{{ __('Lost your phone? Sign in with a recovery code, then set it up again on the new phone.') }}</p>
        <p>{{ __('Tick "Remember this device" on a computer you trust and the code is asked for once a month there.') }}</p>
    </aside>
</div>
<script>
    document.querySelectorAll('[data-two-factor] [data-copy]').forEach((b) => b.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = @js(__('Copied')); } catch (e) { prompt('', b.dataset.copy); }
    }));
</script>
