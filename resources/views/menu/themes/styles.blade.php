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
@endif
