{{-- Until the account's mobile is confirmed on WhatsApp: a reminder that can be put away for this session, never a gate. --}}
@php
    $phonePending = \App\Services\WhatsApp\PhoneVerification::pendingFor(auth()->user()) && ! request()->routeIs('dash.phone*');
@endphp
@if ($phonePending)
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 [&.hidden]:hidden" data-phone-banner>
        <span>{{ __('Confirm your number on WhatsApp') }} — {{ __('one ready message, no codes to type.') }}</span>
        <span class="flex items-center gap-2">
            <a href="{{ route('dash.phone') }}" class="btn-primary min-h-9 px-3">{{ __('Confirm now') }}</a>
            <button type="button" class="btn-ghost min-h-9 px-3" data-phone-banner-dismiss>{{ __('Later') }}</button>
        </span>
    </div>
    <script>
        (() => {
            const banner = document.querySelector('[data-phone-banner]');
            const key = 'egmenu.phone-banner.' + @js(auth()->id());
            try { if (sessionStorage.getItem(key) === '1') banner.classList.add('hidden'); } catch (e) {}
            banner.querySelector('[data-phone-banner-dismiss]').addEventListener('click', () => {
                banner.classList.add('hidden');
                try { sessionStorage.setItem(key, '1'); } catch (e) {}
            });
        })();
    </script>
@endif
