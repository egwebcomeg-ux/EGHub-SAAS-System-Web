{{--
    Before the first paint: decide whether the intro plays (every time the menu is opened, or once per browser session — setting menu_intro_repeat; never under reduced motion) and whether the branch + "how will you order?" sheet opens (2+ branches, none chosen yet), so the
    menu never flashes before them. The overlays fade out on their own in CSS even if the menu script fails.
--}}
@php
    $introKey = 'egmenu.intro.' . tenant()->getTenantKey();
    $branchKey = 'egmenu.branch.' . tenant()->getTenantKey();
    // "always": every time the menu is opened (a scan, a link, a new tab) — but not when coming back from another page
    // of the same menu (order tracking, card); "session": once per browser session
    // (the mascot intro is longer: once per browser session, like the brand it comes from)
    $introAlways = $intro !== 'mascot' && \App\Services\Settings::get('menu_intro_repeat') !== 'session';
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
        @if ($branchFirst ?? false)
        // the first-open sheet: a first visit to a place with 2+ branches and no branch chosen yet (a single branch
        // never opens it by itself: the guest browses at once and picks the way where it is needed)
        try { var mem = JSON.parse(window.localStorage.getItem(@json($branchKey)) || '{}'); if (!mem || !mem.current) { root.classList.add('choice-open'); } } catch (e) {}
        @endif
    })();
