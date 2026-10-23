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

    /* light intro (setting intro_background): the page colour, accent rings and dots, the logo as drawn */
    .m-intro--light { background: var(--menu-bg); color: var(--menu-text); }
    .m-intro--light .m-intro-glow { background: radial-gradient(circle, color-mix(in srgb, var(--menu-accent) 22%, transparent) 0%, transparent 55%); }
    .m-intro--light .m-intro-pattern { opacity: .22; background-image: radial-gradient(var(--menu-accent) 1.6px, transparent 2px); }
    .m-intro--light .m-intro-ring { border-color: color-mix(in srgb, var(--menu-accent) 70%, transparent); }
    .m-intro--light .m-intro-logo--bare { filter: drop-shadow(0 10px 24px rgba(0, 0, 0, .12)); }
    .m-intro--light .m-intro-name { color: var(--menu-accent); }
    .m-intro--light .m-intro-tagline { color: var(--menu-muted); }
    .m-intro--light .m-intro-skip { color: var(--menu-muted); }

    /* the first-open sheet, one step: the branch (2+), then a tap on an order type continues */
    .m-choice {
        position: fixed; inset: 0; z-index: 90; align-items: flex-end; justify-content: center; padding: 0;
        /* the menu (cover, header) shows through, blurred and tinted with the brand colour */
        background: color-mix(in srgb, var(--menu-secondary) 38%, rgba(0, 0, 0, .28));
        -webkit-backdrop-filter: blur(14px) saturate(1.15); backdrop-filter: blur(14px) saturate(1.15);
        animation: m-choice-fade .3s ease both;
    }
    .m-choice-card {
        width: 100%; max-width: 30rem; max-height: 92dvh; overflow-y: auto; overscroll-behavior: contain;
        border-radius: 28px 28px 0 0; padding: 0 18px calc(18px + env(safe-area-inset-bottom));
        display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center;
        box-shadow: 0 -20px 60px -24px rgba(0, 0, 0, .6); animation: m-choice-rise .45s cubic-bezier(.2, .8, .2, 1) both;
    }
    .m-choice-hero {
        align-self: stretch; margin: 0 -18px; height: 88px; flex-shrink: 0; border-radius: 28px 28px 0 0;
        background: radial-gradient(120% 140% at 85% 0%, color-mix(in srgb, var(--menu-accent) 85%, #fff) 0%, transparent 55%),
            linear-gradient(135deg, var(--menu-secondary), color-mix(in srgb, var(--menu-secondary) 55%, var(--menu-accent)));
        background-size: cover; background-position: center;
    }
    .m-choice-close { background: rgba(0, 0, 0, .35); color: #fff; }
    .m-choice-brand { display: flex; flex-direction: column; align-items: center; gap: 6px; margin-top: -46px; }
    .m-choice-logo { height: 76px; width: auto; max-width: 9rem; object-fit: contain; filter: drop-shadow(0 8px 18px rgba(0, 0, 0, .28)); }
    .m-choice-logo--tile { background: #fff; border-radius: 22px; padding: 6px; box-shadow: 0 0 0 4px var(--menu-surface), 0 10px 24px -8px rgba(0, 0, 0, .35); filter: none; }
    .m-choice-logo--name { display: grid; place-items: center; width: 76px; border-radius: 24px; font-size: 2.2rem; font-weight: 900; box-shadow: 0 0 0 4px var(--menu-surface); filter: none; }
    .m-choice-name { font-size: .95rem; font-weight: 800; color: var(--menu-muted); }
    /* "nearest branch to me": prominent, asks for the location only on the tap */
    .m-choice-near { background: color-mix(in srgb, var(--menu-accent) 12%, var(--menu-surface)); color: var(--menu-accent); box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--menu-accent) 45%, transparent); }
    .m-choice-near:disabled { opacity: .6; }
    /* branches: compact radio rows, the list scrolls inside the sheet */
    .m-choice-branches { max-height: min(15rem, 34dvh); overflow-y: auto; overscroll-behavior: contain; padding: 2px; }
    .m-branch-row { display: flex; align-items: center; gap: 10px; min-height: 3.25rem; border-radius: 16px; padding: 8px 12px;
        background: var(--menu-surface-2); color: var(--menu-text); box-shadow: inset 0 0 0 1px var(--menu-border); transition: box-shadow .15s ease, background-color .15s ease; }
    .m-branch-row.is-on { background: color-mix(in srgb, var(--menu-accent) 10%, var(--menu-surface)); box-shadow: inset 0 0 0 2px var(--menu-accent); }
    .m-branch-dot { width: 20px; height: 20px; flex-shrink: 0; border-radius: 999px; box-shadow: inset 0 0 0 2px var(--menu-border); }
    .m-branch-row.is-on .m-branch-dot { background: var(--menu-accent); box-shadow: inset 0 0 0 5px color-mix(in srgb, var(--menu-accent) 60%, var(--menu-surface)); }
    /* the order types: big cards, a tap continues */
    .m-way-card { display: flex; align-items: center; gap: 14px; width: 100%; min-height: 4.75rem; border-radius: 20px; padding: 12px 14px;
        background: var(--menu-surface-2); color: var(--menu-text); box-shadow: inset 0 0 0 1.5px var(--menu-border);
        transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease; -webkit-tap-highlight-color: transparent; }
    .m-way-card:hover, .m-way-card:focus-visible { box-shadow: inset 0 0 0 2px var(--menu-accent); }
    .m-way-card:active { transform: scale(.97); }
    .m-way-card[aria-pressed="true"] { background: color-mix(in srgb, var(--menu-accent) 10%, var(--menu-surface)); box-shadow: inset 0 0 0 2px var(--menu-accent); }
    .m-way-icon { display: grid; place-items: center; width: 52px; height: 52px; flex-shrink: 0; border-radius: 16px; background: var(--menu-accent); color: var(--menu-accent-text); box-shadow: 0 8px 18px -10px var(--menu-accent); }
    .m-way-icon svg { width: 28px; height: 28px; }
    .m-way-go { display: grid; place-items: center; width: 32px; height: 32px; flex-shrink: 0; border-radius: 999px; color: var(--menu-accent); background: color-mix(in srgb, var(--menu-accent) 12%, transparent); }
    .m-way-go svg { width: 18px; height: 18px; }
    [dir="rtl"] .m-way-go svg { transform: scaleX(-1); }
    .m-way-card.is-going { background: var(--menu-accent); color: var(--menu-accent-text); }
    .m-way-card.is-going .m-muted { color: inherit; opacity: .85; }
    .m-way-card.is-going .m-way-icon { background: var(--menu-accent-text); color: var(--menu-accent); }
    /* entrance: the parts rise one after the other */
    .m-choice-in { animation: m-choice-item .5s cubic-bezier(.2, .8, .2, 1) both; animation-delay: calc(.12s + var(--i, 0) * .06s); }
    @media (min-width: 640px) { .m-choice { align-items: center; } .m-choice-card { border-radius: 28px; } .m-choice-hero { border-radius: 28px 28px 0 0; } }
    @keyframes m-choice-rise { from { transform: translateY(40%); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes m-choice-item { from { transform: translateY(14px); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes m-choice-fade { from { opacity: 0; } to { opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .m-choice-card { animation: none; } }
    @media (prefers-reduced-motion: reduce) { .m-choice, .m-choice-in { animation: none; } .m-way-card, .m-branch-row { transition: none; } }
</style>
