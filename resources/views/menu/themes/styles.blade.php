{{-- Decoration of each menu template. Colors come from the tokens of menu/partials/theme (App\Support\MenuThemes::PALETTES). --}}
.m-card { background: var(--menu-surface); border-color: var(--menu-border); color: var(--menu-text); }
[data-cat-nav] { background: color-mix(in srgb, var(--menu-bg) 95%, transparent) !important; border-color: var(--menu-border) !important; }
[data-cat-nav] a:not(.m-primary) { background: var(--menu-surface-2); color: var(--menu-text); }
@if ($theme === 'dark')
    section h2 { color: #fff !important; }
@elseif ($theme === 'cafe')
    :root { --menu-secondary: #4a3426; }
    .m-card { border-radius: 1.5rem !important; }
    [data-cat-nav] a:not(.m-primary) { background: var(--menu-surface); border: 1px solid var(--menu-border); }
    section h2 { font-size: 1.5rem !important; }
@elseif ($theme === 'bistro')
    :root { --menu-secondary: #1c1c1c; --bistro-gold: #c9a45c; }
    header.m-secondary { border-bottom: 1px solid rgba(201, 164, 92, .35); }
    .m-card { background: transparent; border-color: rgba(201, 164, 92, .18); }
    [data-cat-nav] a:not(.m-primary) { background: transparent; border: 1px solid rgba(201, 164, 92, .35); }
    section h2 { color: var(--bistro-gold) !important; text-align: center; letter-spacing: .04em; }
    section h2::before, section h2::after { content: '◆'; font-size: .55em; margin: 0 .6em; vertical-align: middle; opacity: .7; }
@elseif ($theme === 'fastfood')
    :root { --menu-secondary: #d62828; }
    .m-card { border: 0 !important; border-radius: 1.75rem !important; box-shadow: 0 6px 18px rgba(214, 40, 40, .12) !important; }
    [data-cat-nav] a:not(.m-primary) { background: #ffd23f; color: #1f1300; }
    section h2 { font-size: 1.6rem !important; text-transform: uppercase; color: #d62828 !important; }
@elseif ($theme === 'oriental')
    :root { --menu-secondary: #6b1d1d; }
    body { background-image: radial-gradient(rgba(107, 29, 29, .07) 1.5px, transparent 1.5px); background-size: 18px 18px; }
    .m-card { border: 1px solid var(--menu-border) !important; box-shadow: inset 0 0 0 4px var(--menu-surface), inset 0 0 0 5px #ecdcbc; }
    [data-cat-nav] a:not(.m-primary) { background: var(--menu-surface); color: #6b1d1d; border: 1px solid var(--menu-border); }
    section h2 { color: #6b1d1d !important; border-bottom: 2px solid #c9a45c; display: inline-block; padding-bottom: .15rem; }
@elseif ($theme === 'story')
    {{-- «الحكاية»: a full-screen welcome in the brand color (or the cover under a dark veil), folk zigzag edges, the
         category row at the bottom of the screen, and «زورنا» before the footer --}}
    .m-story-hero { position: relative; display: grid; place-items: center; min-height: 86svh; padding: 3rem 1.25rem 4.5rem; text-align: center; color: #fff; background-color: var(--menu-secondary); background-size: cover; background-position: center; }
    .m-story-hero[data-story-cover] { background-image: linear-gradient(rgba(20, 12, 6, .58), rgba(20, 12, 6, .58)), var(--story-cover); }
    .m-story-hero::after { content: ''; position: absolute; inset-inline: 0; bottom: -1px; height: 14px; background: conic-gradient(from -45deg at 50% 100%, var(--menu-bg) 90deg, transparent 0) 0 100% / 22px 14px repeat-x; }
    .m-story-logo { width: auto; max-width: min(10rem, 45vw); max-height: 9rem; margin-inline: auto; margin-bottom: 1.25rem; object-fit: contain; }
    .m-story-name { font-size: clamp(2.1rem, 9vw, 3.4rem); font-weight: 900; line-height: 1.1; text-wrap: balance; }
    .m-story-tagline { max-width: 32rem; margin: .6rem auto 0; font-size: 1.15rem; opacity: .9; text-wrap: balance; }
    .m-story-actions { margin-top: 1.75rem; display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; }
    .m-story-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; padding: 0 1.6rem; border-radius: 999px; font-weight: 800; }
    .m-story-btn-ghost { color: #fff; box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .85); }
    .m-story-band { max-width: 36rem; margin: 0 auto; padding: 2.25rem 1.25rem .5rem; text-align: center; }
    .m-story-band h2, .m-story-visit h2 { font-size: 1.6rem; font-weight: 900; color: var(--menu-secondary); }
    .m-story-band p { margin-top: .75rem; font-size: 1.05rem; line-height: 1.9; color: var(--menu-muted); white-space: pre-line; }
    section[data-category] > h2 { display: block; text-align: center; font-size: 1.65rem !important; }
    section[data-category] > h2::after { content: ''; display: block; width: 7rem; height: 8px; margin: .55rem auto 0; background: conic-gradient(from -45deg at 50% 100%, var(--menu-accent) 90deg, transparent 0) 0 100% / 14px 8px repeat-x; }
    section[data-category] > p { text-align: center; }
    .m-story-cat-img { width: 100%; aspect-ratio: 16 / 7; object-fit: cover; border-radius: 1.25rem; margin-bottom: 1rem; }
    .m-story-visit { margin-top: 2.5rem; padding: 1.75rem 1.25rem; border-radius: 1.5rem; text-align: center; background: var(--menu-surface); box-shadow: inset 0 0 0 1px var(--menu-border); }
    .m-story-visit dl { margin-top: 1rem; display: grid; gap: .6rem; }
    .m-story-visit dt { font-size: .8rem; font-weight: 800; letter-spacing: .02em; color: var(--menu-muted); }
    {{-- the category row sits at the bottom of the screen; the cart bar and the waiter bell move above it --}}
    [data-cat-nav] { position: fixed !important; top: auto !important; bottom: 0; inset-inline: 0; border-bottom: 0 !important; border-top: 1px solid var(--menu-border); padding-bottom: env(safe-area-inset-bottom, 0px); }
    [data-cart-bar] { bottom: calc(4.25rem + env(safe-area-inset-bottom, 0px)); }
    [data-cat-nav][data-cat-style="circles"] ~ [data-cart-bar] { bottom: calc(6.5rem + env(safe-area-inset-bottom, 0px)); }
    [data-call-open] { bottom: calc(10.5rem + env(safe-area-inset-bottom, 0px)) !important; }
    [data-menu-main] { padding-bottom: 13rem !important; }
@endif
