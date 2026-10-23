{{--
    The card as an app on the guest's phone: "Add the card to your phone" (Android/Chrome: the browser's install
    prompt; iPhone: a 2-step sheet Share → Add to Home Screen), a picture of the card to keep, Apple / Google Wallet
    when the platform has their keys, and the live refresh of the installed card from /card/{token}/state.
    Expects: $customer, $cardState (App\Services\Loyalty\CardState), $walletPasses (App\Services\Loyalty\WalletPasses).
--}}
@php($token = $customer->card_token)
<div class="mt-5 space-y-2" data-card-app data-state-url="{{ route('menu.card.state', $token) }}" data-version="{{ $cardState['version'] }}">
    <button type="button" class="btn-primary w-full gap-2" data-card-install>
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M12 7v7M9 11l3 3 3-3M10 18h4"/></svg>
        {{ __('Add the card to your phone') }}
    </button>
    <a href="{{ route('menu.card.image', $token) }}" download="card.png" class="btn-ghost w-full gap-2" data-card-save-image>
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>
        {{ __('Save the card picture') }}
    </a>
    @if ($walletPasses->appleConfigured())
        <a href="{{ route('menu.card.wallet.apple', $token) }}" class="btn w-full gap-2 bg-black text-white" data-wallet-apple>{{ __('Add to Apple Wallet') }}</a>
    @endif
    @if ($walletPasses->googleConfigured())
        <a href="{{ route('menu.card.wallet.google', $token) }}" class="btn w-full gap-2 bg-black text-white" rel="nofollow" data-wallet-google>{{ __('Save to Google Wallet') }}</a>
    @endif
    <p class="text-center text-xs m-muted" data-card-updated-line>
        {{ __('Last updated') }} <time data-card-updated datetime="{{ now()->toIso8601String() }}">{{ local_time(now(), 'Y/m/d h:i A') }}</time>
        <span class="hidden" data-card-offline>· {{ __('No connection: this is the card as last saved on your phone.') }}</span>
    </p>
</div>

{{-- iPhone (Safari): Share → Add to Home Screen. Also the fallback for a browser without an install prompt. --}}
<div class="fixed inset-0 z-50 hidden" data-card-sheet role="dialog" aria-modal="true" aria-labelledby="card-sheet-title">
    <div class="absolute inset-0 bg-black/50" data-card-sheet-close></div>
    <div class="absolute inset-x-0 bottom-0 mx-auto max-w-md rounded-t-3xl bg-white p-5 pb-8 text-navy-900 shadow-2xl">
        <div class="mx-auto mb-4 h-1.5 w-12 rounded-full bg-navy-200"></div>
        <div class="flex items-start justify-between gap-3">
            <h2 id="card-sheet-title" class="text-lg font-extrabold">{{ __('Add the card to your phone') }}</h2>
            <button type="button" class="-m-1 p-1 text-2xl leading-none text-navy-500" data-card-sheet-close aria-label="{{ __('Close') }}">×</button>
        </div>
        <p class="mt-1 text-sm text-navy-500">{{ __('Two steps, and the card opens from your home screen like an app.') }}</p>
        <ol class="mt-4 space-y-3">
            <li class="flex items-center gap-3 rounded-2xl bg-navy-50 p-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-navy-800 text-sm font-bold text-white">1</span>
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-white text-sky-600 shadow-sm">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>
                </span>
                <span class="text-sm"><b class="block">{{ __('Tap the share button') }}</b><span class="text-navy-500" data-sheet-ios>{{ __('At the bottom of Safari (at the top on iPad).') }}</span><span class="hidden text-navy-500" data-sheet-other>{{ __('Or open the browser menu (⋮).') }}</span></span>
            </li>
            <li class="flex items-center gap-3 rounded-2xl bg-navy-50 p-3">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-navy-800 text-sm font-bold text-white">2</span>
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-white text-navy-800 shadow-sm">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>
                </span>
                <span class="text-sm"><b class="block">{{ __('Choose "Add to Home Screen"') }}</b><span class="text-navy-500">{{ __('Then "Add": the card is on your home screen.') }}</span></span>
            </li>
        </ol>
        <button type="button" class="btn-primary mt-5 w-full" data-card-sheet-close>{{ __('Got it') }}</button>
    </div>
</div>

<script>
    (() => {
        const box = document.querySelector('[data-card-app]');
        if (!box) return;
        const standalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const install = box.querySelector('[data-card-install]');
        const sheet = document.querySelector('[data-card-sheet]');
        let deferred = null;

        // installed: nothing to add any more
        const refreshInstall = () => install.classList.toggle('hidden', standalone());
        refreshInstall();
        window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; });
        window.addEventListener('appinstalled', () => { install.classList.add('hidden'); deferred = null; });

        const openSheet = () => {
            sheet.querySelector('[data-sheet-ios]').classList.toggle('hidden', !ios);
            sheet.querySelector('[data-sheet-other]').classList.toggle('hidden', ios);
            sheet.classList.remove('hidden');
        };
        sheet.querySelectorAll('[data-card-sheet-close]').forEach((b) => b.addEventListener('click', () => sheet.classList.add('hidden')));
        install.addEventListener('click', async () => {
            if (deferred) {
                try { await deferred.prompt(); } catch (e) {}
                deferred = null;
                return;
            }
            openSheet();
        });

        // the card picture as a PNG (falls back to the SVG link when the browser cannot draw it)
        const save = box.querySelector('[data-card-save-image]');
        save.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                const svg = await (await fetch(save.href, { cache: 'no-store' })).text();
                const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
                const img = new Image();
                await new Promise((ok, fail) => { img.onload = ok; img.onerror = fail; img.src = url; });
                const canvas = document.createElement('canvas');
                canvas.width = 1280; canvas.height = 2000;
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                const blob = await new Promise((ok) => canvas.toBlob(ok, 'image/png'));
                if (!blob) throw new Error('png');
                const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: 'card.png' });
                document.body.appendChild(a); a.click(); a.remove();
            } catch (err) {
                window.location.href = save.href;
            }
        });

        // live refresh: the installed card re-reads its state when it opens and whenever it comes back to the front
        const stamp = box.querySelector('[data-card-updated]');
        const offline = box.querySelector('[data-card-offline]');
        let busy = false;
        const refresh = async () => {
            if (busy) return;
            busy = true;
            try {
                const response = await fetch(box.dataset.stateUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error(String(response.status));
                const state = await response.json();
                offline.classList.add('hidden');
                if (state.version !== box.dataset.version) { window.location.reload(); return; }
                const at = new Date(state.checked_at || Date.now());
                stamp.setAttribute('datetime', at.toISOString());
                stamp.textContent = at.toLocaleString(document.documentElement.lang === 'ar' ? 'ar-EG' : undefined, { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            } catch (e) {
                offline.classList.remove('hidden');
            } finally {
                busy = false;
            }
        };
        if (standalone() || !navigator.onLine) refresh();
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { refreshInstall(); refresh(); } });
        window.addEventListener('online', refresh);
    })();
</script>
