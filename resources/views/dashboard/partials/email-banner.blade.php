{{-- Until the account's email is confirmed: a reminder that can be put away for this session, never a gate (one
     reminder at a time: the WhatsApp one goes first). --}}
@php
    $emailUser = auth()->user();
    $emailPending = $emailUser && filled($emailUser->email) && $emailUser->email_verified_at === null
        && ! \App\Services\WhatsApp\PhoneVerification::pendingFor($emailUser)
        && ! \App\Services\SupportAccess::active(request())
        && ! request()->routeIs('dash.email*', 'dash.phone*', 'dash.security*');
@endphp
@if ($emailPending)
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-900 [&.hidden]:hidden" data-email-banner>
        <span>{{ __('Confirm your email') }} — {{ __('a 6-digit code reaches :email.', ['email' => \App\Services\Auth\EmailCode::mask($emailUser->email)]) }}</span>
        <span class="flex items-center gap-2">
            <a href="{{ route('dash.email') }}" class="btn-primary min-h-9 px-3">{{ __('Confirm now') }}</a>
            <button type="button" class="btn-ghost min-h-9 px-3" data-email-banner-dismiss>{{ __('Later') }}</button>
        </span>
    </div>
    <script>
        (() => {
            const banner = document.querySelector('[data-email-banner]');
            const key = 'eghub.email-banner.' + @js($emailUser->id);
            try { if (sessionStorage.getItem(key) === '1') banner.classList.add('hidden'); } catch (e) {}
            banner.querySelector('[data-email-banner-dismiss]').addEventListener('click', () => {
                banner.classList.add('hidden');
                try { sessionStorage.setItem(key, '1'); } catch (e) {}
            });
        })();
    </script>
@endif
