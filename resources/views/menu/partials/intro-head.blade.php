{{--
    Before the first paint: decide whether the intro plays (every time the menu is opened, or once per browser session — setting menu_intro_repeat; never under reduced motion) and whether the "how will you order?" screen opens (no choice remembered yet), so the
    menu never flashes before them. The overlays fade out on their own in CSS even if the menu script fails.
--}}
@php
    $introKey = 'egmenu.intro.' . tenant()->getTenantKey();
    $wayKey = 'egmenu.way.' . tenant()->getTenantKey();
    $branchKey = 'egmenu.branch.' . tenant()->getTenantKey();
    // "always": every time the menu is opened (a scan, a link, a new tab) — but not when coming back from another page
    // of the same menu (order tracking, card); "session": once per browser session
    $introAlways = \App\Services\Settings::get('menu_intro_repeat') !== 'session';
@endphp
<script>
    (function () {
        var root = document.documentElement, store = null;
        try { store = window.sessionStorage; } catch (e) {}
        var read = function (key) { try { return store ? store.getItem(key) : null; } catch (e) { return null; } };
        @if ($intro !== 'off')
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var internal = false;
        try { internal = !!document.referrer && new URL(document.referrer).host === location.host; } catch (e) {}
        var again = @json($introAlways) ? !internal : (store && !read(@json($introKey)));
        if (!calm && again) {
            root.classList.add('intro-open');
            try { if (store) { store.setItem(@json($introKey), '1'); } } catch (e) {}
        }
        @endif
        @if ($choice || $branchFirst)
        // the first-open sheet: no way of ordering remembered yet, or a first visit with no branch chosen
        var firstOpen = false;
        @if ($choice)
        if (!read(@json($wayKey))) { firstOpen = true; }
        @endif
        @if ($branchFirst)
        try { var mem = JSON.parse(window.localStorage.getItem(@json($branchKey)) || '{}'); if (!mem || !mem.current) { firstOpen = true; } } catch (e) {}
        @endif
        if (firstOpen) { root.classList.add('choice-open'); }
        @endif
    })();
</script>
<style>
    .m-intro, .m-choice { display: none; }
    html.intro-open .m-intro { display: flex; }
    html.choice-open .m-choice { display: flex; }
    html.choice-open { overflow: hidden; }
    .m-choice-close { display: none; }
    html.choice-reopen .m-choice-close, .m-choice-card--closable .m-choice-close { display: block; }

    /* intro: brand glow, logo rises with a soft scale, tagline fades in, then the overlay fades out (≈1.8 s; full ≈2.3 s) */
    .m-intro {
        --intro-end: 1.4s;
        position: fixed; inset: 0; z-index: 100; flex-direction: column; align-items: center; justify-content: center; gap: 18px;
        background: var(--menu-secondary); color: #fff; overflow: hidden; cursor: pointer; -webkit-tap-highlight-color: transparent;
        animation: m-intro-out .4s ease var(--intro-end) forwards;
    }
    .m-intro--full { --intro-end: 1.9s; }
    .m-intro.is-leaving { animation: m-intro-out .3s ease forwards; }
    .m-intro-glow {
        position: absolute; width: 150vmax; height: 150vmax; border-radius: 50%; pointer-events: none;
        background: radial-gradient(circle, color-mix(in srgb, var(--menu-accent) 55%, transparent) 0%, transparent 58%);
        animation: m-intro-glow 1.4s ease-out both;
    }
    .m-intro-pattern {
        position: absolute; inset: -56px; pointer-events: none; opacity: .3;
        background-image: radial-gradient(color-mix(in srgb, var(--menu-accent) 70%, #fff) 1.6px, transparent 2px);
        background-size: 28px 28px; animation: m-intro-drift 2.4s linear both;
    }
    .m-intro-ring {
        position: absolute; width: min(64vw, 280px); aspect-ratio: 1; border-radius: 50%; pointer-events: none;
        border: 3px solid var(--menu-accent); opacity: 0; animation: m-intro-ring 1.1s .55s ease-out both;
    }
    .m-intro-stage { position: relative; display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 0 24px; text-align: center; }
    .m-intro-logo {
        width: min(46vw, 176px); height: min(46vw, 176px); object-fit: contain; border-radius: 32px; background: #fff; padding: 14px;
        box-shadow: 0 18px 50px -18px rgba(0, 0, 0, .55); animation: m-intro-rise .75s .1s cubic-bezier(.2, .8, .2, 1) both;
    }
    /* transparent logos on dark templates (setting logo_background): no tile, a soft glow keeps the edges crisp */
    .m-intro-logo--bare { background: transparent; padding: 0; border-radius: 0; box-shadow: none; filter: drop-shadow(0 10px 28px rgba(0, 0, 0, .45)); }
    .m-intro-name { font-size: clamp(2rem, 9vw, 3.2rem); font-weight: 900; line-height: 1.2; animation: m-intro-rise .75s .1s cubic-bezier(.2, .8, .2, 1) both; }
    .m-intro-tagline { max-width: 28rem; font-size: 1.05rem; font-weight: 700; color: rgba(255, 255, 255, .85); animation: m-intro-fade .55s .65s ease both; }
    .m-intro--full .m-intro-tagline { animation: m-intro-pop .6s .8s cubic-bezier(.3, 1.4, .5, 1) both; }
    .m-intro-skip { position: absolute; bottom: calc(20px + env(safe-area-inset-bottom)); font-size: .75rem; font-weight: 700; color: rgba(255, 255, 255, .65); }
    @keyframes m-intro-glow { from { opacity: 0; transform: scale(.3); } to { opacity: .95; transform: none; } }
    @keyframes m-intro-drift { from { transform: translate(0, 0); } to { transform: translate(28px, 28px); } }
    @keyframes m-intro-ring { 0% { opacity: .85; transform: scale(.45); } 100% { opacity: 0; transform: scale(1.6); } }
    @keyframes m-intro-rise { from { opacity: 0; transform: translateY(30px) scale(.82); } to { opacity: 1; transform: none; } }
    @keyframes m-intro-fade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    @keyframes m-intro-pop { from { opacity: 0; transform: scale(.5); } to { opacity: 1; transform: none; } }
    @keyframes m-intro-out { to { opacity: 0; visibility: hidden; } }
    @media (prefers-reduced-motion: reduce) { .m-intro { display: none !important; } }

    /* the first-open sheet: "how will you order?" and the branch, one confirm */
    .m-choice {
        position: fixed; inset: 0; z-index: 90; align-items: flex-end; justify-content: center;
        background: color-mix(in srgb, var(--menu-secondary) 82%, #000); padding: 0;
    }
    .m-choice-card {
        width: 100%; max-width: 30rem; max-height: 94dvh; overflow-y: auto; overscroll-behavior: contain;
        border-radius: 28px 28px 0 0; padding: 22px 20px calc(18px + env(safe-area-inset-bottom));
        display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center;
        box-shadow: 0 -20px 60px -24px rgba(0, 0, 0, .6); animation: m-choice-rise .45s cubic-bezier(.2, .8, .2, 1) both;
    }
    @media (min-width: 640px) { .m-choice { align-items: center; } .m-choice-card { border-radius: 28px; } }
    @keyframes m-choice-rise { from { transform: translateY(40%); opacity: 0; } to { transform: none; opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .m-choice-card { animation: none; } }
</style>
