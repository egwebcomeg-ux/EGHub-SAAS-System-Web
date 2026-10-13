{{-- Surface styles of each menu template. The restaurant's primary color (--menu-primary) still drives buttons. --}}
@if ($theme === 'dark')
    :root { --menu-bg: #0f1420; }
    body { color: #f3f4f6; }
    .m-card { background: #1a2130 !important; border-color: #273044 !important; }
    .m-muted { color: #9aa3b5 !important; }
    [data-cat-nav] { background: rgba(15, 20, 32, .95) !important; border-color: #273044 !important; }
    [data-cat-nav] a:not(.m-primary) { background: #1a2130; color: #e5e7eb; }
    [data-cat-nav] input { background: #1a2130; border-color: #273044; color: #f3f4f6; }
    section h2 { color: #fff !important; }
@elseif ($theme === 'cafe')
    :root { --menu-bg: #f6efe6; --menu-secondary: #4a3426; }
    body { color: #3b2a20; }
    .m-card { background: #fffaf4 !important; border-color: #ead9c6 !important; border-radius: 1.5rem !important; }
    .m-muted { color: #8a6f5c !important; }
    [data-cat-nav] { background: rgba(246, 239, 230, .96) !important; border-color: #ead9c6 !important; }
    [data-cat-nav] a:not(.m-primary) { background: #fffaf4; color: #4a3426; border: 1px solid #ead9c6; }
    section h2 { font-size: 1.5rem !important; }
@elseif ($theme === 'bistro')
    :root { --menu-bg: #141414; --menu-secondary: #1c1c1c; --bistro-gold: #c9a45c; }
    body { color: #ece6da; }
    header.m-secondary { border-bottom: 1px solid rgba(201, 164, 92, .35); }
    .m-card { background: transparent !important; border-color: rgba(201, 164, 92, .18) !important; }
    .m-muted { color: #a59c8c !important; }
    .m-primary-text { color: var(--bistro-gold) !important; }
    [data-cat-nav] { background: rgba(20, 20, 20, .96) !important; border-color: rgba(201, 164, 92, .25) !important; }
    [data-cat-nav] a:not(.m-primary) { background: transparent; color: #ece6da; border: 1px solid rgba(201, 164, 92, .35); }
    [data-cat-nav] input { background: #1c1c1c; border-color: rgba(201, 164, 92, .3); color: #ece6da; }
    section h2 { color: var(--bistro-gold) !important; text-align: center; letter-spacing: .04em; }
    section h2::before, section h2::after { content: '◆'; font-size: .55em; margin: 0 .6em; vertical-align: middle; opacity: .7; }
@elseif ($theme === 'fastfood')
    :root { --menu-bg: #fff8e7; --menu-secondary: #d62828; }
    body { color: #1f1300; }
    .m-card { border: 0 !important; border-radius: 1.75rem !important; box-shadow: 0 6px 18px rgba(214, 40, 40, .12) !important; }
    [data-cat-nav] { background: rgba(255, 248, 231, .96) !important; }
    [data-cat-nav] a:not(.m-primary) { background: #ffd23f; color: #1f1300; }
    section h2 { font-size: 1.6rem !important; text-transform: uppercase; color: #d62828 !important; }
@elseif ($theme === 'oriental')
    :root { --menu-bg: #f8f1e3; --menu-secondary: #6b1d1d; }
    body { color: #3a2316; background-image: radial-gradient(rgba(107, 29, 29, .07) 1.5px, transparent 1.5px); background-size: 18px 18px; }
    .m-card { background: #fffdf8 !important; border: 1px solid #e3cfa8 !important; box-shadow: inset 0 0 0 4px #fffdf8, inset 0 0 0 5px #ecdcbc; }
    .m-muted { color: #7d6450 !important; }
    [data-cat-nav] { background: rgba(248, 241, 227, .96) !important; border-color: #e3cfa8 !important; }
    [data-cat-nav] a:not(.m-primary) { background: #fffdf8; color: #6b1d1d; border: 1px solid #e3cfa8; }
    section h2 { color: #6b1d1d !important; border-bottom: 2px solid #c9a45c; display: inline-block; padding-bottom: .15rem; }
@endif
