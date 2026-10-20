// Driver screen: the driver's own orders with big buttons (picked up / delivered / did not deliver), refreshed every
// few seconds, and the phone's position sent every `ping` seconds while an order is out for delivery — only while
// this page is visible, with the state always shown on top. The server refuses positions when nothing is out.

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
    const pingMs = Math.max(10, Number(D.ping || 20)) * 1000;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
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
    function card(o) {
        const out = o.delivery_status === 'picked_up';
        const phone = o.phone ? `<a href="tel:${esc(o.phone)}" class="btn-navy min-h-14 flex-1 text-base" dir="ltr">📞 ${esc(o.phone)}</a>` : '';
        const nav = o.maps ? `<a href="${esc(o.maps)}" target="_blank" rel="noopener" class="btn-ghost min-h-14 flex-1 text-base">🧭 ${esc(t.navigate)}</a>` : '';
        const main = out
            ? `<button class="min-h-16 w-full rounded-2xl bg-emerald-600 text-lg font-black text-white shadow active:bg-emerald-700" data-act="delivered" data-id="${o.id}">✅ ${esc(t.delivered)}</button>`
            : o.ready
                ? `<button class="btn-primary min-h-16 w-full rounded-2xl text-lg font-black" data-act="pickedUp" data-id="${o.id}">🛵 ${esc(t.pickedUp)}</button>`
                : `<div class="flex min-h-16 w-full items-center justify-center rounded-2xl bg-amber-50 text-base font-bold text-amber-800 ring-1 ring-amber-200">⏳ ${esc(t.notReady)}</div>`;
        return `
            <article class="card space-y-3 p-4 ${out ? 'ring-2 ring-emerald-500' : ''}" data-order="${o.id}">
                <header class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-3xl font-black" dir="ltr">#${o.number}</div>
                        <span class="badge ${out ? 'bg-emerald-100 text-emerald-800' : 'bg-navy-100 text-navy-700'}">${esc(o.delivery_label)}</span>
                        ${o.picked_up_at ? `<span class="text-xs text-navy-500">${esc(t.pickedAt.replace('__T__', o.picked_up_at))}</span>` : ''}
                    </div>
                    <div class="text-end">
                        <div class="text-xs font-bold text-navy-500">${esc(t.collect)}</div>
                        <div class="text-2xl font-black ${o.collect > 0 ? 'text-brand-700' : 'text-emerald-700'}">${esc(o.collect_text)} <span class="text-sm">${esc(D.currency)}</span></div>
                        <div class="text-xs font-semibold text-navy-500">${esc(o.payment)}</div>
                    </div>
                </header>
                <div>
                    <div class="text-lg font-bold">${esc(o.customer || '—')}</div>
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
    }

    async function refresh() {
        try {
            const r = await api(D.urls.orders);
            if (!r.ok) throw new Error(r.status);
            orders = r.data.orders || [];
            offline.classList.add('hidden');
            render();
        } catch {
            offline.classList.remove('hidden');
        }
    }

    list.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const id = btn.dataset.id;
        const order = orders.find((o) => String(o.id) === id);
        if (btn.dataset.act === 'failed') return openFail(id);
        if (btn.dataset.act === 'delivered' && !confirm(t.deliveredSure.replace('__A__', `${order?.collect_text ?? ''} ${D.currency}`))) return;
        if (btn.dataset.act === 'pickedUp') startWatch(); // the tap is a good moment for the browser to ask for location
        btn.disabled = true;
        const r = await api(D.urls[btn.dataset.act].replace('__ID__', id), {}).catch(() => ({ ok: false, data: {} }));
        if (!r.ok) alert(r.data.message || t.offline);
        await refresh();
        btn.disabled = false; // still on the page when nothing changed
        if (btn.dataset.act === 'pickedUp') sendPosition(true);
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
            lastSent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            setGeo('on');
        } else if (r.status === 409) {
            refresh(); // nothing is out with this driver any more
        }
    }

    setInterval(() => sendPosition(), pingMs);
    setInterval(() => { if (!document.hidden) refresh(); }, pingMs);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            syncWatch();
            return;
        }
        refresh();
    });

    render();
}
