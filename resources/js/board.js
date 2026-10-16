// Live order board: polls the feed every few seconds, rebuilds the columns, chimes when a new order arrives and
// keeps ringing while an online / QR order waits to be accepted (moved to "preparing").

const board = document.getElementById('board');
if (board) {
    const t = JSON.parse(board.dataset.i18n);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const canManage = board.dataset.canManage === '1';
    const pollMs = Number(board.dataset.poll || 5) * 1000;
    const baseTitle = document.title.replace(/^\(\d+\) .*? · /, '').replace(/^\(\d+\) /, '');
    let lastMaxId = null;
    let lastCallId = null;
    let soundOn = false;
    try { soundOn = localStorage.getItem('egmenu.sound') === '1'; } catch {}
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

    // Browsers only let a page play sound after the first user gesture. Until then a hint asks for one tap.
    let unlocked = false;
    const hint = document.querySelector('[data-audio-hint]');
    hint?.classList.remove('hidden');
    hint?.classList.add('flex');
    function unlock() {
        if (unlocked) return;
        try {
            audio ??= new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') audio.resume();
            unlocked = true;
            hint?.classList.add('hidden');
            hint?.classList.remove('flex');
        } catch {}
    }
    ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, unlock, { passive: true }));

    function tone(freq, at, length, volume = 0.25) {
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(volume, at);
        gain.gain.exponentialRampToValueAtTime(0.001, at + length);
        osc.connect(gain).connect(audio.destination);
        osc.start(at);
        osc.stop(at + length + 0.05);
    }

    // Two short tones with Web Audio (no sound file to load).
    function chime() {
        if (!soundOn) return;
        try {
            audio ??= new (window.AudioContext || window.webkitAudioContext)();
            tone(784, audio.currentTime, 0.35);
            tone(1046, audio.currentTime + 0.18, 0.35);
        } catch {}
    }

    // ---- new-order alarm: rings every few seconds while a guest order (QR / online) is still "new" ----
    const GUEST_SOURCES = ['qr', 'online', 'whatsapp'];
    const ALARM_EVERY_MS = 4000;
    let alarmMuted = false;
    try { alarmMuted = localStorage.getItem('egmenu.alarm.muted') === '1'; } catch {}
    let waitingGuestOrders = 0;
    const alarmBtn = document.querySelector('[data-alarm-mute]');
    function renderAlarmBtn() {
        if (!alarmBtn) return;
        alarmBtn.textContent = (alarmMuted ? '🔕 ' : '🚨 ') + (alarmMuted ? t.alarmOff : t.alarmOn);
        alarmBtn.classList.toggle('text-navy-500', alarmMuted);
        alarmBtn.classList.toggle('line-through', alarmMuted);
    }
    alarmBtn?.addEventListener('click', () => {
        alarmMuted = !alarmMuted;
        try { localStorage.setItem('egmenu.alarm.muted', alarmMuted ? '1' : '0'); } catch {}
        renderAlarmBtn();
    });
    renderAlarmBtn();

    function alarm() {
        if (alarmMuted || !unlocked || waitingGuestOrders === 0) return;
        try {
            const now = audio.currentTime;
            [0, 0.22, 0.44].forEach((d, i) => tone(i % 2 ? 660 : 880, now + d, 0.2, 0.3));
        } catch {}
    }
    setInterval(alarm, ALARM_EVERY_MS);

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    function minutesAgo(iso) {
        return Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    }

    function card(o) {
        const mins = minutesAgo(o.created_at);
        // Same thresholds idea as the Filetto kitchen screen: amber after 10 minutes, red after 20.
        const age = ['served', 'cancelled'].includes(o.status) ? '' : mins >= 20 ? 'ring-2 ring-red-400' : mins >= 10 ? 'ring-2 ring-amber-300' : '';
        const waiting = o.status === 'new' && GUEST_SOURCES.includes(o.source) ? 'animate-pulse' : '';
        const where = o.table ? t.table.replace('__N__', esc(o.table)) : esc(o.type_label);
        const items = o.items.map((i) => `
            <li class="flex gap-2"><b>${i.qty}×</b><span>${esc(i.name)}${i.options ? `<span class="block text-xs text-navy-500">${esc(i.options)}</span>` : ''}${i.notes ? `<span class="block text-xs text-brand-700">${esc(i.notes)}</span>` : ''}</span></li>`).join('');
        const buttons = canManage ? o.next.filter((s) => s !== 'cancelled').map((s) => `
            <button class="btn-primary min-h-10 flex-1" data-move="${o.id}" data-to="${s}">${esc(t[s])}</button>`).join('')
            + (o.next.includes('cancelled') ? `<button class="btn-ghost min-h-10 px-3 text-red-600" data-move="${o.id}" data-to="cancelled">${esc(t.cancel)}</button>` : '') : '';
        return `
            <article class="rounded-xl border border-navy-100 bg-navy-50/40 p-3 ${age} ${waiting}">
                <header class="mb-2 flex items-start justify-between gap-2">
                    <div><div class="text-xl font-extrabold">#${o.number}</div><div class="text-sm font-semibold text-brand-700">${where}</div></div>
                    <div class="text-end text-xs text-navy-500"><div>${esc(o.time)}</div><div>${mins}′</div>${o.branch ? `<div>${esc(o.branch)}</div>` : ''}</div>
                </header>
                <ul class="space-y-1 text-sm">${items}</ul>
                ${o.notes ? `<p class="mt-2 rounded-lg bg-brand-50 p-2 text-sm text-brand-700">${esc(o.notes)}</p>` : ''}
                ${o.customer ? `<p class="mt-1 text-xs text-navy-500">${esc(o.customer)}</p>` : ''}
                ${o.address ? `<p class="mt-1 text-xs font-semibold text-navy-700">📍 ${esc(o.address)}</p>` : ''}
                ${o.scheduled ? `<p class="mt-1 rounded-lg bg-amber-50 p-1.5 text-xs font-bold text-amber-800">🕒 ${esc(o.scheduled)}</p>` : ''}
                <div class="mt-2 text-sm font-bold">${esc(o.total)} ${esc(t.currency)}</div>
                ${buttons ? `<div class="mt-3 flex gap-2">${buttons}</div>` : ''}
                ${['served', 'cancelled'].includes(o.status) ? `<div class="mt-2 text-xs font-bold text-navy-500">${esc(o.status_label)}${o.reason ? ` · <span class="font-normal">${esc(o.reason)}</span>` : ''}</div>` : ''}
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
        waitingGuestOrders = cols.new.filter((o) => GUEST_SOURCES.includes(o.source)).length;
        board.querySelector('[data-section="new"]')?.classList.toggle('ring-red-400', waitingGuestOrders > 0);
        board.querySelector('[data-section="new"]')?.classList.toggle('ring-2', waitingGuestOrders > 0);
        document.title = waiting ? `${t.newOrdersTitle.replace('__N__', waiting)} · ${baseTitle}` : baseTitle;
    }

    // ---- busy mode (pauses online / takeaway / delivery ordering) ----
    const busyBox = document.querySelector('[data-busy-box]');
    function renderBusy(busy) {
        if (!busyBox) return;
        busyBox.querySelector('[data-busy-icon]').textContent = busy ? '🔴' : '🟢';
        busyBox.querySelector('[data-busy-label]').textContent = busy ? t.busyUntil.replace('__T__', busy.until) : t.busyOff;
        busyBox.classList.toggle('bg-red-50', !!busy);
        busyBox.classList.toggle('ring-1', !!busy);
        busyBox.classList.toggle('ring-red-200', !!busy);
        busyBox.querySelector('[data-busy-presets]')?.classList.toggle('hidden', !!busy);
        busyBox.querySelector('[data-busy="off"]')?.classList.toggle('hidden', !busy);
        const msg = busyBox.querySelector('[data-busy-message]');
        if (msg) {
            msg.classList.toggle('hidden', !!busy);
            if (busy) msg.value = '';
        }
    }
    busyBox?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-busy]');
        if (!btn) return;
        const msgInput = busyBox.querySelector('[data-busy-message]');
        let body = { mode: btn.dataset.busy, message: msgInput?.value || '' };
        if (btn.dataset.busy === 'minutes') body.minutes = Number(btn.dataset.value);
        if (btn.dataset.busy === 'time') body.time = btn.dataset.value;
        if (btn.dataset.busy === 'custom') {
            msgInput?.classList.remove('hidden');
            const time = prompt(t.customTime, '23:00');
            if (!time || !/^\d{2}:\d{2}$/.test(time.trim())) return;
            body = { mode: 'time', time: time.trim(), message: msgInput?.value || '' };
        }
        btn.disabled = true;
        try {
            const res = await fetch(busyBox.dataset.busyUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) alert(data.message || res.status);
            else renderBusy(data.busy);
        } finally {
            btn.disabled = false;
        }
    });
    try { renderBusy(JSON.parse(board.dataset.busy || 'null')); } catch {}

    // ---- waiter calls and bill requests from the tables ----
    const callsBox = document.querySelector('[data-calls]');
    function renderCalls(calls) {
        if (!callsBox) return;
        const newest = calls.reduce((m, c) => Math.max(m, c.id), 0);
        if (lastCallId !== null && newest > lastCallId) chime();
        lastCallId = Math.max(lastCallId ?? 0, newest);
        callsBox.classList.toggle('hidden', !calls.length);
        callsBox.classList.toggle('flex', calls.length > 0);
        callsBox.innerHTML = calls.map((c) => `
            <div class="flex items-center gap-3 rounded-2xl px-4 py-2 font-bold text-white shadow ${c.kind === 'bill' ? 'bg-emerald-600' : 'bg-brand-600'} animate-pulse">
                <span>${c.kind === 'bill' ? '🧾' : '🛎'} ${esc(c.label)} · ${esc(t.table.replace('__N__', c.table ?? ''))}</span>
                <span class="text-xs font-normal text-white/80">${esc(t.minutes.replace('__N__', c.minutes))}</span>
                ${canManage ? `<button class="rounded-lg bg-white/20 px-3 py-1 text-sm" data-call-done="${c.id}">${esc(t.handled)}</button>` : ''}
            </div>`).join('');
    }
    callsBox?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-call-done]');
        if (!btn) return;
        btn.disabled = true;
        await fetch(board.dataset.callUrl.replace('__ID__', btn.dataset.callDone), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
        btn.closest('div').remove();
    });

    // ---- table bookings waiting for confirmation (reservations) ----
    const bookingsBox = document.querySelector('[data-reservations]');
    let lastBookingId = null;
    function renderReservations(list) {
        if (!bookingsBox) return;
        const newest = list.reduce((m, r) => Math.max(m, r.id), 0);
        if (lastBookingId !== null && newest > lastBookingId) chime();
        lastBookingId = Math.max(lastBookingId ?? 0, newest);
        bookingsBox.classList.toggle('hidden', !list.length);
        bookingsBox.classList.toggle('flex', list.length > 0);
        const btn = (r, to, label, cls) => `<button class="rounded-lg px-3 py-1 text-sm ${cls}" data-booking="${r.id}" data-to="${to}">${esc(label)}</button>`;
        bookingsBox.innerHTML = list.map((r) => `
            <div class="flex flex-wrap items-center gap-3 rounded-2xl bg-violet-600 px-4 py-2 font-bold text-white shadow">
                <span>📅 ${esc(t.booking)} · <span dir="ltr">${esc(r.when)}</span> ·${esc(t.guests.replace('__N__', r.party))} · ${esc(r.name)}</span>
                <a href="tel:${esc(r.phone)}" dir="ltr" class="text-xs font-normal text-white/80">${esc(r.phone)}</a>
                ${r.notes ? `<span class="text-xs font-normal text-white/80">${esc(r.notes)}</span>` : ''}
                <span class="flex gap-1">${btn(r, 'confirmed', t.confirm, 'bg-white text-violet-700')}${btn(r, 'seated', t.seat, 'bg-white/20')}${btn(r, 'no_show', t.noShow, 'bg-white/20')}${btn(r, 'cancelled', t.cancel, 'bg-white/20')}</span>
            </div>`).join('');
    }
    bookingsBox?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-booking]');
        if (!btn) return;
        if (['cancelled', 'no_show'].includes(btn.dataset.to) && !confirm(t.sure)) return;
        btn.disabled = true;
        const res = await fetch(board.dataset.reservationUrl.replace('__ID__', btn.dataset.booking), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ status: btn.dataset.to }),
        });
        if (!res.ok) alert((await res.json().catch(() => ({}))).message || res.status);
        btn.closest('[data-reservations] > div')?.remove();
        bookingsBox.classList.toggle('hidden', !bookingsBox.children.length);
    });

    async function poll() {
        try {
            const res = await fetch(board.dataset.feed, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            if (lastMaxId !== null && data.max_id > lastMaxId) chime();
            lastMaxId = data.max_id;
            render(data.orders);
            renderBusy(data.busy || null);
            renderCalls(data.calls || []);
            renderReservations(data.reservations || []);
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
