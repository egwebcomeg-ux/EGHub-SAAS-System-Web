{{-- Styles of the table QR card (partials.stand-card) and the on-screen stand mock-up. Sizes are in cqw so one card scales from a thumbnail to A6. --}}
<style>
    @foreach (\App\Support\StandStyles::STYLES as $key => $p)
    [data-style="{{ $key }}"] { --bg1: {{ $p['bg1'] }}; --bg2: {{ $p['bg2'] }}; --ink: {{ $p['ink'] }}; --muted: {{ $p['muted'] }}; --edge: {{ $p['edge'] }}; }
    @endforeach
    .sc { container-type: inline-size; position: relative; width: 100%; aspect-ratio: 105 / 148; overflow: hidden; color: var(--ink);
        background: linear-gradient(165deg, var(--bg1), var(--bg2)); -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .sc[data-style="wood"] { background:
        repeating-linear-gradient(97deg, rgba(120, 72, 30, .07) 0 .6cqw, transparent .6cqw 2.2cqw, rgba(120, 72, 30, .05) 2.2cqw 2.6cqw, transparent 2.6cqw 5cqw),
        radial-gradient(ellipse 40cqw 9cqw at 30% 70%, rgba(120, 72, 30, .10), transparent 70%),
        linear-gradient(165deg, var(--bg1), var(--bg2)); }
    .sc__frame { position: absolute; inset: 5cqw; border: .7cqw solid var(--accent); border-radius: 4cqw; pointer-events: none; }
    .sc__in { position: absolute; inset: 8cqw; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center; gap: 1.6cqw; }
    .sc__head { display: flex; flex-direction: column; align-items: center; gap: 1.2cqw; width: 100%; }
    .sc__logo { max-height: 12cqw; max-width: 42cqw; object-fit: contain; }
    .sc[data-style="navy"] .sc__logo { background: #fff; padding: 1.2cqw; border-radius: 2cqw; }
    .sc__brand { font-size: 5.6cqw; line-height: 1.25; font-weight: 800; max-width: 100%; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .sc__headline { font-size: 6.4cqw; line-height: 1.2; font-weight: 900; color: var(--accent); }
    .sc[data-style="navy"] .sc__headline { color: #fff; }
    .sc__qr { width: 58cqw; background: #fff; padding: 3.2cqw; border-radius: 3.5cqw; border: .7cqw solid var(--accent); box-sizing: content-box; line-height: 0; }
    .sc:has(.sc__logo) .sc__qr { width: 53cqw; }
    .sc__qr svg { width: 100%; height: auto; display: block; }
    .sc__table { font-size: 8.4cqw; line-height: 1.1; font-weight: 900; }
    .sc[data-style="navy"] .sc__table { color: var(--accent); }
    .sc__lines { display: flex; flex-wrap: wrap; justify-content: center; gap: .8cqw 3cqw; font-size: 3.3cqw; line-height: 1.35; color: var(--muted); font-weight: 600; }
    .sc__line { display: inline-flex; align-items: center; gap: 1cqw; }
    .sc__line[hidden] { display: none; }
    .sc__line svg { width: 3.6cqw; height: 3.6cqw; flex-shrink: 0; color: var(--accent); }
    .sc__line--url { flex-basis: 100%; justify-content: center; font-size: 3cqw; letter-spacing: .02em; }
    .sc__sample { position: absolute; top: 9cqw; inset-inline-start: -10cqw; transform: rotate(-35deg); background: #DC2626; color: #fff; font-size: 3.6cqw; font-weight: 800; padding: .6cqw 12cqw; z-index: 2; }
    [dir="rtl"] .sc__sample { transform: rotate(35deg); }

    /* the stand mock-up around a card (screen only) */
    .stand { position: relative; padding: 18px 8% 0; perspective: 900px; }
    .stand__card { position: relative; border-radius: 10px; overflow: hidden; transform: rotateX(5deg); transform-origin: 50% 100%;
        box-shadow: 0 0 0 3px rgba(255, 255, 255, .55), 0 0 0 4px var(--edge), 0 22px 30px -18px rgba(15, 30, 60, .55); }
    .stand__card::after { content: ""; position: absolute; inset: 0; pointer-events: none;
        background: linear-gradient(118deg, rgba(255, 255, 255, .55) 0 12%, rgba(255, 255, 255, 0) 30%, rgba(255, 255, 255, 0) 72%, rgba(255, 255, 255, .18) 86%, rgba(255, 255, 255, 0) 100%); }
    .stand[data-style="wood"] .stand__card { box-shadow: 0 0 0 4px #8c6239, 0 22px 30px -18px rgba(60, 30, 10, .6); }
    .stand[data-style="wood"] .stand__card::after { background: linear-gradient(118deg, rgba(255, 255, 255, .18), rgba(255, 255, 255, 0) 40%); }
    .stand[data-style="frosted"] .stand__card .sc { background: linear-gradient(165deg, rgba(255, 255, 255, .78), rgba(220, 232, 241, .62)); backdrop-filter: blur(6px); }
    .stand__base { position: relative; height: 26px; margin: -2px -4% 0; border-radius: 6px 6px 10px 10px;
        background: linear-gradient(180deg, #ffffff, var(--bg2) 55%, var(--edge)); box-shadow: 0 14px 18px -10px rgba(15, 30, 60, .45); }
    .stand[data-style="navy"] .stand__base { background: linear-gradient(180deg, #3a5c95, var(--bg2) 60%, #071430); }
    .stand[data-style="wood"] .stand__base { height: 30px; background: repeating-linear-gradient(92deg, rgba(90, 50, 20, .12) 0 2px, transparent 2px 9px), linear-gradient(180deg, #d9b48a, #a87b4f 70%, #7d5532); }
    .stand[data-style="frosted"] .stand__base { background: linear-gradient(180deg, rgba(255, 255, 255, .9), rgba(205, 220, 232, .7)); }
    .stand-scene { border-radius: 18px; padding: 8px 24px 28px; background:
        radial-gradient(circle at 18% 22%, rgba(232, 100, 44, .22), transparent 38%),
        radial-gradient(circle at 82% 30%, rgba(30, 45, 79, .18), transparent 40%),
        linear-gradient(180deg, #f3ede4 0 70%, #e2d3bf 70% 100%); }
</style>
