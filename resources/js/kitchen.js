// Kitchen display: polls the feed, oldest ticket first; green → amber after 10 min → red after 20 (Filetto rule).
// "Start" moves a new ticket to preparing, "Ready" bumps it (only this station's lines when a station is chosen).

import { bindPager, pagerButtons, pagerStatus } from './pager-call';

const kds = document.getElementById('kds');
if (kds) {
    const T = JSON.parse(kds.dataset.i18n);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    // the guest's WhatsApp answer on a cash-on-delivery order: a thin strip, the kitchen never waits for it
    const CONFIRM_TONE = {
        amber: 'bg-amber-100 text-amber-900', emerald: 'bg-emerald-100 text-emerald-800', sky: 'bg-sky-100 text-sky-900',
        red: 'bg-red-600 text-white', slate: 'bg-slate-200 text-slate-800',
    };
    let lastMax = null;
    let tickets = [];
    let lastTickets = null; // the last feed drawn, to skip redraws when nothing changed
    let soundOn = false;
    try { soundOn = localStorage.getItem('egmenu.kds.sound') === '1'; } catch {}
    let audio = null;
    let wakeLock = null;

    function chime() {
        if (!soundOn) return;
        try {
            audio ??= new AudioContext();
            [0, 0.2, 0.4].forEach((d) => {
                const o = audio.createOscillator(); const g = audio.createGain();
                o.frequency.value = 880; g.gain.setValueAtTime(0.3, audio.currentTime + d);
                g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + d + 0.18);
                o.connect(g).connect(audio.destination); o.start(audio.currentTime + d); o.stop(audio.currentTime + d + 0.2);
            });
        } catch {}
    }

    const minutes = (iso) => Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));

    // ---- quick filters: an order type, or only the food / only the drinks lines (the bar follows "drinks") ----
    const KINDS = ['food', 'drinks'];
    const ICON = { food: '🍽', drinks: '☕' };
    let filter = 'all';
    try { filter = localStorage.getItem('egmenu.kds.filter') || 'all'; } catch {}
    const pending = (t, kind) => t.items.some((i) => !i.done && (!kind || i.kind === kind));

    // what this screen shows of a ticket under the filter (null = nothing to do here)
    function view(t, f) {
        if (['dine_in', 'takeaway', 'delivery'].includes(f) && t.type !== f) return null;
        if (KINDS.includes(f)) {
            const items = t.items.filter((i) => i.kind === f);
            return items.length && items.some((i) => !i.done) ? { ...t, items, only: f } : null;
        }
        return t;
    }

    const chips = document.querySelector('[data-chips]');
    function renderChips() {
        chips?.querySelectorAll('[data-chip]').forEach((c) => {
            c.setAttribute('aria-pressed', c.dataset.chip === filter ? 'true' : 'false');
            c.querySelector('[data-chip-count]').textContent = tickets.filter((t) => view(t, c.dataset.chip)).length;
        });
    }
    chips?.addEventListener('click', (e) => {
        const c = e.target.closest('[data-chip]');
        if (!c) return;
        filter = c.dataset.chip;
        try { localStorage.setItem('egmenu.kds.filter', filter); } catch {}
        render();
    });

    // one "ready", or "food ready" + "drinks ready" when the ticket shows both kinds
    function readyButtons(t) {
        const kinds = KINDS.filter((k) => t.items.some((i) => i.kind === k));
        if (kinds.length > 1) {
            return kinds.map((k) => pending(t, k)
                ? `<button class="min-h-14 rounded-xl bg-emerald-600 text-lg font-bold text-white" data-bump="${t.id}" data-action="ready" data-kind="${k}">${ICON[k]} ${esc(k === 'drinks' ? T.drinksReady : T.foodReady)}</button>`
                : `<span class="grid min-h-14 place-items-center rounded-xl bg-emerald-50 text-lg font-bold text-emerald-700">${ICON[k]} ✓</span>`).join('');
        }
        return `<button class="min-h-14 rounded-xl bg-emerald-600 text-lg font-bold text-white" data-bump="${t.id}" data-action="ready" data-kind="${t.only || ''}">${esc(T.ready)} ✓</button>`;
    }

    // a finger on the screen: the redraw waits, so the button under it is still the one tapped
    let pressing = false;
    let redrawLater = false;
    kds.addEventListener('pointerdown', () => { pressing = true; });
    const release = () => {
        pressing = false;
        if (redrawLater) {
            redrawLater = false;
            setTimeout(render, 0);
        }
    };
    window.addEventListener('pointerup', release);
    window.addEventListener('pointercancel', release);

    function render() {
        if (pressing) {
            redrawLater = true;
            return;
        }
        renderChips();
        const shown = tickets.map((t) => view(t, filter)).filter(Boolean);
        document.querySelector('[data-count]').textContent = shown.length;
        if (!shown.length) {
            kds.innerHTML = `<p class="col-span-full py-24 text-center text-2xl text-white/40">${esc(T.empty)}</p>`;
            return;
        }
        kds.innerHTML = shown.map((t) => {
            const split = KINDS.filter((k) => t.items.some((i) => i.kind === k)).length > 1;
            const m = minutes(t.since);
            const tone = m >= 20 ? 'bg-brand-600 motion-safe:animate-pulse' : m >= 10 ? 'bg-amber-500' : t.status === 'new' ? 'bg-sky-600' : 'bg-emerald-600';
            const where = t.table ? T.table.replace('__N__', t.table) : t.type_label;
            return `<article class="flex flex-col overflow-hidden rounded-2xl bg-white text-navy-900">
                <header class="${tone} flex items-center justify-between px-4 py-2 text-white">
                    <span class="text-2xl font-extrabold">#${t.number}</span>
                    <span class="text-sm font-bold">${esc(where)}</span>
                    <span class="text-xl font-extrabold tabular-nums">${m}′</span>
                </header>
                ${t.scheduled ? `<div class="bg-violet-100 px-4 py-1 text-sm font-bold text-violet-800">${esc(T.scheduled.replace('__T__', new Date(t.scheduled).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })))}</div>` : ''}
                ${t.confirm ? `<div class="px-4 py-0.5 text-xs font-bold ${CONFIRM_TONE[t.confirm.tone] || CONFIRM_TONE.slate}" data-confirm-badge="${esc(t.confirm.state)}">💬 ${esc(t.confirm.label)}</div>` : ''}
                <ul class="flex-1 space-y-2 p-4 text-lg">${t.items.map((i) => `
                    <li class="${i.done ? 'line-through opacity-40' : ''}">${split ? `<span class="me-1">${ICON[i.kind] || ''}</span>` : ''}<b>${i.qty}×</b> ${esc(i.name)}
                        ${i.options ? `<div class="text-sm text-navy-500">${esc(i.options)}</div>` : ''}
                        ${i.notes ? `<div class="text-sm font-bold text-amber-700">⚠ ${esc(i.notes)}</div>` : ''}</li>`).join('')}</ul>
                ${t.notes ? `<p class="mx-4 mb-3 rounded-lg bg-amber-100 p-2 text-sm font-bold text-amber-900">⚠ ${esc(t.notes)}</p>` : ''}
                ${t.status === 'new' ? `<div class="px-3 pb-2"><button class="min-h-12 w-full rounded-xl bg-sky-100 text-lg font-bold text-sky-900" data-bump="${t.id}" data-action="start">${esc(T.start)}</button></div>` : ''}
                <div class="grid ${split ? 'grid-cols-2' : 'grid-cols-1'} gap-2 p-3 pt-0">${readyButtons(t)}</div>
                ${t.pager ? `<div class="px-3 pb-3">${t.pager.seen ? pagerStatus(t.pager, T.pager) : ''}${pagerButtons(t.pager.seen ? t.pager : { ...t.pager, call: null }, T.pager, t.id)}</div>` : ''}
            </article>`;
        }).join('');
    }

    async function load() {
        const res = await fetch(kds.dataset.feed, { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (lastMax !== null && data.max_id > lastMax) chime();
        lastMax = data.max_id;
        // only what changed is drawn again (the minutes move with the 30-second redraw)
        const fresh = JSON.stringify(data.tickets);
        if (fresh === lastTickets) return;
        lastTickets = fresh;
        tickets = data.tickets;
        render();
    }

    async function poll() {
        try { await load(); } catch {}
        setTimeout(poll, 4000);
    }

    bindPager(kds, () => load().catch(() => {}));

    kds.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-bump]');
        if (!b) return;
        b.disabled = true;
        try {
            const res = await fetch(kds.dataset.bump.replace('__ID__', b.dataset.bump), {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ action: b.dataset.action, kind: b.dataset.kind || null }),
            });
            // a refused tap is said, not silently dropped (the order was cancelled, the connection broke...)
            if (!res.ok) alert((await res.json().catch(() => ({}))).message || T.failed);
        } catch {
            alert(T.failed);
        }
        lastTickets = null;
        try { await load(); } catch { render(); }
    });

    document.querySelector('[data-sound]').addEventListener('click', (e) => {
        soundOn = !soundOn;
        try { localStorage.setItem('egmenu.kds.sound', soundOn ? '1' : '0'); } catch {}
        e.currentTarget.classList.toggle('ring-2', soundOn);
        chime();
    });
    document.querySelector('[data-sound]').classList.toggle('ring-2', soundOn);
    document.querySelector('[data-fullscreen]').addEventListener('click', async () => {
        try {
            await document.documentElement.requestFullscreen();
            wakeLock = await navigator.wakeLock?.request('screen'); // keep the kitchen screen awake
        } catch {}
    });
    setInterval(render, 30000); // timers move even when nothing changes
    poll();
}