</script>
<style>
    .m-intro, .m-choice { display: none; }
    html.intro-open .m-intro { display: flex; }
    html.choice-open .m-choice { display: flex; }
    html.choice-open { overflow: hidden; }
    .m-choice-close { display: none; }
    html.choice-reopen .m-choice-close, .m-choice-card--closable .m-choice-close { display: grid; }

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

    /* mascot intro (port of the Scratch cafe intro): the page colour, a drifting pattern tile, a soft white glow */
    .m-intro--mascot { --intro-end: 2.6s; --roll-from: -120vw; --roll-over: 3vw; --spin: -720deg; --tilt: 14deg;
        background: var(--menu-bg); color: var(--menu-text); }
    [dir="rtl"] .m-intro--mascot { --roll-from: 120vw; --roll-over: -3vw; --spin: 720deg; --tilt: -14deg; }
    .m-mascot-pattern { position: absolute; top: -240px; left: -240px; right: 0; bottom: 0; pointer-events: none;
        background-size: 240px; background-repeat: repeat; opacity: .08; animation: m-pattern-drift 60s linear infinite; }
    .m-mascot-glow { position: absolute; width: 120vmax; height: 120vmax; border-radius: 50%; pointer-events: none;
        background: radial-gradient(circle, #fff 0%, rgba(255, 255, 255, 0) 55%); opacity: .9; }
    .m-mascot-stage { position: relative; display: flex; flex-direction: column; align-items: center; gap: min(2.2vh, 18px); padding: 0 24px; text-align: center; }
    .m-char { position: relative; height: min(27vh, 230px); aspect-ratio: 1; max-width: 62vw; }
    .m-char-move, .m-char-squash, .m-char-float { position: absolute; inset: 0; }
    .m-char img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; }
    .m-char-bean { transform-origin: 57% 49%; }
    .m-char-speed { transform-origin: 30% 72%; }
    [dir="rtl"] .m-char img { transform: scaleX(-1); }
    .m-char-spark { position: absolute; top: 4%; inset-inline-end: 0; color: var(--menu-accent); font-size: min(5vh, 38px); line-height: 1; opacity: 0; }
    .m-mascot-logo { height: min(13vh, 112px); max-width: 76vw; object-fit: contain; opacity: 0; }
    .m-mascot-name { height: auto; font-size: clamp(2rem, 9vw, 3rem); font-weight: 900; color: var(--menu-accent); }
    .m-mascot-slogan { height: min(17vh, 150px); max-width: 52vw; object-fit: contain; opacity: 0; transform: rotate(-4deg); }
    .m-mascot-tagline { height: auto; max-width: 22rem; font-size: 1.15rem; font-weight: 900; color: var(--menu-accent); }
    .m-intro--mascot .m-intro-skip { color: color-mix(in srgb, var(--menu-text) 50%, transparent); }
    .m-intro--mascot .m-char-move { animation: m-roll-in 1s cubic-bezier(.22, .85, .32, 1) both; }
    .m-intro--mascot .m-char-bean { animation: m-roll-spin 1s cubic-bezier(.22, .85, .32, 1) both; }
    .m-intro--mascot .m-char-speed { animation: m-speed-trail 1.1s ease-out both; }
    .m-intro--mascot .m-char-squash { animation: m-land .5s .95s ease-out both; }
    .m-intro--mascot .m-char-spark { animation: m-spark .7s 1.5s ease-out both; }
    .m-intro--mascot .m-mascot-logo { animation: m-intro-fade .6s 1.05s cubic-bezier(.2, .8, .2, 1) both; }
    .m-intro--mascot .m-mascot-slogan { animation: m-slogan-pop .65s 1.45s cubic-bezier(.3, 1.5, .5, 1) both; }
    .m-intro--mascot .m-char-float { animation: m-float 3.2s 1.5s ease-in-out infinite; }
    /* the bean keeps its mirror in RTL while it spins */
    [dir="rtl"] .m-intro--mascot .m-char-bean { animation-name: m-roll-spin-rtl; }
    [dir="rtl"] .m-intro--mascot .m-char-speed { animation-name: m-speed-trail-rtl; }
    @keyframes m-pattern-drift { to { transform: translate(240px, 240px); } }
    @keyframes m-roll-in { 0% { transform: translateX(var(--roll-from)); } 78% { transform: translateX(var(--roll-over)); } 100% { transform: none; } }
    @keyframes m-roll-spin { 0% { transform: rotate(var(--spin)); } 78% { transform: rotate(var(--tilt)); } 100% { transform: none; } }
    @keyframes m-roll-spin-rtl { 0% { transform: scaleX(-1) rotate(var(--spin)); } 78% { transform: scaleX(-1) rotate(var(--tilt)); } 100% { transform: scaleX(-1); } }
    @keyframes m-speed-trail { 0%, 70% { transform: scaleX(2.4); } 100% { transform: none; } }
    @keyframes m-speed-trail-rtl { 0%, 70% { transform: scaleX(-2.4); } 100% { transform: scaleX(-1); } }
    @keyframes m-land { 30% { transform: scale(1.12, .86) translateY(6%); } 60% { transform: scale(.95, 1.06) translateY(-4%); } 100% { transform: none; } }
    @keyframes m-spark { 0% { opacity: 0; transform: scale(.2) rotate(-40deg); } 50% { opacity: 1; transform: scale(1.2) rotate(10deg); } 100% { opacity: 0; transform: scale(.6) rotate(40deg); } }
    @keyframes m-slogan-pop { from { opacity: 0; transform: scale(.3) rotate(-14deg); } to { opacity: 1; transform: rotate(-4deg); } }
    @keyframes m-float { 50% { transform: translateY(-5%) rotate(2deg); } }

    /*
     * The sheet, short and light: a small header (logo + the question), the branch list (2+ branches) that takes the
     * room left and scrolls on its own, then the ways of ordering as a row of tiles that always stays on screen.
     * Phones: a bottom sheet; wider screens: a centred card capped at 28rem.
     */
    .m-choice {
        position: fixed; inset: 0; z-index: 90; align-items: flex-end; justify-content: center; padding: 0;
        /* the menu (cover, header) shows through, blurred and tinted with the brand colour */
        background: color-mix(in srgb, var(--menu-secondary) 38%, rgba(0, 0, 0, .28));
        -webkit-backdrop-filter: blur(12px) saturate(1.15); backdrop-filter: blur(12px) saturate(1.15);
        animation: m-choice-fade .3s ease both;
    }
    .m-choice-card {
        width: 100%; max-width: 28rem; max-height: min(92dvh, 48rem); overflow: hidden;
        border-radius: 24px 24px 0 0; padding: 16px 16px calc(14px + env(safe-area-inset-bottom));
        display: flex; flex-direction: column; gap: 12px; text-align: start;
        box-shadow: 0 -20px 60px -24px rgba(0, 0, 0, .6); animation: m-choice-rise .4s cubic-bezier(.2, .8, .2, 1) both;
    }
    /* a soft wash of the brand colour at the top, on the theme's own surface (light and dark templates) */
    .m-choice-card.m-surface { background: linear-gradient(180deg, color-mix(in srgb, var(--menu-accent) 10%, var(--menu-surface)) 0, var(--menu-surface) 5.5rem); }
    .m-choice-head { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .m-choice-close { flex-shrink: 0; background: var(--menu-surface-2); color: var(--menu-text); box-shadow: inset 0 0 0 1px var(--menu-border); }
    /* a bare (transparent) logo sits on a surface tile, so it never melts into the sheet */
    .m-choice-logo { height: 48px; width: auto; max-width: 7.5rem; flex-shrink: 0; object-fit: contain; border-radius: 14px; padding: 4px 8px;
        background: var(--menu-surface); box-shadow: 0 0 0 1px var(--menu-border), 0 6px 16px -10px rgba(0, 0, 0, .35); }
    .m-choice-logo--tile { background: #fff; padding: 4px; }
    .m-choice-logo--name { display: grid; place-items: center; width: 48px; padding: 0; font-size: 1.4rem; font-weight: 900; box-shadow: none; background: var(--menu-accent); color: var(--menu-accent-text); }
    .m-choice-brand { display: block; font-size: .8rem; font-weight: 800; color: var(--menu-muted); }
    /* the branch part takes the room that is left; its list scrolls inside, the ways below never leave the screen */
    .m-choice-branch { display: flex; flex-direction: column; gap: 8px; min-height: 0; flex: 0 1 auto; }
    .m-choice-label { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-shrink: 0; }
    /* "nearest to me": a small secondary action, asks for the location only on the tap */
    .m-choice-near { display: inline-flex; align-items: center; gap: 6px; min-height: 2.25rem; padding: 0 12px; border-radius: 999px; flex-shrink: 0;
        font-size: .8rem; font-weight: 800; color: var(--menu-accent); background: color-mix(in srgb, var(--menu-accent) 10%, var(--menu-surface)); }
    .m-choice-near:disabled { opacity: .6; }
    .m-choice-near svg { width: 15px; height: 15px; }
    .m-choice-branches { display: grid; gap: 6px; align-content: start; min-height: 6.6rem; overflow-y: auto; overscroll-behavior: contain; padding: 2px; flex: 0 1 auto; }
    .m-branch-row { display: flex; align-items: center; gap: 10px; min-height: 3rem; border-radius: 14px; padding: 6px 12px;
        background: var(--menu-surface-2); color: var(--menu-text); box-shadow: inset 0 0 0 1px var(--menu-border); transition: box-shadow .15s ease, background-color .15s ease; }
    .m-branch-row.is-on { background: color-mix(in srgb, var(--menu-accent) 10%, var(--menu-surface)); box-shadow: inset 0 0 0 2px var(--menu-accent); }
    .m-branch-row:focus-visible { outline: 2px solid var(--menu-accent); outline-offset: 2px; }
    .m-branch-dot { width: 18px; height: 18px; flex-shrink: 0; border-radius: 999px; box-shadow: inset 0 0 0 2px var(--menu-border); }
    .m-branch-row.is-on .m-branch-dot { background: var(--menu-accent); box-shadow: inset 0 0 0 4px color-mix(in srgb, var(--menu-accent) 55%, var(--menu-surface)); }
    /* the ways of ordering: a row of tiles (two or three side by side), a tap continues */
    .m-way-grid { display: grid; grid-template-columns: repeat(var(--ways, 3), minmax(0, 1fr)); gap: 8px; flex-shrink: 0; }
    .m-way-card { display: flex; flex-direction: column; align-items: center; justify-content: flex-start; gap: 6px; width: 100%; min-height: 5.75rem;
        border-radius: 18px; padding: 10px 6px; text-align: center; line-height: 1.2;
        background: var(--menu-surface-2); color: var(--menu-text); box-shadow: inset 0 0 0 1.5px var(--menu-border);
        transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease; -webkit-tap-highlight-color: transparent; }
    .m-way-card:hover, .m-way-card:focus-visible { box-shadow: inset 0 0 0 2px var(--menu-accent); outline: none; }
    .m-way-card:active { transform: scale(.97); }
    .m-way-card:disabled { opacity: .45; cursor: not-allowed; transform: none; box-shadow: none; }
    .m-way-card[aria-pressed="true"] { background: color-mix(in srgb, var(--menu-accent) 10%, var(--menu-surface)); box-shadow: inset 0 0 0 2px var(--menu-accent); }
    .m-way-icon { display: grid; place-items: center; width: 40px; height: 40px; flex-shrink: 0; border-radius: 13px; background: var(--menu-accent); color: var(--menu-accent-text); }
    .m-way-icon svg { width: 22px; height: 22px; }
    .m-way-name { font-size: .95rem; font-weight: 900; }
    .m-way-sub { font-size: .72rem; font-weight: 600; color: var(--menu-muted); }
    /* three tiles on a narrow phone: the name alone keeps them short */
    @media (max-width: 400px) { .m-way-grid--3 .m-way-sub { display: none; } .m-way-grid--3 .m-way-card { min-height: 5rem; } }
    .m-way-card.is-going { background: var(--menu-accent); color: var(--menu-accent-text); }
    .m-way-card.is-going .m-way-sub { color: inherit; opacity: .85; }
    .m-way-card.is-going .m-way-icon { background: var(--menu-accent-text); color: var(--menu-accent); }
    .m-choice-foot { flex-shrink: 0; text-align: center; font-size: .75rem; }
    /* entrance: the parts rise one after the other */
    .m-choice-in { animation: m-choice-item .45s cubic-bezier(.2, .8, .2, 1) both; animation-delay: calc(.08s + var(--i, 0) * .05s); }
    @media (min-width: 640px) { .m-choice { align-items: center; padding: 24px; } .m-choice-card { border-radius: 24px; padding-bottom: 16px; } }
    /* very short screens (a phone held sideways): the whole card scrolls instead of squeezing the list */
    @media (max-height: 520px) { .m-choice-card { overflow-y: auto; } .m-choice-card > * { flex-shrink: 0; } .m-choice-branches { flex: none; max-height: 9rem; } }
    @keyframes m-choice-rise { from { transform: translateY(40%); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes m-choice-item { from { transform: translateY(12px); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes m-choice-fade { from { opacity: 0; } to { opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .m-choice-card { animation: none; } }
    @media (prefers-reduced-motion: reduce) { .m-choice, .m-choice-in { animation: none; } .m-way-card, .m-branch-row { transition: none; } }
</style>
