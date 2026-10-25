// Driver screen: the driver's own orders with big buttons (picked up / delivered with how the guest paid / did not
// deliver), refreshed every `ping` seconds and on demand, with a banner + sound + vibration when a new order is given
// to them, and the phone's position sent every `ping` seconds while an order is out for delivery — only while this
// page is visible, with the state always shown on top. The server refuses positions when nothing is out.
// Inside the EgHub driver app the app's own service keeps sending the position in the background (screen off, other
// apps open): started when an order goes out, stopped when nothing is out any more (App\Services\Orders\NativeTracking).
import { call, callJson, inApp } from './native';

const dataEl = document.getElementById('driver-data');
if (dataEl) {
    const D = JSON.parse(dataEl.textContent);
    const t = D.i18n;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const list = document.querySelector('[data-driver-orders]');
    const geoBox = document.querySelector('[data-geo]');
    const geoDot = geoBox.querySelector('[data-geo-dot]');
    const geoText = geoBox.querySelector('[data-geo-text]');
    const offline = document.querySelector('[data-offline]');
    const dialog = document.querySelector('[data-fail-dialog]');
    const deliverDialog = document.querySelector('[data-deliver-dialog]');
    const banner = document.querySelector('[data-new-banner]');
    const refreshBtn = document.querySelector('[data-refresh]');
    const refreshed = document.querySelector('[data-refreshed]');
    const soundBtn = document.querySelector('[data-sound]');
    const pingMs = Math.max(10, Number(D.ping || 20)) * 1000;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const minutesSince = (iso) => (iso ? Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000)) : null);
    const timeNow = () => new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    // the guest's WhatsApp answer on a cash-on-delivery order (confirmed, wants a change, asks to cancel, no reply)
    const CONFIRM_TONE = {
        amber: 'bg-amber-100 text-amber-900', emerald: 'bg-emerald-100 text-emerald-800', sky: 'bg-sky-100 text-sky-900',
        red: 'bg-red-600 text-white', slate: 'bg-slate-200 text-slate-800',
    };
    let orders = D.orders || [];
    let sharing = false;
    let watchId = null;
    let lastPos = null;
    let geoState = 'idle';
    let lastSent = null;

    async function api(url, body = null) {
        const res = await fetch(url, {
            method: body ? 'POST' : 'GET',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body ? JSON.stringify(body) : undefined,
            cache: 'no-store',
        });
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok, status: res.status, data };
    }

    // ---- the orders ----
    function since(o) {
        if (o.delivery_status === 'picked_up') {
            const n = minutesSince(o.picked_up_at_iso);
            return n === null ? '' : t.sincePicked.replace('__N__', n);
        }
        const n = minutesSince(o.assigned_at_iso);
        return n === null ? '' : (n < 1 ? t.sinceAssignedNow : t.sinceAssigned.replace('__N__', n));
    }

    function card(o) {
        const out = o.delivery_status === 'picked_up';
        const phone = o.phone ? `<a href="tel:${esc(o.phone)}" class="btn-navy min-h-14 flex-1 text-base" dir="ltr">📞 ${esc(o.phone)}</a>` : '';
        const nav = o.maps ? `<a href="${esc(o.maps)}" target="_blank" rel="noopener" class="btn-ghost min-h-14 flex-1 text-base">🧭 ${esc(o.pinned ? t.navigatePin : t.navigate)}</a>` : '';
        const main = out
            ? `<button class="min-h-16 w-full rounded-2xl bg-emerald-600 text-lg font-black text-white shadow active:bg-emerald-700" data-act="delivered" data-id="${o.id}">✅ ${esc(t.delivered)}</button>`
            : o.ready
                ? `<button class="btn-primary min-h-16 w-full rounded-2xl text-lg font-black" data-act="pickedUp" data-id="${o.id}">🛵 ${esc(t.pickedUp)}</button>`
                : `<div class="flex min-h-16 w-full items-center justify-center rounded-2xl bg-amber-50 text-base font-bold text-amber-800 ring-1 ring-amber-200">⏳ ${esc(t.notReady)}</div>`;
        return `
            <article class="card space-y-3 p-4 ${out ? 'ring-2 ring-emerald-500' : ''}" data-order="${o.id}">
                <header class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-3xl font-black" dir="ltr">#${o.number}</div>
                        <span class="badge ${out ? 'bg-emerald-100 text-emerald-800' : 'bg-navy-100 text-navy-700'}">${esc(o.delivery_label)}</span>
                        <div class="mt-1 text-xs font-semibold text-navy-500" data-since="${o.id}">${esc(since(o))}</div>
                    </div>
                    <div class="shrink-0 text-end">
                        <div class="text-xs font-bold text-navy-500">${esc(t.collect)}</div>
                        <div class="text-2xl font-black ${o.collect > 0 ? 'text-brand-700' : 'text-emerald-700'}">${esc(o.collect_text)} <span class="text-sm">${esc(D.currency)}</span></div>
                        <div class="text-xs font-semibold text-navy-500">${esc(o.payment)}</div>
                    </div>
                </header>
                <div>
                    <div class="text-lg font-bold">${esc(o.customer || '—')}</div>
                    ${o.confirm ? `<span class="badge mt-1 ${CONFIRM_TONE[o.confirm.tone] || CONFIRM_TONE.slate}" data-confirm-badge="${esc(o.confirm.state)}">💬 ${esc(o.confirm.label)}</span>` : ''}
                    <div class="mt-1 text-base">📍 ${esc([o.zone, o.address].filter(Boolean).join(' — '))}</div>
                    ${o.address_label ? `<div class="text-sm text-navy-500">${esc(o.address_label)}</div>` : ''}
                    ${o.notes ? `<p class="mt-2 rounded-xl bg-brand-50 p-2 text-sm font-semibold text-brand-700">${esc(t.notes)}: ${esc(o.notes)}</p>` : ''}
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer font-semibold text-navy-600">${esc(t.items.replace('__N__', o.items_count))}${o.branch ? ` · ${esc(o.branch)}` : ''}</summary>
                        <ul class="mt-1 space-y-0.5">${o.items.map((i) => `<li><b>${i.qty}×</b> ${esc(i.name)}</li>`).join('')}</ul>
                    </details>
                </div>
                ${phone || nav ? `<div class="flex flex-wrap gap-2">${phone}${nav}</div>` : ''}
                ${main}
                <button class="min-h-12 w-full rounded-2xl border border-red-200 bg-white font-bold text-red-700 active:bg-red-50" data-act="failed" data-id="${o.id}">✖ ${esc(t.failed)}</button>
            </article>`;
    }

    let lastSignature = null;
    function render() {
        const signature = JSON.stringify(orders);
        if (signature !== lastSignature) list.innerHTML = orders.length ? orders.map(card).join('') : `<p class="card py-12 text-center text-base font-semibold text-navy-500">${esc(t.empty)}</p>`;
        lastSignature = signature; // unchanged orders keep the page as it is (an opened item list stays open)
        sharing = orders.some((o) => o.delivery_status === 'picked_up');
        syncWatch();
        syncNative();
    }

    // ---- the driver app's background tracking: one delivery at a time, the next one when it is delivered ----
    const nativeApp = inApp('driver');
    let nativeFor = null;
    let nativeBusy = false;
    const nativeRunning = () => {
        const s = callJson('trackingState');
        return s === true || s === 'running' || !!(s && typeof s === 'object' && (s.running || s.tracking || s.active));
    };
    async function syncNative() {
        if (!nativeApp || nativeBusy) return;
        const out = orders.filter((o) => o.delivery_status === 'picked_up');
        if (!out.length) {
            if (nativeFor !== null || nativeRunning()) call('stopTracking');
            nativeFor = null;
            return;
        }
        if (out.some((o) => o.id === nativeFor) && nativeRunning()) return;
        nativeBusy = true;
        try {
            const r = await api(D.urls.tracking.replace('__ID__', out[0].id), {});
            if (r.ok) {
                call('startTracking', JSON.stringify(r.data));
                nativeFor = out[0].id;
            }
        } catch { /* offline: the next refresh tries again */ } finally {
            nativeBusy = false;
        }
    }

    // "with you for N min" ticks every minute without redrawing the cards
    setInterval(() => {
        orders.forEach((o) => {
            const el = list.querySelector(`[data-since="${o.id}"]`);
            if (el) el.textContent = since(o);
        });
    }, 60000);

    // ---- a new order given to this driver: banner, sound and vibration ----
    let known = new Set(orders.map((o) => o.id));
    let soundOn = true;
    try { soundOn = localStorage.getItem('egmenu.driver.sound') !== '0'; } catch {}
    let audio = null;
    const unlockAudio = () => {
        try {
            audio ??= new (window.AudioContext || window.webkitAudioContext)();
            if (audio.state === 'suspended') audio.resume();
        } catch {}
    };
    document.addEventListener('pointerdown', unlockAudio, { passive: true }); // browsers allow sound only after a tap
    function beep() {
        if (!soundOn || !audio || audio.state !== 'running') return;
        try {
            [0, 0.25, 0.5].forEach((at) => {
                const osc = audio.createOscillator();
                const gain = audio.createGain();
                osc.type = 'sine';
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.0001, audio.currentTime + at);
                gain.gain.exponentialRampToValueAtTime(0.4, audio.currentTime + at + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + at + 0.2);
                osc.connect(gain).connect(audio.destination);
                osc.start(audio.currentTime + at);
                osc.stop(audio.currentTime + at + 0.22);
            });
        } catch {}
    }
    function renderSound() {
        soundBtn.textContent = soundOn ? '🔔' : '🔕';
        soundBtn.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
        soundBtn.setAttribute('aria-label', soundOn ? t.soundOn : t.soundOff);
        soundBtn.title = soundOn ? t.soundOn : t.soundOff;
    }
    soundBtn.addEventListener('click', () => {
        soundOn = !soundOn;
        try { localStorage.setItem('egmenu.driver.sound', soundOn ? '1' : '0'); } catch {}
        renderSound();
        if (soundOn) { unlockAudio(); beep(); }
    });
    renderSound();
    function announce(fresh) {
        banner.querySelector('[data-new-text]').textContent = fresh.length === 1 ? t.newOrder.replace('__N__', fresh[0].number) : t.newOrders.replace('__N__', fresh.length);
        banner.classList.remove('hidden');
        banner.classList.add('flex');
        beep();
        try { navigator.vibrate?.([300, 150, 300, 150, 500]); } catch {}
    }
    banner.querySelector('[data-new-dismiss]').addEventListener('click', () => {
        banner.classList.add('hidden');
        banner.classList.remove('flex');
    });

    let refreshing = false;
    async function refresh() {
        if (refreshing) return;
        refreshing = true;
        refreshBtn.querySelector('[data-refresh-icon]').classList.add('animate-spin');
        try {
            const r = await api(D.urls.orders);
            if (!r.ok) throw new Error(r.status);
            orders = r.data.orders || [];
            const fresh = orders.filter((o) => !known.has(o.id));
            known = new Set(orders.map((o) => o.id));
            offline.classList.add('hidden');
            render();
            if (fresh.length) announce(fresh);
            refreshed.textContent = t.refreshed.replace('__T__', timeNow());
        } catch {
            offline.classList.remove('hidden');
        } finally {
            refreshing = false;
            refreshBtn.querySelector('[data-refresh-icon]').classList.remove('animate-spin');
        }
    }
    refreshBtn.addEventListener('click', () => { refresh(); if (sharing && lastPos) sendPosition(true); });

    async function act(action, id, body = {}) {
        const r = await api(D.urls[action].replace('__ID__', id), body).catch(() => ({ ok: false, data: {} }));
        if (!r.ok) alert(r.data.message || t.offline);
        await refresh();
        return r.ok;
    }

    list.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const id = btn.dataset.id;
        const order = orders.find((o) => String(o.id) === id);
        if (btn.dataset.act === 'failed') return openFail(id);
        if (btn.dataset.act === 'delivered') return openDeliver(order);
        if (btn.dataset.act === 'pickedUp') startWatch(); // the tap is a good moment for the browser to ask for location
        btn.disabled = true;
        await act(btn.dataset.act, id);
        btn.disabled = false; // still on the page when nothing changed
        if (btn.dataset.act === 'pickedUp') sendPosition(true);
    });

    // ---- delivered: how did the guest pay? one big button per method (a paid order only confirms) ----
    let deliverId = null;
    const methodIcons = { cash: '💵', card: '💳', instapay: '📲', wallet: '📱' };
    function openDeliver(order) {
        if (!order) return;
        deliverId = order.id;
        const amount = `${order.collect_text} ${D.currency}`;
        deliverDialog.querySelector('[data-deliver-title]').textContent = order.paid ? t.deliveredPaid : t.howPaid.replace('__A__', amount);
        deliverDialog.querySelector('[data-deliver-error]').classList.add('hidden');
        const box = deliverDialog.querySelector('[data-deliver-methods]');
        const paidBtn = deliverDialog.querySelector('[data-deliver-paid]');
        box.innerHTML = order.paid ? '' : D.methods.map((m) => `
            <button type="button" class="flex min-h-16 w-full items-center gap-3 rounded-2xl border-2 ${m === 'cash' ? 'border-emerald-600 bg-emerald-50' : 'border-navy-100 bg-white'} px-4 text-start active:bg-navy-50" data-method="${m}">
                <span class="text-2xl">${methodIcons[m] || '💰'}</span>
                <span class="min-w-0 flex-1"><span class="block text-base font-black">${esc(t.methods[m] || m)}</span><span class="block text-xs font-semibold text-navy-500">${esc(t.methodHints[m] || '')}</span></span>
            </button>`).join('');
        paidBtn.classList.toggle('hidden', !order.paid);
        deliverDialog.showModal();
    }
    async function sendDelivered(method) {
        deliverDialog.querySelectorAll('button').forEach((b) => { b.disabled = true; });
        const ok = await act('delivered', deliverId, method ? { method } : {});
        deliverDialog.querySelectorAll('button').forEach((b) => { b.disabled = false; });
        if (ok) deliverDialog.close();
    }
    deliverDialog.addEventListener('click', (e) => {
        const m = e.target.closest('[data-method]');
        if (m) return sendDelivered(m.dataset.method);
        if (e.target.closest('[data-deliver-paid]')) return sendDelivered(null);
        if (e.target.closest('[data-deliver-cancel]')) deliverDialog.close();
    });

    // ---- did not deliver: a reason, then send ----
    let failId = null;
    function openFail(id) {
        failId = id;
        dialog.querySelector('form').reset();
        dialog.querySelector('[data-fail-error]').classList.add('hidden');
        dialog.showModal();
    }
    dialog.querySelector('[data-fail-cancel]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-fail-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const preset = form.querySelector('[name="preset"]:checked')?.value || '';
        const extra = form.reason.value.trim();
        const reason = [preset, extra].filter(Boolean).join(' — ').slice(0, 200);
        if (!reason) return dialog.querySelector('[data-fail-error]').classList.remove('hidden');
        const r = await api(D.urls.failed.replace('__ID__', failId), { reason }).catch(() => ({ ok: false, data: {} }));
        if (!r.ok) return alert(r.data.message || t.offline);
        dialog.close();
        refresh();
    });

    // ---- location: watched while an order is out and the page is visible, sent every `ping` seconds ----
    function setGeo(state) {
        geoState = state;
        const colors = { idle: 'bg-navy-200', ask: 'bg-amber-400', on: 'bg-emerald-500 animate-pulse', denied: 'bg-red-500', error: 'bg-red-500', unsupported: 'bg-red-500', paused: 'bg-amber-400' };
        geoDot.className = `size-3 shrink-0 rounded-full ${colors[state] || colors.idle}`;
        const text = { idle: t.geoIdle, ask: t.geoAsk, on: t.geoOn, denied: t.geoDenied, error: t.geoError, unsupported: t.geoUnsupported, paused: t.geoPaused }[state];
        geoText.textContent = state === 'on' && lastSent ? `${text} · ${t.geoSent.replace('__T__', lastSent)}` : text;
        geoBox.classList.toggle('bg-red-50', ['denied', 'error', 'unsupported'].includes(state));
        geoBox.classList.toggle('bg-emerald-50', state === 'on');
    }

    function startWatch() {
        if (watchId !== null) return;
        if (!navigator.geolocation) return setGeo('unsupported');
        if (geoState !== 'on') setGeo('ask');
        watchId = navigator.geolocation.watchPosition((pos) => {
            const first = !lastPos;
            lastPos = pos;
            if (geoState !== 'on') setGeo('on');
            if (first) sendPosition(true);
        }, (err) => {
            setGeo(err.code === 1 ? 'denied' : 'error');
            if (err.code === 1) stopWatch();
        }, { enableHighAccuracy: true, maximumAge: 15000, timeout: 30000 });
    }

    function stopWatch() {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        watchId = null;
        lastPos = null;
    }

    function syncWatch() {
        if (!sharing) {
            stopWatch();
            if (!['denied', 'unsupported'].includes(geoState)) setGeo('idle');
            return;
        }
        if (document.hidden) {
            stopWatch();
            return setGeo('paused');
        }
        if (geoState !== 'denied') startWatch();
    }

    let lastPostAt = 0;
    async function sendPosition(force = false) {
        if (!sharing || document.hidden || !lastPos) return;
        if (!force && Date.now() - lastPostAt < pingMs - 1000) return;
        lastPostAt = Date.now();
        const { latitude: lat, longitude: lng, accuracy } = lastPos.coords;
        const r = await api(D.urls.location, { lat, lng, accuracy: Math.round(accuracy || 0) }).catch(() => ({ ok: false, status: 0, data: {} }));
        if (r.ok) {
            lastSent = timeNow();
            setGeo('on');
        } else if (r.status === 409) {
            refresh(); // nothing is out with this driver any more
        }
    }

    setInterval(() => sendPosition(), pingMs);
    setInterval(() => { if (!document.hidden) refresh(); }, pingMs);
    // the phone was locked / another app was open: back in front, the list and the location pick up at once
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            syncWatch();
            return;
        }
        refresh();
        syncWatch();
    });
    window.addEventListener('online', () => refresh());

    render();
    refreshed.textContent = t.refreshed.replace('__T__', timeNow());
}
