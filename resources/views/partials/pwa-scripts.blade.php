{{--
    Service worker registration and, on a restaurant menu only, the gentle "add to your home screen" prompt:
    never on the first day a guest visits, then at most once every 14 days (localStorage), never once installed.
    Android/Chrome: the beforeinstallprompt event; iPhone: a one-line hint with the share icon.
--}}
@if ($pwa)
    @if ($pwa['install'])
        <div id="pwa-install-prompt" class="fixed inset-x-3 bottom-24 z-40 hidden rounded-2xl bg-white p-3 text-navy-900 shadow-xl ring-1 ring-black/10 sm:inset-x-auto sm:end-4 sm:w-80" data-pwa-banner role="dialog" aria-label="{{ __('Add the menu to your home screen') }}">
            <div class="flex items-start gap-3">
                <img src="{{ $pwa['icon'] }}" alt="" class="size-10 shrink-0 rounded-xl">
                <div class="min-w-0 flex-1 text-sm">
                    <b class="block">{{ __('Add the menu to your home screen') }}</b>
                    <p class="mt-0.5 text-navy-500" data-pwa-text-android>{{ __('Opens like an app, even with a weak signal.') }}</p>
                    <p class="mt-0.5 hidden text-navy-500" data-pwa-text-ios>
                        {{ __('Tap the share button') }}
                        <svg class="inline size-4 align-text-bottom" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>
                        {{ __('then "Add to Home Screen".') }}
                    </p>
                </div>
                <button type="button" class="-m-1 p-1 text-xl leading-none text-navy-500" data-pwa-dismiss aria-label="{{ __('Close') }}">×</button>
            </div>
            <button type="button" class="mt-3 hidden min-h-10 w-full rounded-xl text-sm font-bold text-white" style="background: var(--menu-primary, #F26522)" data-pwa-install>{{ __('Add') }}</button>
        </div>
    @endif
    <script>
        (() => {
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => navigator.serviceWorker.register(@json($pwa['sw'], JSON_UNESCAPED_SLASHES), { scope: @json($pwa['scope'], JSON_UNESCAPED_SLASHES) }).catch(() => {}));
            }
            const banner = document.querySelector('[data-pwa-banner]');
            if (!banner) return;
            const KEY = 'egmenu.pwa.' + @js($pwa['key']);
            const DAY = 86400000;
            let state = {};
            try { state = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
            const save = () => { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} };
            const today = new Date().toDateString();
            if (state.day !== today) { state.day = today; state.visits = (state.visits || 0) + 1; save(); }

            const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
            const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
            const due = !standalone && !state.installed && (state.visits || 0) >= 2 && (!state.shown || Date.now() - state.shown > 14 * DAY);
            let deferred = null;

            const show = (mode) => {
                banner.querySelector('[data-pwa-text-ios]').classList.toggle('hidden', mode !== 'ios');
                banner.querySelector('[data-pwa-text-android]').classList.toggle('hidden', mode === 'ios');
                banner.querySelector('[data-pwa-install]').classList.toggle('hidden', mode === 'ios');
                banner.classList.remove('hidden');
                state.shown = Date.now();
                save();
            };
            const hide = () => banner.classList.add('hidden');

            banner.querySelector('[data-pwa-dismiss]').addEventListener('click', hide);
            banner.querySelector('[data-pwa-install]').addEventListener('click', async () => {
                hide();
                if (!deferred) return;
                try { await deferred.prompt(); } catch (e) {}
                deferred = null;
            });
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferred = e;
                if (due) setTimeout(() => show('android'), 3000);
            });
            window.addEventListener('appinstalled', () => { state.installed = true; save(); hide(); openers.forEach((b) => { b.hidden = true; }); });
            if (ios && due) setTimeout(() => { if (!deferred) show('ios'); }, 3000);

            // the guest asks for it ("add the menu to your phone" in the footer): the system dialog when the browser
            // offers one, otherwise the same banner with the iPhone / browser-menu steps
            const openers = document.querySelectorAll('[data-pwa-open]');
            if (!standalone) {
                openers.forEach((b) => {
                    b.hidden = false;
                    b.addEventListener('click', async () => {
                        if (deferred) {
                            try { await deferred.prompt(); } catch (e) {}
                            deferred = null;
                            return;
                        }
                        show('ios');
                    });
                });
            }
        })();
    </script>
@endif
