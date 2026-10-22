// Drivers page: a live map (Leaflet from the CDN, window.L) and list of the drivers with their orders, last seen
// time, today's numbers and the cash they hold, the delivery orders waiting for a driver, and the stored trail of a
// delivered order drawn on the same map. Polls the feed every `poll` seconds.

const dataEl = document.getElementById('drivers-data');
if (dataEl) {
    const D = JSON.parse(dataEl.textContent);
    const t = D.i18n;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const driversBox = document.querySelector('[data-drivers]');
    const waitingBox = document.querySelector('[data-waiting]');
    const trailPick = document.querySelector('[data-trail-pick]');
    const trailClear = document.querySelector('[data-trail-clear]');
    const trailInfo = document.querySelector('[data-trail-info]');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const minutesSince = (iso) => (iso ? Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000)) : null);
    let drivers = [];
    let recent = [];

    let map = null;
    const markers = {};
    let fitted = false;
    if (window.L && document.getElementById('drivers-map')) {
        map = L.map('drivers-map').setView(D.center || [30.0444, 31.2357], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    }

    function renderMap() {
        if (!map) return;
        const seen = new Set();
        const points = [];
        drivers.forEach((d) => {
            if (!d.location) return;
            const pos = [d.location.lat, d.location.lng];
            const stale = d.location.minutes > 10;
            const label = `${esc(d.name)} · ${esc(d.location.time)}${d.orders.length ? ' · ' + d.orders.map((o) => '#' + o.number).join(' ') : ''}`;
            seen.add(d.id);
            points.push(pos);
            const style = { radius: 9, color: '#fff', weight: 3, fillColor: stale ? '#9fb7d0' : (d.orders.length ? '#f26522' : '#0E9F6E'), fillOpacity: 1 };
            if (markers[d.id]) {
                markers[d.id].setLatLng(pos).setStyle(style).setTooltipContent(label);
            } else {
                markers[d.id] = L.circleMarker(pos, style).bindTooltip(label, { permanent: true, direction: 'top', offset: [0, -8] }).addTo(map);
            }
        });
        Object.keys(markers).forEach((id) => {
            if (!seen.has(Number(id))) { markers[id].remove(); delete markers[id]; }
        });
        if (!fitted && points.length) {
            map.fitBounds(points, { padding: [40, 40], maxZoom: 15 });
            fitted = true;
        }
    }

    // ---- the trail of a delivered order: a line from the pick-up to the door, with the address pin ----
    let trailLayer = null;
    function clearTrail() {
        if (trailLayer) { trailLayer.remove(); trailLayer = null; }
        trailClear.classList.add('hidden');
        trailInfo.classList.add('hidden');
        trailPick.value = '';
    }
    async function showTrail(id) {
        if (!map || !id) return clearTrail();
        try {
            const res = await fetch(D.urls.trail.replace('__ID__', id), { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            if (trailLayer) trailLayer.remove();
            trailLayer = L.layerGroup().addTo(map);
            const line = data.points.map((p) => [p.lat, p.lng]);
            const bounds = [...line];
            if (line.length > 1) L.polyline(line, { color: '#0A2540', weight: 4, opacity: 0.8 }).addTo(trailLayer);
            if (line.length) {
                L.circleMarker(line[0], { radius: 7, color: '#fff', weight: 2, fillColor: '#0E9F6E', fillOpacity: 1 }).bindTooltip(t.pickedUp).addTo(trailLayer);
                L.circleMarker(line[line.length - 1], { radius: 7, color: '#fff', weight: 2, fillColor: '#f26522', fillOpacity: 1 }).bindTooltip(t.delivered).addTo(trailLayer);
            }
            if (data.destination) {
                const dest = [data.destination.lat, data.destination.lng];
                bounds.push(dest);
                L.circleMarker(dest, { radius: 8, color: '#0A2540', weight: 2, fillColor: '#fff', fillOpacity: 1 }).bindTooltip(t.destination).addTo(trailLayer);
            }
            if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
            trailInfo.textContent = line.length
                ? t.trailOf.replace('__N__', data.number).replace('__D__', data.driver || '').replace('__F__', data.picked_up_at || '').replace('__T__', data.delivered_at || '')
                : t.noTrail;
            trailInfo.classList.remove('hidden');
            trailClear.classList.remove('hidden');
        } catch {
            clearTrail();
        }
    }
    trailPick.addEventListener('change', () => showTrail(trailPick.value));
    trailClear.addEventListener('click', clearTrail);
    function renderRecent() {
        const current = trailPick.value;
        const options = [`<option value="">${esc(t.chooseTrail)}</option>`].concat(recent.map((o) =>
            `<option value="${o.id}">#${o.number} · ${esc(o.driver || '')} · ${esc(o.time || '')}${o.minutes !== null ? ' · ' + esc(t.minutes.replace('__N__', o.minutes)) : ''}${o.method ? ' · ' + esc(t.methods[o.method] || o.method) : ''}</option>`));
        trailPick.innerHTML = options.join('');
        if (current && recent.some((o) => String(o.id) === current)) trailPick.value = current;
    }

    function driverCard(d) {
        const seen = d.location ? t.lastSeen.replace('__T__', d.location.time).replace('__N__', d.location.minutes) : t.neverSeen;
        const orders = d.orders.length ? d.orders.map((o) => {
            const since = minutesSince(o.assigned_at_iso);
            return `
            <li class="flex flex-wrap items-start gap-2"><b dir="ltr">#${o.number}</b><span class="badge ${o.delivery_status === 'picked_up' ? 'bg-emerald-100 text-emerald-800' : 'bg-navy-100 text-navy-700'}">${esc(o.label)}</span>${since !== null ? `<span class="text-xs ${since > 45 ? 'font-bold text-red-700' : 'text-navy-500'}">${esc(t.sinceAssigned.replace('__N__', since))}</span>` : ''}<span class="text-navy-500">${esc(o.address)}</span></li>`;
        }).join('') : `<li class="text-navy-500">${esc(t.free)}</li>`;
        const stats = [[t.deliveredToday.replace('__N__', d.delivered_today), '']];
        if (d.failed_today) stats.push([t.failedToday.replace('__N__', d.failed_today), 'text-red-700']);
        if (d.avg_minutes !== null && d.avg_minutes !== undefined) stats.push([t.avgMinutes.replace('__N__', d.avg_minutes), '']);
        const cash = D.canSettle ? `
            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-navy-50 px-3 py-2 text-sm">
                <span><b>${esc(t.holding.replace('__A__', `${fmt(d.holding)} ${t.currency}`).replace('__N__', d.holding_orders))}</b>
                <span class="block text-xs text-navy-500">${esc(t.collectedToday.replace('__A__', `${fmt(d.collected_today)} ${t.currency}`))}${d.other_today > 0 ? ` · ${esc(t.otherToday.replace('__A__', `${fmt(d.other_today)} ${t.currency}`))}` : ''}</span></span>
                ${d.holding > 0 ? `<button class="btn-primary min-h-10 px-4" data-settle="${d.id}">${esc(t.settle)}</button>` : ''}
            </div>` : '';
        return `
            <article class="rounded-xl border border-navy-100 p-3" data-driver="${d.id}">
                <header class="flex items-start justify-between gap-2">
                    <div><div class="font-bold">${esc(d.name)}</div>${d.phone ? `<a href="tel:${esc(d.phone)}" class="text-xs text-navy-600" dir="ltr">${esc(d.phone)}</a>` : ''}</div>
                    <div class="text-end text-xs text-navy-500"><div>${esc(seen)}</div>${stats.map(([text, cls]) => `<div class="${cls}">${esc(text)}</div>`).join('')}</div>
                </header>
                <ul class="mt-2 space-y-1 text-sm">${orders}</ul>
                ${cash}
            </article>`;
    }

    function waitingCard(o) {
        const options = [`<option value="">${esc(o.driver_id ? t.noDriver : t.chooseDriver)}</option>`]
            .concat(drivers.map((d) => `<option value="${d.id}" ${d.id === o.driver_id ? 'selected' : ''}>${esc(d.name)} (${d.orders.length})</option>`)).join('');
        return `
            <article class="rounded-xl border border-navy-100 p-3 ${o.delivery_status === 'failed' ? 'ring-2 ring-red-300' : ''}">
                <header class="flex items-start justify-between gap-2">
                    <div><b class="text-lg" dir="ltr">#${o.number}</b> <span class="badge bg-navy-100 text-navy-700">${esc(o.status_label)}</span>
                        ${o.delivery_label ? `<span class="badge ${o.delivery_status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'}">${esc(o.delivery_label)}</span>` : ''}</div>
                    <div class="text-end text-xs text-navy-500"><div>${esc(o.time)}</div><div class="font-bold text-navy-800">${esc(o.total)} ${esc(t.currency)}</div></div>
                </header>
                ${o.customer ? `<p class="mt-1 text-sm">${esc(o.customer)}</p>` : ''}
                ${o.address ? `<p class="text-sm font-semibold text-navy-700">📍 ${esc(o.address)}</p>` : ''}
                ${o.reason ? `<p class="mt-1 rounded-lg bg-red-50 p-2 text-xs font-semibold text-red-700">${esc(t.failed)}: ${esc(o.reason)}${o.driver ? ` · ${esc(o.driver)}` : ''}</p>` : ''}
                ${D.canAssign ? `<select class="field mt-2" data-assign="${o.id}">${options}</select>` : ''}
            </article>`;
    }

    async function poll() {
        try {
            const res = await fetch(D.urls.feed, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            drivers = data.drivers || [];
            recent = data.recent || [];
            // a list the manager is choosing in is not redrawn under their finger
            if (!document.activeElement?.matches?.('[data-assign], [data-trail-pick]')) {
                driversBox.innerHTML = drivers.length ? drivers.map(driverCard).join('') : `<p class="py-6 text-center text-sm text-navy-500">${esc(t.noDrivers)}</p>`;
                waitingBox.innerHTML = (data.waiting || []).length ? data.waiting.map(waitingCard).join('') : `<p class="py-6 text-center text-sm text-navy-500">${esc(t.noWaiting)}</p>`;
                renderRecent();
            }
            renderMap();
            document.querySelector('[data-live-dot]')?.classList.replace('bg-red-500', 'bg-emerald-500');
        } catch {
            document.querySelector('[data-live-dot]')?.classList.replace('bg-emerald-500', 'bg-red-500');
        }
    }

    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) alert(data.message || res.status);
        else if (data.message) alert(data.message);
        return res.ok;
    }

    waitingBox.addEventListener('change', async (e) => {
        const select = e.target.closest('[data-assign]');
        if (!select) return;
        select.disabled = true;
        await post(D.urls.assign.replace('__ID__', select.dataset.assign), { driver_id: select.value ? Number(select.value) : null });
        select.blur();
        poll();
    });

    driversBox.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-settle]');
        if (!btn) return;
        const d = drivers.find((x) => String(x.id) === btn.dataset.settle);
        if (!confirm(t.settleSure.replace('__D__', d?.name ?? '').replace('__A__', `${fmt(d?.holding)} ${t.currency}`))) return;
        btn.disabled = true;
        await post(D.urls.settle.replace('__ID__', btn.dataset.settle), {});
        poll();
    });

    poll();
    setInterval(() => { if (!document.hidden) poll(); }, Math.max(10, Number(D.poll || 20)) * 1000);
}
