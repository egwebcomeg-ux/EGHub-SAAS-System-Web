// Live order board: polls the feed every few seconds, rebuilds the columns and chimes when a new order arrives.

const board = document.getElementById('board');
if (board) {
    const t = JSON.parse(board.dataset.i18n);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const canManage = board.dataset.canManage === '1';
    const pollMs = Number(board.dataset.poll || 5) * 1000;
    let lastMaxId = null;
    let soundOn = localStorage.getItem('egmenu.sound') === '1';
    let audio = null;

    const soundBtn = document.querySelector('[data-sound]');
    const renderSoundBtn = () => soundBtn?.classList.toggle('ring-2', soundOn);
    soundBtn?.addEventListener('click', () => {
        soundOn = !soundOn;
        try { localStorage.setItem('egmenu.sound', soundOn ? '1' : '0'); } catch {}
        renderSoundBtn();
        if (soundOn) chime();
    });
    renderSoundBtn();

    // Two short tones with Web Audio (no sound file to load). Browsers need one click first to allow audio.
    function chime() {
        if (!soundOn) return;
        try {
            audio ??= new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.18].forEach((delay, i) => {
                const osc = audio.createOscillator();
                const gain = audio.createGain();
                osc.frequency.value = i ? 1046 : 784;
                gain.gain.setValueAtTime(0.25, audio.currentTime + delay);
                gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + delay + 0.35);
                osc.connect(gain).connect(audio.destination);
                osc.start(audio.currentTime + delay);
                osc.stop(audio.currentTime + delay + 0.4);
            });
        } catch {}
    }

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    function minutesAgo(iso) {
        return Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    }

    function card(o) {
        const mins = minutesAgo(o.created_at);
        // Same thresholds idea as the Filetto kitchen screen: amber after 10 minutes, red after 20.
        const age = ['served', 'cancelled'].includes(o.status) ? '' : mins >= 20 ? 'ring-2 ring-red-400' : mins >= 10 ? 'ring-2 ring-amber-300' : '';
        const where = o.table ? t.table.replace('__N__', esc(o.table)) : esc(o.type_label);
        const items = o.items.map((i) => `
            <li class="flex gap-2"><b>${i.qty}×</b><span>${esc(i.name)}${i.options ? `<span class="block text-xs text-navy-500">${esc(i.options)}</span>` : ''}${i.notes ? `<span class="block text-xs text-brand-700">${esc(i.notes)}</span>` : ''}</span></li>`).join('');
        const buttons = canManage ? o.next.filter((s) => s !== 'cancelled').map((s) => `
            <button class="btn-primary min-h-10 flex-1" data-move="${o.id}" data-to="${s}">${esc(t[s])}</button>`).join('')
            + (o.next.includes('cancelled') ? `<button class="btn-ghost min-h-10 px-3 text-red-600" data-move="${o.id}" data-to="cancelled">${esc(t.cancel)}</button>` : '') : '';
        return `
            <article class="rounded-xl border border-navy-100 bg-navy-50/40 p-3 ${age}">
                <header class="mb-2 flex items-start justify-between gap-2">
                    <div><div class="text-xl font-extrabold">#${o.number}</div><div class="text-sm font-semibold text-brand-700">${where}</div></div>
                    <div class="text-end text-xs text-navy-500"><div>${esc(o.time)}</div><div>${mins}′</div>${o.branch ? `<div>${esc(o.branch)}</div>` : ''}</div>
                </header>
                <ul class="space-y-1 text-sm">${items}</ul>
                ${o.notes ? `<p class="mt-2 rounded-lg bg-brand-50 p-2 text-sm text-brand-700">${esc(o.notes)}</p>` : ''}
                ${o.customer ? `<p class="mt-1 text-xs text-navy-500">${esc(o.customer)}</p>` : ''}
                ${o.address ? `<p class="mt-1 text-xs font-semibold text-navy-700">📍 ${esc(o.address)}</p>` : ''}
                <div class="mt-2 text-sm font-bold">${esc(o.total)} ${esc(t.currency)}</div>
                ${buttons ? `<div class="mt-3 flex gap-2">${buttons}</div>` : ''}
                ${['served', 'cancelled'].includes(o.status) ? `<div class="mt-2 text-xs font-bold text-navy-500">${esc(o.status_label)}</div>` : ''}
            </article>`;
    }

    function render(orders) {
        const cols = { new: [], preparing: [], ready: [], done: [] };
        orders.forEach((o) => {
            const key = o.status === 'on_way' ? 'ready' : ['served', 'cancelled'].includes(o.status) ? 'done' : o.status;
            cols[key]?.push(o);
        });
        cols.done.reverse();
        Object.entries(cols).forEach(([key, list]) => {
            const el = board.parentElement.querySelector(`[data-column="${key}"]`);
            if (el) el.innerHTML = list.length ? list.map(card).join('') : `<p class="px-1 py-6 text-center text-sm text-navy-500">${esc(t.empty)}</p>`;
            const count = board.querySelector(`[data-count="${key}"]`);
            if (count) count.textContent = list.length;
        });
        const waiting = cols.new.length;
        document.title = (waiting ? `(${waiting}) ` : '') + document.title.replace(/^\(\d+\) /, '');
    }

    async function poll() {
        try {
            const res = await fetch(board.dataset.feed, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            if (lastMaxId !== null && data.max_id > lastMaxId) chime();
            lastMaxId = data.max_id;
            render(data.orders);
            document.querySelector('[data-live-dot]')?.classList.replace('bg-red-500', 'bg-emerald-500');
        } catch {
            document.querySelector('[data-live-dot]')?.classList.replace('bg-emerald-500', 'bg-red-500');
        } finally {
            setTimeout(poll, document.hidden ? pollMs * 3 : pollMs);
        }
    }

    board.parentElement.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-move]');
        if (!btn) return;
        let reason = null;
        if (btn.dataset.to === 'cancelled') {
            reason = prompt(t.cancelReason);
            if (reason === null) return;
        }
        btn.disabled = true;
        const res = await fetch(board.dataset.statusUrl.replace('__ID__', btn.dataset.move), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ status: btn.dataset.to, reason }),
        });
        if (!res.ok) {
            const body = await res.json().catch(() => ({}));
            alert(body.message || res.status);
        }
        lastMaxId = lastMaxId ?? 0;
        const data = await (await fetch(board.dataset.feed, { headers: { Accept: 'application/json' } })).json();
        render(data.orders);
    });

    poll();
}
