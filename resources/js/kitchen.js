// Kitchen display: polls the feed, oldest ticket first; green → amber after 10 min → red after 20 (Filetto rule).
// "Start" moves a new ticket to preparing, "Ready" bumps it (only this station's lines when a station is chosen).

const kds = document.getElementById('kds');
if (kds) {
    const T = JSON.parse(kds.dataset.i18n);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    let lastMax = null;
    let tickets = [];
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

    function render() {
        document.querySelector('[data-count]').textContent = tickets.length;
        if (!tickets.length) {
            kds.innerHTML = `<p class="col-span-full py-24 text-center text-2xl text-white/40">${esc(T.empty)}</p>`;
            return;
        }
        kds.innerHTML = tickets.map((t) => {
            const m = minutes(t.since);
            const tone = m >= 20 ? 'bg-red-600 animate-pulse' : m >= 10 ? 'bg-amber-500' : t.status === 'new' ? 'bg-sky-600' : 'bg-emerald-600';
            const where = t.table ? T.table.replace('__N__', t.table) : t.type_label;
            return `<article class="flex flex-col overflow-hidden rounded-2xl bg-white text-navy-900">
                <header class="${tone} flex items-center justify-between px-4 py-2 text-white">
                    <span class="text-2xl font-extrabold">#${t.number}</span>
                    <span class="text-sm font-bold">${esc(where)}</span>
                    <span class="text-xl font-extrabold tabular-nums">${m}′</span>
                </header>
                ${t.scheduled ? `<div class="bg-violet-100 px-4 py-1 text-sm font-bold text-violet-800">${esc(T.scheduled.replace('__T__', new Date(t.scheduled).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })))}</div>` : ''}
                <ul class="flex-1 space-y-2 p-4 text-lg">${t.items.map((i) => `
                    <li class="${i.done ? 'line-through opacity-40' : ''}"><b>${i.qty}×</b> ${esc(i.name)}
                        ${i.options ? `<div class="text-sm text-navy-500">${esc(i.options)}</div>` : ''}
                        ${i.notes ? `<div class="text-sm font-bold text-red-600">⚠ ${esc(i.notes)}</div>` : ''}</li>`).join('')}</ul>
                ${t.notes ? `<p class="mx-4 mb-3 rounded-lg bg-amber-100 p-2 text-sm font-bold text-amber-900">⚠ ${esc(t.notes)}</p>` : ''}
                <div class="grid ${t.status === 'new' ? 'grid-cols-2' : 'grid-cols-1'} gap-2 p-3 pt-0">
                    ${t.status === 'new' ? `<button class="min-h-14 rounded-xl bg-sky-100 text-lg font-bold text-sky-900" data-bump="${t.id}" data-action="start">${esc(T.start)}</button>` : ''}
                    <button class="min-h-14 rounded-xl bg-emerald-600 text-lg font-bold text-white" data-bump="${t.id}" data-action="ready">${esc(T.ready)} ✓</button>
                </div>
            </article>`;
        }).join('');
    }

    async function poll() {
        try {
            const res = await fetch(kds.dataset.feed, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (lastMax !== null && data.max_id > lastMax) chime();
            lastMax = data.max_id;
            tickets = data.tickets;
            render();
        } catch {}
        setTimeout(poll, 4000);
    }

    kds.addEventListener('click', async (e) => {
        const b = e.target.closest('[data-bump]');
        if (!b) return;
        b.disabled = true;
        await fetch(kds.dataset.bump.replace('__ID__', b.dataset.bump), {
            method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ action: b.dataset.action }),
        });
        if (b.dataset.action === 'ready') tickets = tickets.filter((t) => String(t.id) !== b.dataset.bump);
        render();
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
