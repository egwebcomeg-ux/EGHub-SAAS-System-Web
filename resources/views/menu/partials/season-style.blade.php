{{-- Light decoration of a running season on the menu header: a small repeating SVG pattern, no script, no images. --}}
@php
    $seasonPatterns = [
        'crescent' => '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><path d="M22 10a10 10 0 1 0 8 16 8 8 0 1 1-8-16z" fill="#F5D27A"/><path d="M48 36l1.5 3.2 3.5.5-2.5 2.4.6 3.5-3.1-1.7-3.1 1.7.6-3.5-2.5-2.4 3.5-.5z" fill="#F5D27A"/><circle cx="50" cy="12" r="1.4" fill="#fff"/><circle cx="12" cy="50" r="1.2" fill="#fff"/></svg>',
        'lantern' => '<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72"><path d="M18 4v6" stroke="#F5D27A" stroke-width="1.5"/><path d="M13 12h10l2 4-2 14H13l-2-14z" fill="none" stroke="#F5D27A" stroke-width="1.6"/><path d="M14 30h8l-1 3h-6z" fill="#F5D27A"/><path d="M52 40a8 8 0 1 0 6 13 6.5 6.5 0 1 1-6-13z" fill="#F5D27A"/><circle cx="58" cy="14" r="1.5" fill="#fff"/></svg>',
        'snow' => '<svg xmlns="http://www.w3.org/2000/svg" width="56" height="56"><circle cx="8" cy="10" r="2" fill="#fff"/><circle cx="34" cy="6" r="1.4" fill="#fff"/><circle cx="22" cy="30" r="2.4" fill="#fff"/><circle cx="48" cy="26" r="1.6" fill="#fff"/><circle cx="12" cy="46" r="1.3" fill="#fff"/><circle cx="40" cy="48" r="2" fill="#fff"/></svg>',
        'sun' => '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><circle cx="16" cy="16" r="5" fill="#FFD166"/><g stroke="#FFD166" stroke-width="1.5"><path d="M16 4v4M16 24v4M4 16h4M24 16h4M7.5 7.5l2.8 2.8M21.7 21.7l2.8 2.8M7.5 24.5l2.8-2.8M21.7 10.3l2.8-2.8"/></g><circle cx="48" cy="46" r="2" fill="#fff"/></svg>',
        'hearts' => '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><path d="M15 24s-8-5-8-10a4 4 0 0 1 8-1 4 4 0 0 1 8 1c0 5-8 10-8 10z" fill="#FF8FA3"/><path d="M45 50s-5-3.2-5-6.4a2.6 2.6 0 0 1 5-.6 2.6 2.6 0 0 1 5 .6c0 3.2-5 6.4-5 6.4z" fill="#fff"/></svg>',
        'dots' => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="8" cy="8" r="1.6" fill="#fff"/><circle cx="28" cy="24" r="1.2" fill="#fff"/></svg>',
    ];
    $seasonSvg = $seasonPatterns[$season->pattern()] ?? $seasonPatterns['dots'];
@endphp
<style>
    .m-season-deco { position: absolute; inset: 0; z-index: 0; pointer-events: none; opacity: .22;
        background-image: url("data:image/svg+xml,{{ rawurlencode($seasonSvg) }}"); background-repeat: repeat; }
    [data-season-strip] { box-shadow: inset 0 -1px 0 rgba(0, 0, 0, .08); }
    @media (prefers-reduced-motion: no-preference) {
        .m-season-deco[data-season-deco="snow"] { animation: m-season-snow 18s linear infinite; }
        @keyframes m-season-snow { from { background-position: 0 0; } to { background-position: 0 560px; } }
    }
</style>
