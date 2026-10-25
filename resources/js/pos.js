// POS: item grid, ticket, takeaway/table, discount, payment with change, open orders, receipts and the
// customer-facing display (mirrored through BroadcastChannel). The server recomputes every amount.
import { canScanQr, planVouchers, scanQr } from './corporate-vouchers';
import { createOffline, NetworkError, printLocalReceipt } from './pos-offline';
import './kb-drawer';
import { bindPager, pagerButtons, pagerStatus } from './pager-call';
import { createNativePrinter } from './native-print';

const dataEl = document.getElementById('pos-data');
if (dataEl) {
    const D = JSON.parse(dataEl.textContent);
    const T = JSON.parse(document.getElementById('pos-i18n').textContent);
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = (n) => `${Math.round(n * 100) / 100} ${D.currency}`;
    const session = { csrf: $('meta[name="csrf-token"]').content };
    const items = Object.fromEntries(D.items.map((i) => [i.id, i]));
    const display = 'BroadcastChannel' in window ? new BroadcastChannel('egmenu-pos') : null;
    const uuid = () => crypto.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (crypto.getRandomValues(new Uint8Array(1))[0] & 15);
        return (c === 'x' ? r : (r & 3) | 8).toString(16);
    });

    const CC = D.mode === 'callcenter'; // call-center mode: delivery / pickup, caller lookup, no payment here
    let ccPoint = () => null; // the pinned customer location (set by the call-center map below)
    let resetPoint = () => {};
    let renderCc = () => {}; // call-center panel: steps, zone facts, summary line (set below)
    let resetCc = () => {};
    let goStep = () => {};
    const zones = Object.fromEntries((D.zones || []).map((z) => [z.id, z]));
    let state = { type: CC ? 'delivery' : 'takeaway', table: '', lines: [], category: 'all' };
    let lastOrderId = null;
    let lastOffline = null; // the last sale saved on this device while offline (printed from here)
    // offline mode (POS only): terminal, catalog and the sales made without internet, synced when it is back
    const off = !CC && D.tenant ? createOffline(D, session) : null;
    // inside the EgHub cashier app: the app's printers (receipt, kitchen per station); null in a browser
    const printer = !CC && D.printing ? createNativePrinter({
        tenant: D.tenant, csrf: () => session.csrf, printing: D.printing,
        urls: { jobs: D.urls.printJobs, release: D.urls.printRelease, queue: D.urls.printQueue }, texts: T.native || {},
    }) : null;

    // every request of the screen: a lost connection throws NetworkError (with a readable message), a refusal Error
    async function api(url, body, method = 'POST') {
        if (off) {
            try {
                return await off.request(url, { method, body });
            } catch (e) {
                if (e instanceof NetworkError) e.message = T.noInternet;
                throw e;
            }
        }
        const res = await fetch(url, {
            method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': session.csrf },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || (data.errors ? Object.values(data.errors)[0][0] : res.status));
        return data;
    }
    const getJson = (url) => api(url, null, 'GET');

    // ---- menu grid ----
    function renderCats() {
        $('[data-cats]').innerHTML = [{ id: 'all', name: T.all }, ...D.categories].map((c) =>
            `<button type="button" data-cat="${c.id}" class="btn min-h-9 shrink-0 rounded-full px-4 ${String(state.category) === String(c.id) ? 'bg-navy-800 text-white' : 'bg-navy-50'}">${esc(c.name)}</button>`).join('');
    }
    function renderItems() {
        const q = $('[data-search]').value.trim().toLowerCase();
        const list = D.items.filter((i) => (state.category === 'all' || String(i.category) === String(state.category)) && (!q || i.name.toLowerCase().includes(q)));
        $('[data-items]').innerHTML = list.map((i) => `
            <button type="button" data-add="${i.id}" ${i.soldOut ? 'disabled' : ''}
                class="flex min-h-24 flex-col justify-between rounded-xl border border-navy-100 bg-white p-3 text-start shadow-sm active:scale-[.98] disabled:opacity-40">
                <span class="font-bold leading-snug">${esc(i.name)}</span>
                <span class="text-sm font-extrabold text-brand-700">${i.soldOut ? esc(T.soldOut) : fmt(i.price)}</span>
            </button>`).join('');
    }
    $('[data-cats]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-cat]');
        if (!b) return;
        state.category = b.dataset.cat;
        renderCats();
        renderItems();
    });
    $('[data-search]').addEventListener('input', renderItems);

    // ---- options dialog ----
    const optDialog = $('[data-options-dialog]');
    let optItem = null;
    function openOptions(item) {
        optItem = item;
        $('[data-options-title]').textContent = item.name;
        $('[data-line-notes]').value = '';
        $('[data-options-body]').innerHTML = item.groups.map((g) => `
            <fieldset><legend class="mb-1 font-bold">${esc(g.name)} ${g.required ? `<span class="badge bg-brand-100 text-brand-700">${esc(T.required)}</span>` : ''}</legend>
            <div class="grid grid-cols-2 gap-2">${g.options.map((o) => `
                <label class="flex min-h-11 items-center gap-2 rounded-lg border border-navy-100 px-2 ${o.soldOut ? 'opacity-40' : ''}">
                    <input type="${g.type === 'single' ? 'radio' : 'checkbox'}" name="g${g.id}" value="${o.id}" data-opt data-group="${g.id}" ${o.soldOut ? 'disabled' : ''} ${g.type === 'single' && o.default && !o.soldOut ? 'checked' : ''} class="size-4 accent-brand-600">
                    <span class="text-sm">${esc(o.name)}${o.price ? ` <span class="text-navy-500">+${o.price}</span>` : ''}</span>
                </label>`).join('')}</div></fieldset>`).join('');
        optDialog.showModal();
    }
    optDialog.addEventListener('change', (e) => {
        const input = e.target.closest('[data-opt]');
        if (input?.type !== 'checkbox') return;
        const g = optItem.groups.find((x) => x.id === Number(input.dataset.group));
        if (g.max && $$(`[data-group="${g.id}"]:checked`, optDialog).length > g.max) input.checked = false;
    });
    $('[data-options-add]').addEventListener('click', () => {
        const ids = $$('[data-opt]:checked', optDialog).map((i) => Number(i.value));
        const missing = optItem.groups.find((g) => g.required && !g.options.some((o) => ids.includes(o.id)));
        if (missing) return alert(T.pickRequired.replace('__G__', missing.name));
        addLine(optItem, ids, $('[data-line-notes]').value.trim());
        optDialog.close();
    });

    function addLine(item, optionIds = [], notes = '') {
        const same = state.lines.find((l) => l.item_id === item.id && l.notes === notes && l.option_ids.join() === optionIds.join());
        if (same) same.qty++;
        else state.lines.push({ item_id: item.id, option_ids: optionIds, qty: 1, notes });
        render();
    }
    $('[data-items]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-add]');
        if (!b) return;
        const item = items[b.dataset.add];
        item.groups.length ? openOptions(item) : addLine(item);
    });

    // ---- ticket ----
    const unit = (l) => {
        const item = items[l.item_id];
        return item.price + item.groups.flatMap((g) => g.options).filter((o) => l.option_ids.includes(o.id)).reduce((s, o) => s + o.price, 0);
    };
    function totals() {
        const subtotal = state.lines.reduce((s, l) => s + unit(l) * l.qty, 0);
        const raw = Number($('[data-discount]')?.value || 0);
        const staff = Math.round(Math.min(subtotal, $('[data-discount-kind]')?.value === 'percent' ? subtotal * raw / 100 : raw) * 100) / 100;
        // the voucher's discount as the server priced it for this exact cart (re-checked when the cart changes)
        const voucher = state.coupon && state.coupon.key === couponKey(staff) ? Math.min(subtotal - staff, state.coupon.off) : 0;
        const discount = staff + voucher;
        const service = state.type === 'dine_in' ? (subtotal - discount) * D.service / 100 : 0;
        const tax = (subtotal - discount + service) * D.tax / 100;
        const zone = CC && state.type === 'delivery' ? zones[$('[data-zone]').value] : null;
        const delivery = zone ? zone.fee : 0;
        return { subtotal, staff, voucher, discount: Math.round(discount * 100) / 100, service, tax, delivery, zone, total: subtotal - discount + service + tax + delivery };
    }

    // ---- voucher / coupon code (loyalty vouchers, campaign coupons): checked by the server, never priced here ----
    const phoneValue = () => $('[data-customer-phone]')?.value.trim() || '';
    function couponKey(staff) {
        return JSON.stringify([state.lines, phoneValue(), staff]);
    }
    function couponInfo(text, ok) {
        const info = $('[data-coupon-info]');
        if (!info) return;
        info.textContent = text || '';
        info.classList.toggle('hidden', !text);
        info.classList.toggle('text-emerald-700', !!ok);
        info.classList.toggle('text-red-600', !ok);
    }
    let couponTimer = null;
    async function applyCoupon(code) {
        if (!D.urls.coupon) return;
        code = (code || '').trim().toUpperCase();
        if (!code) { state.coupon = null; couponInfo(''); render(); return; }
        if (!state.lines.length) return;
        const staff = totals().staff;
        const key = couponKey(staff);
        try {
            const r = await api(D.urls.coupon, { coupon: code, items: state.lines, customer_phone: phoneValue() || null, discount: staff });
            state.coupon = { code: r.code, off: r.discount, key };
            couponInfo(`${r.code} · ${r.label} · −${fmt(r.discount)}`, true);
        } catch (e) {
            state.coupon = null;
            couponInfo(e.message, false);
        }
        render();
    }
    $('[data-coupon-apply]')?.addEventListener('click', () => applyCoupon($('[data-coupon]').value));
    $('[data-coupon]')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyCoupon(e.target.value); } });
    $('[data-customer-phone]')?.addEventListener('change', () => { if (state.coupon) applyCoupon(state.coupon.code); });
    // the cart changed under an applied voucher: price it again for the new cart
    function recheckCoupon() {
        if (!state.coupon || state.coupon.key === couponKey(totals().staff)) return;
        clearTimeout(couponTimer);
        couponTimer = setTimeout(() => (state.lines.length ? applyCoupon(state.coupon.code) : ((state.coupon = null), couponInfo(T.voucherRemoved, false), render())), 400);
    }
    function render() {
        $$('[data-type]').forEach((b) => {
            const on = b.dataset.type === state.type;
            b.classList.toggle('bg-navy-800', on);
            b.classList.toggle('text-white', on);
            b.classList.toggle('bg-navy-50', !on);
        });
        $('[data-table-box]')?.classList.toggle('hidden', state.type !== 'dine_in');
        $('[data-delivery-box]')?.classList.toggle('hidden', state.type !== 'delivery');
        $('[data-lines]').innerHTML = state.lines.length ? state.lines.map((l, i) => {
            const item = items[l.item_id];
            const opts = item.groups.flatMap((g) => g.options).filter((o) => l.option_ids.includes(o.id)).map((o) => o.name).join('، ');
            return `<li class="flex items-center gap-2 py-2">
                <div class="min-w-0 flex-1"><div class="font-semibold">${esc(item.name)}</div>
                    ${opts ? `<div class="text-xs text-navy-500">${esc(opts)}</div>` : ''}${l.notes ? `<div class="text-xs text-brand-700">${esc(l.notes)}</div>` : ''}</div>
                <div class="flex items-center rounded-lg border border-navy-100">
                    <button type="button" class="size-10 md:size-9" data-step="-1" data-i="${i}">−</button><b class="w-6 text-center">${l.qty}</b>
                    <button type="button" class="size-10 md:size-9" data-step="1" data-i="${i}">+</button></div>
                <div class="w-16 text-end text-sm font-bold">${Math.round(unit(l) * l.qty * 100) / 100}</div>
            </li>`;
        }).join('') : `<li class="py-10 text-center text-sm text-navy-500">${esc(T.empty)}</li>`;
        const t = totals();
        const row = (label, v, cls = '') => `<div class="flex justify-between ${cls}"><span>${esc(label)}</span><span>${fmt(v)}</span></div>`;
        $('[data-totals]').innerHTML = (t.discount || t.service || t.tax || t.delivery ? row(T.subtotal, t.subtotal) : '')
            + (t.staff ? row(T.discount, -t.staff, 'text-emerald-700') : '')
            + (t.voucher ? row(T.voucher.replace('__C__', state.coupon.code), -t.voucher, 'text-emerald-700') : '') + (t.service ? row(T.service, t.service) : '')
            + (t.tax ? row(T.tax, t.tax) : '') + (t.delivery ? row(T.deliveryFee, t.delivery) : '') + row(T.total, t.total, 'text-lg font-extrabold');
        if (CC) renderCc(t);
        // phones: the bar under the menu shows how many items the order has and its total
        const bar = $('[data-ticket-bar]');
        if (bar) {
            $('[data-ticket-count]', bar).textContent = state.lines.reduce((n, l) => n + l.qty, 0);
            $('[data-ticket-total]', bar).textContent = fmt(t.total);
        }
        display?.postMessage({ lines: state.lines.map((l) => ({ name: items[l.item_id].name, qty: l.qty, amount: unit(l) * l.qty })), totals: t, currency: D.currency });
        recheckCoupon();
    }
    $('[data-lines]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-step]');
        if (!b) return;
        const l = state.lines[Number(b.dataset.i)];
        l.qty += Number(b.dataset.step);
        if (l.qty < 1) state.lines.splice(Number(b.dataset.i), 1);
        render();
    });
    $$('[data-type]').forEach((b) => b.addEventListener('click', () => { state.type = b.dataset.type; render(); }));
    if (!CC) {
        $('[data-table]').innerHTML += D.tables.map((t) => `<option value="${t.id}">${esc(T.table.replace('__N__', t.name))}</option>`).join('');
        $('[data-table]').addEventListener('change', (e) => (state.table = e.target.value));
    }
    $('[data-discount]')?.addEventListener('input', render);
    $('[data-discount-kind]')?.addEventListener('change', render);
    $('[data-clear]').addEventListener('click', () => { if (state.lines.length && confirm(T.clearAsk)) reset(); });

    function reset() {
        state = { ...state, lines: [], table: '', coupon: null };
        if ($('[data-table]')) $('[data-table]').value = '';
        if ($('[data-coupon]')) $('[data-coupon]').value = '';
        couponInfo('');
        ['[data-customer-name]', '[data-customer-phone]', '[data-notes]', '[data-phone2]', '[data-zone]', '[data-address]', '[data-address-label]']
            .forEach((sel) => { if ($(sel)) $(sel).value = ''; });
        if (CC) {
            resetPoint();
            state.type = 'delivery';
            resetCc();
        }
        if ($('[data-discount]')) $('[data-discount]').value = 0;
        render();
    }

    function payload(pay, clientUuid = uuid()) {
        const p = {
            client_uuid: clientUuid, type: state.type, table_id: state.type === 'dine_in' ? Number(state.table) || null : null,
            items: state.lines, discount: totals().staff, pay: pay || null,
            customer_name: $('[data-customer-name]').value.trim(), customer_phone: $('[data-customer-phone]').value.trim(), notes: $('[data-notes]').value.trim(),
        };
        if (CC) p.phone_2 = $('[data-phone2]').value.trim();
        if (state.coupon) p.coupon = state.coupon.code;
        if (CC && state.type === 'delivery') {
            Object.assign(p, { zone_id: Number($('[data-zone]').value) || null, address: $('[data-address]').value.trim(), address_label: $('[data-address-label]').value.trim() });
            if (ccPoint()) Object.assign(p, { lat: ccPoint()[0], lng: ccPoint()[1] });
        }
        return p;
    }
    function canSubmit() {
        if (!state.lines.length) return false;
        if (state.type === 'dine_in' && !state.table) { alert(T.chooseTable); return false; }
        if (CC) {
            if (!$('[data-customer-phone]').value.trim()) { goStep('customer', true); alert(T.phoneFirst); $('[data-customer-phone]').focus(); return false; }
            if (state.type === 'delivery') {
                const t = totals();
                if (!t.zone) { goStep('delivery'); alert(T.chooseZone); return false; }
                if (!$('[data-address]').value.trim()) { goStep('delivery'); alert(T.enterAddress); $('[data-address]').focus(); return false; }
                if (t.zone.min > 0 && t.subtotal - t.discount < t.zone.min) { alert(T.minOrder.replace('__A__', fmt(t.zone.min))); return false; }
            }
        }
        return true;
    }

    // ---- call center: caller card, saved addresses, delivery zone, steps ----
    if (CC) {
        const panel = $('[data-cc-panel]');
        const zoneSel = $('[data-zone]');
        zoneSel.innerHTML += D.zones.map((z) => `<option value="${z.id}">${esc(z.name)} · ${fmt(z.fee)}${z.minutes ? ' · ' + esc(T.minutes.replace('__N__', z.minutes)) : ''}</option>`).join('');
        zoneSel.addEventListener('change', render);
        let saved = [];

        // ---- manual zones: the main area, then its street / sub-area (as the guest picks it on the menu) ----
        const rootSel = $('[data-zone-root]');
        const subSel = $('[data-zone-sub]');
        const kidsOf = (id) => D.zones.filter((z) => z.parent === id);
        // a sub-area whose main area is not offered on its own is listed with the main areas
        const roots = D.zones.filter((z) => !z.parent || !zones[z.parent]);
        const facts = (z) => [T.zoneFee.replace('__A__', fmt(z.fee)), z.min > 0 ? T.zoneMin.replace('__A__', fmt(z.min)) : '', z.minutes ? T.zoneTime.replace('__N__', z.minutes) : '']
            .filter(Boolean).join(' · ');
        if (rootSel) {
            rootSel.innerHTML += roots.map((z) => {
                const kids = kidsOf(z.id).length;
                return `<option value="${z.id}" title="${kids ? esc(T.streets.replace('__N__', kids)) : ''}">${esc(z.parent ? z.name : z.short)}${kids ? '' : ' · ' + fmt(z.fee)}</option>`;
            }).join('');
        }
        function fillSub(rootId, selected = '') {
            if (!subSel) return;
            const kids = rootId ? kidsOf(rootId) : [];
            subSel.innerHTML = `<option value="">${esc(T.chooseStreet)}</option>`
                + kids.map((z) => `<option value="${z.id}">${esc(z.short)} · ${fmt(z.fee)}${z.min > 0 ? ' · ' + esc(T.zoneMin.replace('__A__', fmt(z.min))) : ''}</option>`).join('');
            subSel.disabled = !kids.length;
            subSel.classList.toggle('hidden', !!rootId && !kids.length);
            rootSel.classList.toggle('col-span-2', !!rootId && !kids.length);
            subSel.value = selected;
        }
        // keeps the two pickers in step with the zone that is sent (picked from a saved address or the map)
        function syncPickers() {
            if (!rootSel) return;
            const z = zones[zoneSel.value];
            const rootId = z ? (z.parent && zones[z.parent] ? z.parent : z.id) : null;
            rootSel.value = rootId || '';
            fillSub(rootId, z && rootId !== z.id ? String(z.id) : '');
        }
        function setZone(id) {
            zoneSel.value = id || '';
            syncPickers();
            render();
        }
        rootSel?.addEventListener('change', () => {
            const id = Number(rootSel.value) || null;
            fillSub(id);
            // an area without streets is the zone itself; otherwise wait for the street
            zoneSel.value = id && !kidsOf(id).length ? id : '';
            render();
            if (id && kidsOf(id).length) subSel.focus();
        });
        subSel?.addEventListener('change', () => {
            zoneSel.value = subSel.value || '';
            render();
        });

        // ---- map: pin the customer, the zone whose drawn area contains the pin is chosen ----
        let point = null;
        let map = null;
        let marker = null;
        const inside = ([lat, lng], poly) => {
            let hit = false;
            for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
                const [yi, xi] = poly[i];
                const [yj, xj] = poly[j];
                if ((xi > lng) !== (xj > lng) && lat < ((yj - yi) * (lng - xi)) / (xj - xi) + yi) hit = !hit;
            }
            return hit;
        };
        // sub-areas first: the most specific zone wins
        const drawn = D.zones.filter((z) => z.polygon && z.polygon.length >= 3).sort((a, b) => b.sub - a.sub);
        function setPoint(p, pickZone = true) {
            point = p;
            const pinned = $('[data-pinned]');
            pinned.textContent = p ? '📍 ✓ ' + T.pinnedOnMap : '';
            pinned.classList.toggle('hidden', !p);
            $('[data-map-toggle]').classList.toggle('ring-2', !!p);
            $('[data-map-toggle]').classList.toggle('ring-emerald-500', !!p);
            if (!map) return;
            if (marker) marker.remove();
            marker = p ? L.marker(p).addTo(map) : null;
            if (p && pickZone) {
                const zone = drawn.find((z) => inside(p, z.polygon));
                if (zone) setZone(zone.id); else alert(T.outside);
            }
        }
        $('[data-map-toggle]').addEventListener('click', () => {
            const box = $('[data-cc-map]');
            box.classList.toggle('hidden');
            if (map || !window.L || box.classList.contains('hidden')) return;
            map = L.map(box).setView(drawn[0]?.polygon[0] || [30.0444, 31.2357], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
            drawn.forEach((z) => L.polygon(z.polygon, { color: '#0A2540', weight: 1, fillOpacity: 0.08 }).bindTooltip(z.name).addTo(map));
            if (drawn.length) map.fitBounds(drawn.flatMap((z) => z.polygon));
            map.on('click', (e) => setPoint([+e.latlng.lat.toFixed(6), +e.latlng.lng.toFixed(6)]));
            setPoint(point, false);
            if (point) map.setView(point, 15);
        });

        // ---- saved addresses: one tap fills the zone, the address and its name ----
        const pick = (a) => {
            $('[data-address]').value = a.text;
            $('[data-address-label]').value = a.label || '';
            setPoint(a.lat != null ? [a.lat, a.lng] : null, false);
            setZone(a.zone_id);
        };
        function renderAddresses() {
            $('[data-addresses-box]').classList.toggle('hidden', !saved.length);
            const text = $('[data-address]').value.trim();
            $('[data-addresses]').innerHTML = saved.map((a, i) => {
                const on = String(a.zone_id) === zoneSel.value && a.text === text;
                return `<button type="button" class="flex min-h-11 min-w-0 max-w-full flex-col items-start rounded-xl border px-3 py-1.5 text-start ${on ? 'border-brand-600 bg-brand-50 ring-1 ring-brand-600' : 'border-navy-100 bg-white hover:border-navy-300'}" data-pick-address="${i}" aria-pressed="${on}" title="${esc(a.text)}">
                    <span class="text-sm font-bold">${a.label ? '📍 ' + esc(a.label) : '📍 ' + esc(zones[a.zone_id].short)}</span>
                    <span class="max-w-56 truncate text-xs text-navy-500">${esc(zones[a.zone_id].name)} · ${esc(a.text)}</span>
                </button>`;
            }).join('');
        }
        $('[data-addresses]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-pick-address]');
            if (b) pick(saved[Number(b.dataset.pickAddress)]);
        });
        $('[data-address]').addEventListener('input', () => render());

        // ---- the caller: look up by mobile; a known caller collapses to a card with "edit" ----
        const showSearch = (on) => {
            $('[data-caller-search]').classList.toggle('hidden', !on);
            $('[data-customer-fields]').classList.toggle('hidden', !on);
        };
        function callerCard(c) {
            const last = c.orders[0];
            const chips = [
                c.orders_count ? T.ordersCount.replace('__N__', c.orders_count) : T.firstOrder,
                last ? T.lastOrder.replace('__W__', last.date).replace('__T__', last.total + ' ' + D.currency) : '',
                c.tier ? '⭐ ' + c.tier : '',
                ...(c.loyalty || []).map((l) => '🎟 ' + l.title + ' · ' + T.stamps.replace('__N__', l.stamps).replace('__O__', l.of) + (l.rewards ? ' · ' + T.rewards.replace('__N__', l.rewards) : '')),
                c.balance > 0 ? '💳 ' + T.balance.replace('__A__', fmt(c.balance)) : '',
            ].filter(Boolean);
            const phones = [c.phone, $('[data-phone2]').value.trim()].filter(Boolean);
            return `<div class="rounded-2xl border border-navy-100 bg-white p-3 shadow-sm" data-caller-card>
                <div class="flex items-start gap-3">
                    <div class="grid size-11 shrink-0 place-items-center rounded-full bg-navy-800 text-lg font-extrabold text-white" aria-hidden="true">${esc((c.name || '#').trim().charAt(0))}</div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-base font-extrabold">${esc(c.name || T.noName)}</div>
                        <div class="flex flex-wrap gap-x-3 text-sm text-navy-500">${phones.map((x) => `<span dir="ltr">${esc(x)}</span>`).join('')}</div>
                    </div>
                    <button type="button" class="btn-ghost min-h-11 shrink-0 px-3 text-sm" data-caller-edit>${esc(T.edit)}</button>
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">${chips.map((x) => `<span class="rounded-full bg-navy-50 px-2.5 py-1 text-xs font-semibold text-navy-700">${esc(x)}</span>`).join('')}</div>
                ${c.notes ? `<div class="mt-2 rounded-xl bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700">⚠ ${esc(c.notes)}</div>` : ''}
                ${c.orders.length ? `<details class="mt-1 text-xs"><summary class="flex min-h-11 cursor-pointer items-center font-semibold text-navy-600">${esc(T.previousOrders)}</summary>
                    <ul class="space-y-1 text-navy-600">${c.orders.map((o) => `<li>#${o.number} · ${esc(o.date)} · ${esc(o.type)} · ${esc(o.total)} · ${esc(o.status)}</li>`).join('')}</ul></details>` : ''}
            </div>`;
        }
        $('[data-caller]').addEventListener('click', (e) => {
            if (!e.target.closest('[data-caller-edit]')) return;
            showSearch(true);
            e.target.closest('[data-caller-edit]').remove();
            $('[data-customer-name]').focus();
        });
        async function lookup() {
            const phone = $('[data-customer-phone]').value.trim();
            if (!phone) return;
            const box = $('[data-caller]');
            box.classList.remove('hidden');
            try {
                const res = await fetch(D.urls.lookup + '?phone=' + encodeURIComponent(phone), { headers: { Accept: 'application/json' } });
                const c = await res.json();
                if (!res.ok) throw new Error(c.message || res.status);
                $('[data-customer-phone]').value = c.phone;
                if (!c.found) {
                    saved = [];
                    renderAddresses();
                    box.innerHTML = `<p class="rounded-xl bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800">${esc(T.newCaller)}</p>`;
                    showSearch(true);
                    $('[data-customer-name]').focus();
                    render();
                    return;
                }
                $('[data-customer-name]').value = c.name || '';
                $('[data-phone2]').value = c.phone_2 || '';
                box.innerHTML = callerCard(c);
                showSearch(false);
                saved = c.addresses.filter((a) => zones[a.zone_id]); // addresses in zones this branch still serves
                renderAddresses();
                if (saved[0] && state.type === 'delivery') pick(saved[0]); else render();
                goStep('delivery');
            } catch (err) {
                box.innerHTML = `<p class="rounded-xl bg-red-50 px-3 py-2 text-sm font-bold text-red-700">${esc(err.message)}</p>`;
            }
        }
        ccPoint = () => point;
        resetPoint = () => setPoint(null, false);
        $('[data-lookup]').addEventListener('click', lookup);
        $('[data-customer-phone]').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });
        $('[data-customer-name]').addEventListener('input', () => render());

        // ---- steps on narrower screens (customer → delivery → order); both zones show side by side from 1280px ----
        let step = 'customer';
        goStep = (s, focus = false) => {
            step = s;
            panel.dataset.ccStep = s;
            $$('[data-cc-tab]').forEach((b) => {
                const on = b.dataset.ccTab === s;
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                b.classList.toggle('bg-white', on);
                b.classList.toggle('text-navy-900', on);
                b.classList.toggle('shadow-sm', on);
                b.classList.toggle('text-navy-600', !on);
            });
            $('[data-cc-setup]').classList.toggle('max-xl:hidden', s === 'order');
            $('[data-cc-order]').classList.toggle('max-xl:hidden', s !== 'order');
            $('[data-cc-pane="customer"]').classList.toggle('max-xl:hidden', s !== 'customer');
            $('[data-cc-pane="delivery"]').classList.toggle('max-xl:hidden', s !== 'delivery');
            $('[data-cc-footer-summary]').classList.toggle('hidden', s === 'order');
            if (focus && s === 'customer') $('[data-customer-phone]').focus();
        };
        $$('[data-cc-tab]').forEach((b) => b.addEventListener('click', () => goStep(b.dataset.ccTab)));
        $$('[data-cc-next]').forEach((b) => b.addEventListener('click', () => goStep(b.dataset.ccNext)));
        $$('[data-cc-goto]').forEach((b) => b.addEventListener('click', () => goStep(b.dataset.ccGoto)));

        renderCc = (t) => {
            const delivery = state.type === 'delivery';
            $('[data-pickup-note]').classList.toggle('hidden', delivery);
            // the chosen zone: fee, minimum and time inline, and how far below the minimum the order is
            const z = delivery ? t.zone : null;
            $('[data-zone-facts]').classList.toggle('hidden', !delivery);
            $('[data-zone-facts]').innerHTML = z
                ? `<b class="text-navy-900">${esc(z.name)}</b><span>${esc(facts(z))}</span>`
                : `<span class="text-navy-500">${esc(T.pickArea)}</span>`;
            const left = z && z.min > 0 ? z.min - (t.subtotal - t.discount) : 0;
            ['[data-min-warning]', '[data-min-warning-footer]'].forEach((sel) => {
                const warn = $(sel);
                warn.textContent = left > 0 ? '⚠ ' + T.belowMin.replace('__M__', fmt(z.min)).replace('__S__', fmt(left)) : '';
                warn.classList.toggle('hidden', !(left > 0));
            });
            // one line that says who and where, above the order lines (and in the footer on the other steps)
            const name = $('[data-customer-name]').value.trim();
            const phone = $('[data-customer-phone]').value.trim();
            const who = name || phone ? `👤 <b>${esc(name || phone)}</b>${name && phone ? ` <span class="text-navy-500" dir="ltr">${esc(phone)}</span>` : ''}` : `<span class="text-amber-700">👤 ${esc(T.noCustomer)}</span>`;
            const where = !delivery ? `🛍 <b>${esc(T.pickup)}</b>${D.branchName ? ' · ' + esc(D.branchName) : ''}`
                : z ? `<span class="truncate">🛵 <b>${esc(T.delivery)}</b> · ${esc(z.name)}</span><span class="shrink-0 font-bold">· ${esc(fmt(z.fee))}</span>`
                    : `<span class="font-bold text-amber-700">🛵 ${esc(T.delivery)} · ${esc(T.noArea)}</span>`;
            $$('[data-cc-summary]').forEach((el) => { el.innerHTML = `<div class="truncate">${who}</div><div class="flex min-w-0 gap-1 overflow-hidden whitespace-nowrap"><span class="flex min-w-0 gap-1">${where}</span></div>`; });
            const count = state.lines.reduce((n, l) => n + l.qty, 0);
            $$('[data-cc-count]').forEach((el) => { el.textContent = count; });
            $('[data-send-total]').textContent = fmt(t.total);
            // the step badges turn into a check once the step has what the order needs
            const done = { customer: !!phone, delivery: !delivery || (!!z && !!$('[data-address]').value.trim()), order: count > 0 };
            $$('[data-cc-tab]').forEach((b, i) => {
                const num = $('[data-cc-tab-num]', b);
                num.textContent = done[b.dataset.ccTab] ? '✓' : ['١', '٢', '٣'][i];
                num.classList.toggle('bg-emerald-600', done[b.dataset.ccTab]);
                num.classList.toggle('text-white', done[b.dataset.ccTab]);
                num.classList.toggle('bg-white', !done[b.dataset.ccTab]);
                num.classList.toggle('ring-1', !done[b.dataset.ccTab]);
            });
            renderAddresses();
        };
        resetCc = () => {
            saved = [];
            $('[data-caller]').classList.add('hidden');
            $('[data-caller]').innerHTML = '';
            showSearch(true);
            if ($('[data-address-more]')) $('[data-address-more]').open = false;
            $('[data-cc-map]').classList.add('hidden');
            syncPickers();
            goStep('customer');
        };
        goStep('customer');
    }

    // ---- send / pay ----
    const doneDialog = $('[data-done-dialog]');
    // ---- the receipt's link on WhatsApp (wa.me: the guest's mobile when known, else pick the chat) ----
    let lastShare = null;
    function shareReceipt(o) {
        const text = T.shareText.replace('__R__', o.ref).replace('__U__', o.share);
        const digits = String(o.phone || '').replace(/\D/g, '');
        const intl = digits ? (digits.startsWith('20') ? digits : '20' + digits.replace(/^0/, '')) : '';
        window.open('https://wa.me/' + intl + '?text=' + encodeURIComponent(text), '_blank', 'noopener');
    }
    $('[data-share-wa]')?.addEventListener('click', () => { if (lastShare) shareReceipt(lastShare); });

    function done(order, status) {
        lastOrderId = order.id;
        lastOffline = null;
        lastShare = order.receipt_url ? { ref: '#' + order.number, share: order.receipt_url, phone: order.phone || '' } : null;
        $('[data-share-wa]')?.classList.toggle('hidden', !lastShare);
        $('[data-done-number]').textContent = '#' + order.number;
        $('[data-done-status]').textContent = status;
        reset();
        refreshOpen();
        doneDialog.showModal();
        if (printer) {
            // the cashier app prints by itself: the receipt once paid (setting), the kitchen tickets of the new order
            if (printer.receiptAuto && (status === T.paid || order.paid)) printer.printOrder(order.id, ['receipt']);
            printer.poll();
        }
    }
    $$('[data-print]').forEach((b) => b.addEventListener('click', () => {
        if (lastOffline) return printLocalReceipt(lastOffline, D.receiptHeader, T, b.dataset.print === 'kitchen');
        if (printer && lastOrderId) {
            return printer.printOrder(lastOrderId, b.dataset.print === 'kitchen' ? printer.kitchenRoles() : ['receipt'], { reprint: true });
        }
        if (lastOrderId) window.open(D.urls.receipt.replace('__ID__', lastOrderId) + (b.dataset.print === 'kitchen' ? '?copy=kitchen' : ''), '_blank', 'width=420,height=700');
    }));

    $('[data-send]').addEventListener('click', async () => {
        if (!canSubmit()) return;
        if (off && !off.online) return alert(T.offSend);
        try { done(await api(D.urls.order, payload(null)), CC ? T.placed : T.sent); } catch (err) { alert(err.message); }
    });

    const payDialog = $('[data-pay-dialog]');
    let payTarget = null; // null = current ticket, else {id,total} of an open order
    let method = 'cash';
    // corporate vouchers on this payment: [{id, code, serial, company, balance, mode, expiresSort, approval}], and the
    // guest's mobile when they were found by it (linked vouchers go with the mobile, never with the paper code)
    let corp = { vouchers: [], phone: null };
    const payTotal = () => (payTarget ? payTarget.total : totals().total);
    // earliest expiry first, until the order is covered (same rule as the server); single-use vouchers lose the rest
    const corpPlan = () => planVouchers(corp.vouchers, payTotal());
    const payDue = () => (corp.vouchers.length ? corpPlan().rest : payTotal());
    function renderQuick() {
        const total = payDue();
        const round = (n) => Math.ceil(total / n) * n;
        $('[data-quick]').innerHTML = [...new Set([Math.ceil(total), round(50), round(100), round(200)])]
            .map((v) => `<button type="button" class="btn-ghost min-h-9 px-3" data-quick-v="${v}">${v}</button>`).join('');
    }
    let corpReset = () => {};
    function openPay(target) {
        payTarget = target;
        const total = target ? target.total : totals().total;
        $('[data-pay-total]').textContent = fmt(total);
        $('[data-tendered]').value = '';
        $('[data-change]').textContent = '';
        $('[data-pay-error]').classList.add('hidden');
        if ($('[data-credit-phone]')) {
            $('[data-credit-phone]').value = target ? '' : $('[data-customer-phone]').value.trim();
            $('[data-credit-info]').textContent = T.enterPhone;
        }
        corpReset();
        split = null;
        renderSplit();
        renderQuick();
        setMethod('cash');
        payDialog.showModal();
    }
    function setMethod(m) {
        method = m;
        $$('[data-method]').forEach((b) => {
            b.classList.toggle('ring-2', b.dataset.method === m);
            b.classList.toggle('ring-brand-500', b.dataset.method === m);
        });
        $('[data-cash-box]').classList.toggle('hidden', m !== 'cash' && !(split || []).some((p) => p.method === 'cash'));
        if (split) renderSplit();
        $('[data-credit-box]')?.classList.toggle('hidden', m !== 'credit');
        if (m === 'credit') $('[data-credit-phone]').value ? creditLookup() : $('[data-credit-phone]').focus();
    }

    // ---- paying from the guest's prepaid balance: show it before confirming ----
    async function creditLookup() {
        const phone = $('[data-credit-phone]').value.trim();
        const info = $('[data-credit-info]');
        if (!phone) { info.textContent = T.enterPhone; return; }
        try {
            const c = await getJson(D.urls.walletLookup + '?phone=' + encodeURIComponent(phone));
            $('[data-credit-phone]').value = c.phone;
            const total = payTarget ? payTarget.total : totals().total;
            info.innerHTML = !c.found ? `<span class="text-red-600">${esc(T.noWallet)}</span>`
                : `${esc(c.name || c.phone)} · ${esc(T.balance.replace('__A__', fmt(c.balance)))}`
                    + (c.balance + 0.001 < total ? `<div class="mt-1 text-red-600">${esc(T.notEnough.replace('__A__', fmt(c.balance)))}</div>` : '');
        } catch (e) {
            info.innerHTML = `<span class="text-red-600">${esc(e.message)}</span>`;
        }
    }
    $('[data-credit-lookup]')?.addEventListener('click', creditLookup);
    $('[data-credit-phone]')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); creditLookup(); } });
    $$('[data-method]').forEach((b) => b.addEventListener('click', () => setMethod(b.dataset.method)));
    // ---- split payment: several methods for one order (cash + card + InstaPay …); what is left goes on the chosen method ----
    let split = null; // null = one method; else [{method, amount}]
    const splitSum = () => Math.round((split || []).reduce((s, p) => s + p.amount, 0) * 100) / 100;
    const splitRest = () => Math.max(0, Math.round((payDue() - splitSum()) * 100) / 100);
    // the cash the guest hands over covers the cash parts (and the rest, when it goes on cash)
    const cashDue = () => (split ? split.filter((p) => p.method === 'cash').reduce((s, p) => s + p.amount, 0) + (method === 'cash' ? splitRest() : 0) : payDue());
    function renderSplit() {
        const box = $('[data-split-box]');
        if (!box) return;
        box.classList.toggle('hidden', !split);
        $('[data-split-toggle]').setAttribute('aria-pressed', split ? 'true' : 'false');
        $('[data-split-toggle]').classList.toggle('ring-2', !!split);
        $('[data-method="credit"]')?.classList.toggle('hidden', !!split);
        if (!split) return;
        $('[data-split-list]').innerHTML = split.map((p, i) => `<li class="flex items-center justify-between gap-2 py-1.5">
            <span class="font-semibold">${esc(T.methods[p.method] || p.method)}</span>
            <span class="flex items-center gap-1"><b>${fmt(p.amount)}</b><button type="button" class="grid size-11 place-items-center rounded-lg text-red-600 hover:bg-red-50" data-split-remove="${i}" aria-label="${esc(T.remove)}">×</button></span></li>`).join('');
        const rest = splitRest();
        $('[data-split-rest]').textContent = rest > 0.004 ? T.splitRest.replace('__A__', fmt(rest)).replace('__M__', T.methods[method] || method) : T.splitDone;
        $('[data-split-amount]').placeholder = rest > 0 ? String(rest) : '';
        showChange();
    }
    $('[data-split-toggle]')?.addEventListener('click', () => {
        split = split ? null : [];
        if (split && method === 'credit') setMethod('cash');
        renderSplit();
        if (split) $('[data-split-amount]').focus();
    });
    function addSplitPart() {
        const rest = splitRest();
        const amount = Math.round(Math.min(rest, Number($('[data-split-amount]').value || rest)) * 100) / 100;
        if (!(amount > 0) || !T.methods[method]) return;
        split.push({ method, amount });
        $('[data-split-amount]').value = '';
        renderSplit();
    }
    $('[data-split-add]')?.addEventListener('click', addSplitPart);
    $('[data-split-amount]')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addSplitPart(); } });
    $('[data-split-list]')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-split-remove]');
        if (b) { split.splice(Number(b.dataset.splitRemove), 1); renderSplit(); }
    });
    /** the parts to send (null = one method): the rest goes on the chosen method; cash keeps what was handed over */
    function splitParts() {
        if (!split || !split.length) return null;
        const parts = split.map((p) => ({ ...p }));
        const rest = splitRest();
        if (rest > 0.004) parts.push({ method, amount: rest });
        const tendered = $('[data-tendered]').value;
        const cash = parts.find((p) => p.method === 'cash');
        if (cash && tendered !== '') cash.tendered = Math.max(0, Number(tendered) - parts.filter((p) => p.method === 'cash' && p !== cash).reduce((s, p) => s + p.amount, 0));
        return parts;
    }

    function showChange() {
        const total = cashDue();
        const diff = Number($('[data-tendered]').value || 0) - total;
        $('[data-change]').textContent = $('[data-tendered]').value === '' ? '' : diff >= 0 ? T.change.replace('__A__', fmt(diff)) : T.short.replace('__A__', fmt(-diff));
        $('[data-change]').className = 'text-lg font-bold ' + (diff >= 0 ? 'text-emerald-700' : 'text-red-600');
    }
    $('[data-tendered]').addEventListener('input', showChange);
    $('[data-quick]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-quick-v]');
        if (b) { $('[data-tendered]').value = b.dataset.quickV; showChange(); }
    });
    $('[data-pay]')?.addEventListener('click', () => { if (canSubmit()) openPay(null); });
    $('[data-pay-confirm]').addEventListener('click', async () => {
        const err = $('[data-pay-error]');
        err.classList.add('hidden');
        const creditPhone = method === 'credit' ? $('[data-credit-phone]').value.trim() : '';
        if (method === 'credit' && !creditPhone) { err.textContent = T.enterPhone; err.classList.remove('hidden'); return; }
        // corporate vouchers pay first; the method chosen pays the rest (or "corporate" when they cover it all)
        const vouchers = corp.vouchers.map((v) => (v.code ? { code: v.code, approval: v.approval || null } : { id: v.id, approval: v.approval || null }));
        const extra = vouchers.length ? { vouchers, voucher_phone: corp.phone } : {};
        const parts = vouchers.length && corpPlan().rest <= 0.004 ? null : splitParts();
        if (parts && parts.some((p) => !T.methods[p.method])) { err.textContent = T.splitMethods; err.classList.remove('hidden'); return; }
        const single = parts ? [...new Set(parts.map((p) => p.method))] : [];
        const payMethod = vouchers.length && corpPlan().rest <= 0.004 ? 'corporate' : parts ? (single.length > 1 ? 'split' : single[0]) : method;
        if (parts) extra.payments = parts;
        if (vouchers.length && payMethod === 'credit') { err.textContent = T.corpNoCredit; err.classList.remove('hidden'); return; }
        const saleUuid = uuid();
        const fail = (text) => { err.textContent = text; err.classList.remove('hidden'); };
        // no internet: the sale is kept on this device and synced later (cash, card, InstaPay, mobile wallet only)
        if (off && !off.online && !payTarget) return sellOffline(payMethod, vouchers.length > 0, saleUuid, fail, parts);
        try {
            if (payTarget) {
                const r = await api(D.urls.pay.replace('__ID__', payTarget.id), { method: payMethod, phone: creditPhone || null, ...extra });
                payDialog.close();
                $('[data-open-dialog]').close();
                done({ id: payTarget.id, number: payTarget.number, receipt_url: r.receipt_url, phone: r.phone }, T.paid);
            } else {
                if (creditPhone) $('[data-customer-phone]').value = creditPhone;
                const order = await api(D.urls.order, { ...payload(payMethod, saleUuid), ...extra });
                payDialog.close();
                done(order, T.paid);
            }
        } catch (e) {
            // the connection dropped on the way: keep it offline under the same id (the server saves it once)
            if (e instanceof NetworkError && off && !payTarget) return sellOffline(payMethod, vouchers.length > 0, saleUuid, fail, parts);
            fail(e.message);
        }
    });

    // ---- a sale made offline: priced from the catalog kept on the device, numbered OFF-{terminal}-{n} ----
    const r2 = (n) => Math.round(n * 100) / 100;
    async function sellOffline(payMethod, withVouchers, clientUuid, fail, parts = null) {
        if (withVouchers || payMethod === 'corporate') return fail(T.offNoVouchers);
        if (payMethod === 'credit') return fail(T.offNoCredit);
        if (!(parts ? parts.every((p) => D.offlineMethods.includes(p.method)) : D.offlineMethods.includes(payMethod))) return fail(T.offMethods);
        if (state.coupon) return fail(T.offNoCoupon);
        const blocked = off.blockReason(D.shift);
        if (blocked) return fail(T['offBlock_' + blocked] || T.noInternet);
        const all = D.items.flatMap((i) => i.groups.flatMap((g) => g.options.map((o) => ({ ...o, group: g.name }))));
        const lines = state.lines.map((l) => {
            const item = items[l.item_id];
            return {
                item_id: item.id, qty: l.qty, name: item.name, unit_price: r2(unit(l)), option_ids: l.option_ids, notes: l.notes || null,
                options: l.option_ids.map((id) => all.find((o) => o.id === id)).filter(Boolean).map((o) => ({ id: o.id, group: o.group, name: o.name, price: o.price })),
            };
        });
        const subtotal = r2(lines.reduce((sum, l) => sum + r2(l.unit_price * l.qty), 0));
        const discount = r2(Math.min(subtotal, totals().staff));
        const service = state.type === 'dine_in' ? r2((subtotal - discount) * D.service / 100) : 0;
        const tax = r2((subtotal - discount + service) * D.tax / 100);
        const total = r2(subtotal - discount + service + tax);
        const tendered = payMethod === 'cash' && $('[data-tendered]').value !== '' ? Number($('[data-tendered]').value) : null;
        const now = new Date().toISOString();
        const sale = {
            client_uuid: clientUuid, offline_ref: off.nextRef(), created_offline_at: now, offline_since: off.offlineSince() || now,
            shift_id: D.shift.id, type: state.type, table_id: state.type === 'dine_in' ? Number(state.table) || null : null, items: lines,
            customer_name: $('[data-customer-name]').value.trim(), customer_phone: $('[data-customer-phone]').value.trim(), notes: $('[data-notes]').value.trim(),
            discount, subtotal, service, tax, total, method: payMethod, tendered,
        };
        if (parts) {
            // the parts were taken from the displayed total: the last one absorbs any rounding difference
            const diff = Math.round((total - parts.reduce((s, p) => s + p.amount, 0)) * 100) / 100;
            parts[parts.length - 1].amount = Math.round((parts[parts.length - 1].amount + diff) * 100) / 100;
            sale.payments = parts;
        }
        try {
            await off.queue({ client_uuid: clientUuid, offline_ref: sale.offline_ref, created_offline_at: now, total, payload: sale });
        } catch {
            return fail(T.offSaveFailed);
        }
        payDialog.close();
        lastOrderId = null;
        lastOffline = { payload: sale };
        lastShare = null;
        $('[data-share-wa]')?.classList.add('hidden');
        $('[data-done-number]').textContent = sale.offline_ref;
        $('[data-done-status]').textContent = T.savedOffline;
        reset();
        doneDialog.showModal();
    }

    // ---- corporate vouchers: scan / type the code or the guest's mobile, refusals with their reason, exceptions ----
    if (D.corporate) {
        const box = $('[data-corp-panel]');
        const errBox = $('[data-corp-error]');
        const showErr = (text) => { errBox.textContent = text || ''; errBox.classList.toggle('hidden', !text); };
        let found = []; // the last lookup
        let lookupPhone = null;
        corpReset = () => {
            corp = { vouchers: [], phone: null };
            found = [];
            lookupPhone = null;
            $('[data-corp-query]').value = '';
            $('[data-corp-results]').innerHTML = '';
            showErr('');
            box.classList.add('hidden');
            renderCorp();
        };
        $('[data-corp-toggle]').addEventListener('click', () => {
            box.classList.toggle('hidden');
            if (!box.classList.contains('hidden')) $('[data-corp-query]').focus();
        });

        function renderCorp() {
            const applied = $('[data-corp-applied]');
            const due = $('[data-pay-due]');
            if (!corp.vouchers.length) {
                applied.classList.add('hidden');
                due.classList.add('hidden');
                $('[data-corp-count]').textContent = '';
                $('[data-methods]').classList.remove('opacity-40');
                return;
            }
            const plan = corpPlan();
            applied.classList.remove('hidden');
            applied.innerHTML = plan.lines.map((l, i) => `
                <div class="flex items-start justify-between gap-2 py-1">
                    <div><b dir="ltr">${esc(l.serial)}</b> · ${esc(l.company)}${l.approval ? ` <span class="badge bg-amber-100 text-amber-800">${esc(T.corpException)}</span>` : ''}
                        ${l.used <= 0 ? `<div class="text-xs text-navy-500">${esc(T.corpNotNeeded)}</div>` : ''}
                        ${l.lost > 0 ? `<div class="text-xs font-semibold text-amber-700">⚠ ${esc(T.corpLost.replace('__A__', fmt(l.lost)))}</div>` : ''}</div>
                    <div class="flex items-center gap-1"><b>${l.used > 0 ? '−' + fmt(l.used) : ''}</b>
                        <button type="button" class="grid size-11 place-items-center rounded-lg text-red-600 hover:bg-red-50" data-corp-remove="${l.id}" aria-label="${esc(T.remove)}">×</button></div>
                </div>`).join('');
            $('[data-corp-count]').textContent = String(corp.vouchers.length);
            due.classList.remove('hidden');
            due.innerHTML = plan.rest > 0.004 ? esc(T.corpRest.replace('__A__', fmt(plan.rest))) : `<span class="text-emerald-700">${esc(T.corpCovered)}</span>`;
            $('[data-methods]').classList.toggle('opacity-40', plan.rest <= 0.004);
            renderQuick();
            showChange();
        }
        $('[data-corp-applied]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-corp-remove]');
            if (!b) return;
            corp.vouchers = corp.vouchers.filter((v) => String(v.id) !== b.dataset.corpRemove);
            if (!corp.vouchers.some((v) => !v.code)) corp.phone = null;
            renderCorp();
            renderFound();
        });

        function renderFound() {
            const inUse = new Set(corp.vouchers.map((v) => v.id));
            $('[data-corp-results]').innerHTML = found.map((v, i) => `
                <li class="rounded-xl border ${v.ok ? 'border-emerald-200 bg-emerald-50/40' : 'border-navy-100'} p-3" data-corp-voucher="${esc(v.serial)}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-bold"><span dir="ltr">${esc(v.serial)}</span> · ${esc(v.company)}</div>
                            <div class="text-sm">${esc(T.corpBalance.replace('__B__', fmt(v.balance)).replace('__V__', fmt(v.value)))} · ${esc(v.modeLabel)}</div>
                            <div class="text-xs text-navy-500">${esc(T.corpUntil.replace('__D__', v.expires))}${v.linked ? ' · 🔒 ' + esc(v.linked) : ''}${v.pending ? ' · ⏳ ' + esc(v.pending) : ''}</div>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            ${v.ok && !inUse.has(v.id) ? `<button type="button" class="btn-primary min-h-11 px-4" data-corp-use="${i}">${esc(T.corpUse)}</button>` : ''}
                            ${inUse.has(v.id) ? `<span class="badge bg-emerald-100 text-emerald-800">✓ ${esc(T.corpAdded)}</span>` : ''}
                            ${v.expired && !inUse.has(v.id) ? `<button type="button" class="btn-ghost min-h-11 px-3 text-amber-800" data-corp-exception="${i}">${esc(T.corpAskException)}</button>` : ''}
                            ${v.pending && lookupPhone ? `<button type="button" class="btn-ghost min-h-11 px-3" data-corp-confirm="${i}">${esc(T.corpConfirmLink)}</button>` : ''}
                            ${v.code && !v.linked && v.ok ? `<button type="button" class="btn-ghost min-h-11 px-3" data-corp-link="${i}">${esc(T.corpLink)}</button>` : ''}
                        </div>
                    </div>
                    ${v.reason ? `<div class="mt-2 rounded-lg ${v.ok ? 'bg-emerald-50 text-emerald-800' : v.expired ? 'bg-amber-50 text-amber-800' : 'bg-red-50 text-red-700'} px-2 py-1 text-sm font-semibold" data-corp-reason>${esc(v.reason)}</div>` : ''}
                    ${v.ok && v.mode === 'single' && v.balance > payTotal() + 0.004 ? `<div class="mt-2 text-xs font-semibold text-amber-700">⚠ ${esc(T.corpSingleWarn.replace('__A__', fmt(v.balance - payTotal())))}</div>` : ''}
                </li>`).join('');
        }

        async function lookup(raw) {
            const query = (raw ?? $('[data-corp-query]').value).trim();
            showErr('');
            if (!query) return;
            try {
                const r = await api(D.urls.corporateCheck, { query, total: payTotal() });
                lookupPhone = r.by === 'phone' ? r.phone : null;
                found = r.vouchers.map((v) => (r.by === 'code' ? { ...v, code: v.code } : { ...v, code: null }));
                if (r.by === 'phone' && !found.length) showErr(T.corpNoneForPhone);
                renderFound();
                // a single good voucher scanned / typed goes straight onto the payment
                if (r.by === 'code' && found.length === 1 && found[0].ok) use(0);
                $('[data-corp-query]').value = '';
            } catch (e) {
                found = [];
                renderFound();
                showErr(e.message);
            }
        }
        function use(i, approval = null) {
            const v = found[i];
            if (!v || corp.vouchers.some((x) => x.id === v.id)) return;
            if (!v.code) {
                if (corp.phone && corp.phone !== lookupPhone) corp.vouchers = corp.vouchers.filter((x) => x.code);
                corp.phone = lookupPhone;
            }
            corp.vouchers.push({ id: v.id, code: v.code, serial: v.serial, company: v.company, balance: v.balance, mode: v.mode, expiresSort: v.expiresSort, approval });
            renderCorp();
            renderFound();
        }
        $('[data-corp-check]').addEventListener('click', () => lookup());
        $('[data-corp-query]').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });
        $('[data-corp-results]').addEventListener('click', async (e) => {
            const useBtn = e.target.closest('[data-corp-use]');
            if (useBtn) return use(Number(useBtn.dataset.corpUse));
            const exc = e.target.closest('[data-corp-exception]');
            if (exc) return openException(Number(exc.dataset.corpException));
            const link = e.target.closest('[data-corp-link], [data-corp-confirm]');
            if (!link) return;
            const v = found[Number(link.dataset.corpLink ?? link.dataset.corpConfirm)];
            const phone = link.dataset.corpConfirm !== undefined ? lookupPhone : (prompt(T.corpLinkAsk, $('[data-customer-phone]').value.trim()) || '').trim();
            if (!phone) return;
            try {
                await api(D.urls.corporateLink, v.code ? { code: v.code, phone, total: payTotal() } : { id: v.id, phone, total: payTotal() });
                corp.vouchers = corp.vouchers.filter((x) => x.id !== v.id);
                await lookup(phone); // from now on it goes with the mobile
            } catch (err) {
                showErr(err.message);
            }
        });

        // camera scan where the browser can read QR codes (same as the loyalty counter); a USB scanner types into the field
        if (canScanQr()) {
            const scan = $('[data-corp-scan]');
            const video = $('[data-corp-video]');
            let stop = null;
            scan.classList.remove('hidden');
            scan.addEventListener('click', async () => {
                if (stop) { stop(); stop = null; return; }
                stop = await scanQr(video, (code) => { stop = null; lookup(code); }, () => { stop = null; showErr(T.corpNoCamera); });
            });
            payDialog.addEventListener('close', () => { stop?.(); stop = null; });
        }

        // ---- exception after expiry: a manager approves here (signed in) or with their PIN on this device ----
        const excDialog = $('[data-corp-exception-dialog]');
        let excIndex = null;
        function openException(i) {
            excIndex = i;
            const v = found[i];
            $('[data-exc-voucher]').textContent = `${v.serial} · ${v.company} · ${v.reason}`;
            $('[data-exc-reason]').value = '';
            if ($('[data-exc-pin]')) $('[data-exc-pin]').value = '';
            $('[data-exc-error]').classList.add('hidden');
            excDialog.showModal();
            $('[data-exc-reason]').focus();
        }
        $('[data-exc-confirm]').addEventListener('click', async (e) => {
            const v = found[excIndex];
            const err = $('[data-exc-error]');
            err.classList.add('hidden');
            const body = { reason: $('[data-exc-reason]').value.trim() };
            if (!D.corporateApprove) {
                body.approver_id = Number($('[data-exc-manager]')?.value) || null;
                body.pin = $('[data-exc-pin]')?.value || '';
            }
            const btn = e.currentTarget;
            btn.disabled = true;
            try {
                const r = await api(D.urls.corporateException.replace('__ID__', v.id), body);
                excDialog.close();
                found[excIndex] = { ...v, ok: true, expired: false, reason: T.corpApprovedBy.replace('__N__', r.approver) };
                use(excIndex, r.token);
            } catch (ex) {
                err.textContent = ex.message;
                err.classList.remove('hidden');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- wallet: top-up and gift cards (taken through the open shift, like a sale) ----
    if (D.walletManage) {
        const pick = (attr, value) => $$(`[${attr}]`).forEach((b) => {
            b.classList.toggle('ring-2', b.getAttribute(attr) === value);
            b.classList.toggle('ring-brand-500', b.getAttribute(attr) === value);
        });
        const show = (el, text) => { el.textContent = text; el.classList.toggle('hidden', !text); };

        const topup = $('[data-topup-dialog]');
        let topupMethod = 'cash';
        $('[data-topup-open]').addEventListener('click', () => {
            ['[data-topup-phone]', '[data-topup-name]', '[data-topup-amount]'].forEach((s) => ($(s).value = ''));
            ['[data-topup-info]', '[data-topup-error]', '[data-topup-done]'].forEach((s) => show($(s), ''));
            topupMethod = 'cash';
            pick('data-topup-method', 'cash');
            topup.showModal();
            $('[data-topup-phone]').focus();
        });
        async function topupLookup() {
            const phone = $('[data-topup-phone]').value.trim();
            if (!phone) return;
            try {
                const c = await getJson(D.urls.walletLookup + '?phone=' + encodeURIComponent(phone));
                $('[data-topup-phone]').value = c.phone;
                if (c.found && c.name) $('[data-topup-name]').value = c.name;
                show($('[data-topup-info]'), c.found ? `${c.name || c.phone} · ${T.balance.replace('__A__', fmt(c.balance))}` : T.newGuest);
            } catch (e) {
                show($('[data-topup-info]'), e.message);
            }
        }
        $('[data-topup-lookup]').addEventListener('click', topupLookup);
        $('[data-topup-phone]').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); topupLookup(); } });
        $$('[data-topup-quick]').forEach((b) => b.addEventListener('click', () => ($('[data-topup-amount]').value = b.dataset.topupQuick)));
        $$('[data-topup-method]').forEach((b) => b.addEventListener('click', () => { topupMethod = b.dataset.topupMethod; pick('data-topup-method', topupMethod); }));
        $('[data-topup-confirm]').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            show($('[data-topup-error]'), '');
            show($('[data-topup-done]'), '');
            if (!$('[data-topup-phone]').value.trim()) return show($('[data-topup-error]'), T.enterPhone);
            if (!(Number($('[data-topup-amount]').value) > 0)) return show($('[data-topup-error]'), T.enterAmount);
            btn.disabled = true;
            try {
                const r = await api(D.urls.topup, {
                    phone: $('[data-topup-phone]').value.trim(), name: $('[data-topup-name]').value.trim(),
                    amount: Number($('[data-topup-amount]').value), method: topupMethod,
                });
                $('[data-topup-amount]').value = '';
                const done = $('[data-topup-done]');
                done.innerHTML = `${esc(T.topupDone.replace('__A__', fmt(r.balance)))} · <a href="${esc(r.receipt)}" target="_blank" class="underline" data-slip>${esc(T.printSlip)}</a>`;
                done.classList.remove('hidden');
                show($('[data-topup-info]'), `${r.name || r.phone} · ${T.balance.replace('__A__', fmt(r.balance))}`);
            } catch (err) {
                show($('[data-topup-error]'), err.message);
            } finally {
                btn.disabled = false;
            }
        });

        const gift = $('[data-gift-dialog]');
        let giftMethod = 'cash';
        $('[data-gift-open]').addEventListener('click', () => {
            ['[data-gift-amount]', '[data-gift-phone]', '[data-gift-message]'].forEach((s) => ($(s).value = ''));
            show($('[data-gift-error]'), '');
            giftMethod = 'cash';
            pick('data-gift-method', 'cash');
            gift.showModal();
            $('[data-gift-amount]').focus();
        });
        $$('[data-gift-quick]').forEach((b) => b.addEventListener('click', () => ($('[data-gift-amount]').value = b.dataset.giftQuick)));
        $$('[data-gift-method]').forEach((b) => b.addEventListener('click', () => { giftMethod = b.dataset.giftMethod; pick('data-gift-method', giftMethod); }));
        $('[data-gift-confirm]').addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            show($('[data-gift-error]'), '');
            if (!(Number($('[data-gift-amount]').value) > 0)) return show($('[data-gift-error]'), T.enterAmount);
            btn.disabled = true;
            const win = window.open('about:blank', '_blank', 'width=420,height=700'); // opened on the tap so it is not blocked
            try {
                const r = await api(D.urls.giftSell, {
                    amount: Number($('[data-gift-amount]').value), method: giftMethod,
                    recipient_phone: $('[data-gift-phone]').value.trim() || null, message: $('[data-gift-message]').value.trim() || null,
                });
                if (win) win.location = r.print; else window.open(r.print, '_blank');
                gift.close();
            } catch (err) {
                win?.close();
                show($('[data-gift-error]'), err.message);
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- open (unpaid) orders ----
    async function refreshOpen() {
        try {
            const { orders } = await getJson(D.urls.open);
            $('[data-open-count]').textContent = orders.length;
            $('[data-open-list]').innerHTML = orders.length ? orders.map((o) => `
                <li class="flex items-center justify-between gap-3 py-3">
                    <div><b>#${o.number}</b> · ${esc(o.table ? T.table.replace('__N__', o.table) : T.takeaway)}
                        <div class="text-xs text-navy-500">${esc(o.status)} · ${esc(o.time)}</div></div>
                    <div class="flex items-center gap-2"><b>${fmt(o.total)}</b>
                        <button type="button" class="btn-primary min-h-9 px-3" data-pay-open='${JSON.stringify({ id: o.id, number: o.number, total: o.total })}'>${esc(T.pay)}</button></div>
                </li>`).join('') : `<li class="py-8 text-center text-navy-500">${esc(T.noOpen)}</li>`;
        } catch {}
    }
    $('[data-open-orders]').addEventListener('click', () => { refreshOpen(); $('[data-open-dialog]').showModal(); });
    $('[data-open-list]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-pay-open]');
        if (b) openPay(JSON.parse(b.dataset.payOpen));
    });

    $('[data-customer-display]').addEventListener('click', () => {
        window.open(D.urls.display, 'egmenu-display', 'width=900,height=600');
        setTimeout(render, 800);
    });

    // phones: the ticket is a sheet over the menu, opened from the bottom bar
    const ticket = $('[data-ticket]');
    const ticketBackdrop = $('[data-ticket-backdrop]');
    const setTicket = (open) => {
        ticket?.toggleAttribute('data-open', open);
        ticketBackdrop?.toggleAttribute('data-open', open);
        $('[data-ticket-open]')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    $('[data-ticket-open]')?.addEventListener('click', () => setTicket(true));
    $$('[data-ticket-close]').forEach((b) => b.addEventListener('click', () => setTicket(false)));
    ticketBackdrop?.addEventListener('click', () => setTicket(false));

    // ---- offline mode: status bar, terminal of this device, sync log ----
    if (off) {
        const net = $('[data-net]');
        const banner = $('[data-offline-banner]');
        const syncBtn = $('[data-sync-open]');
        const fmtTime = (iso) => new Date(iso).toLocaleTimeString('ar-EG', { hour: 'numeric', minute: '2-digit' });
        function renderNet() {
            const pending = off.pending().length;
            const refused = off.log.filter((e) => e.status === 'refused').length;
            net.textContent = off.online ? T.online : T.offline;
            net.className = 'badge min-h-9 px-3 ' + (off.online ? 'bg-emerald-100 text-emerald-800' : 'bg-red-600 text-white');
            net.dataset.state = off.online ? 'online' : 'offline';
            syncBtn.classList.toggle('hidden', !off.log.length && off.online);
            $('[data-sync-count]').textContent = off.syncing && pending ? T.syncing : refused ? T.refusedCount.replace('__N__', refused) : T.pendingCount.replace('__N__', pending);
            syncBtn.classList.toggle('bg-amber-100', pending > 0 && !refused);
            syncBtn.classList.toggle('bg-red-100', refused > 0);
            const blocked = off.online ? null : off.blockReason(D.shift);
            banner.classList.toggle('hidden', off.online);
            banner.textContent = off.online ? '' : blocked ? T['offBlock_' + blocked] : T.offlineBanner.replace('__H__', D.offlineHours);
            banner.classList.toggle('bg-red-50', !!blocked);
            banner.classList.toggle('text-red-800', !!blocked);
            // what cannot work without internet is switched off, with a short note in the payment window
            $$('[data-method="credit"], [data-corp-toggle], [data-coupon-apply], [data-topup-open], [data-gift-open], [data-send]').forEach((b) => { b.disabled = !off.online; });
            $('[data-pay-offline]')?.classList.toggle('hidden', off.online);
            const t = off.terminal();
            $('[data-terminal-label]').textContent = t ? `${t.code} · ${t.name}` : T.pickTerminal;
            $('[data-terminal-open]').classList.toggle('ring-2', !t && D.terminals.length > 0);
            if ($('[data-sync-dialog]').open) renderLog();
        }
        const statusLabel = (e) => ({ pending: T.stPending, synced: T.stSynced, refused: T.stRefused, resolved: T.stResolved })[e.status] || e.status;
        function renderLog() {
            $('[data-sync-list]').innerHTML = off.log.length ? off.log.map((e) => `
                <li class="py-3" data-sync-entry="${esc(e.offline_ref)}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><b dir="ltr">${esc(e.offline_ref)}</b>${e.number ? ` → <b>#${e.number}</b>` : ''} · ${fmt(e.total)} <span class="text-xs text-navy-500">${esc(fmtTime(e.created_offline_at))}</span></div>
                        <span class="badge ${e.status === 'synced' ? 'bg-emerald-100 text-emerald-800' : e.status === 'refused' ? 'bg-red-100 text-red-700' : e.status === 'resolved' ? 'bg-navy-100 text-navy-700' : 'bg-amber-100 text-amber-800'}">${esc(statusLabel(e))}</span>
                    </div>
                    ${e.number ? `<div class="text-xs text-navy-500">${esc(T.wasRef.replace('__N__', e.number).replace('__R__', e.offline_ref))}${e.review ? ' · ⚠ ' + esc(T.needsReview) : ''}</div>` : ''}
                    ${e.error && e.status !== 'synced' ? `<div class="mt-1 rounded-lg bg-red-50 px-2 py-1 text-sm font-semibold text-red-700">${esc(e.error)}</div>` : ''}
                    ${e.resolved_note ? `<div class="mt-1 text-xs text-navy-500">${esc(e.resolved_note)}</div>` : ''}
                    ${e.status === 'refused' ? `<div class="mt-2 flex gap-2"><button type="button" class="btn-ghost min-h-11 px-3" data-sync-retry="${esc(e.client_uuid)}">${esc(T.retry)}</button>
                        <button type="button" class="btn-ghost min-h-11 px-3" data-sync-resolve="${esc(e.client_uuid)}">${esc(T.markResolved)}</button></div>` : ''}
                </li>`).join('') : `<li class="py-8 text-center text-navy-500">${esc(T.noQueued)}</li>`;
        }
        syncBtn.addEventListener('click', () => { renderLog(); $('[data-sync-dialog]').showModal(); });
        $('[data-sync-now]').addEventListener('click', () => off.ping().then(() => off.sync()));
        $('[data-sync-list]').addEventListener('click', (e) => {
            const retry = e.target.closest('[data-sync-retry]');
            if (retry) return off.retry(retry.dataset.syncRetry);
            const res = e.target.closest('[data-sync-resolve]');
            if (res && confirm(T.resolveAsk)) off.resolve(res.dataset.syncResolve, prompt(T.resolveNote) || '');
        });

        // ---- this device's terminal ----
        const termDialog = $('[data-terminal-dialog]');
        function renderTerminals() {
            const mine = off.terminal();
            $('[data-terminal-list]').innerHTML = D.terminals.length ? D.terminals.map((t) => `
                <li class="flex items-center justify-between gap-2 py-2">
                    <div><span class="badge bg-navy-800 text-white" dir="ltr">${esc(t.code)}</span> <b>${esc(t.name)}</b>
                        ${mine && mine.id === t.id ? `<span class="badge bg-emerald-100 text-emerald-800">${esc(T.thisDevice)}</span>` : t.claimed ? `<span class="text-xs text-navy-500">${esc(T.otherDevice)}</span>` : ''}</div>
                    ${mine && mine.id === t.id ? '' : `<button type="button" class="btn-primary min-h-11 px-4" data-claim="${t.id}" data-claimed="${t.claimed ? 1 : 0}">${esc(T.useTerminal)}</button>`}
                </li>`).join('') : `<li class="py-6 text-center text-navy-500">${esc(T.noTerminals)}</li>`;
        }
        $('[data-terminal-open]').addEventListener('click', () => { renderTerminals(); $('[data-terminal-error]').classList.add('hidden'); termDialog.showModal(); });
        $('[data-terminal-list]').addEventListener('click', async (e) => {
            const b = e.target.closest('[data-claim]');
            if (!b) return;
            const err = $('[data-terminal-error]');
            err.classList.add('hidden');
            const takeover = b.dataset.claimed === '1';
            if (takeover && !confirm(T.takeoverAsk)) return;
            try {
                const r = await api(D.urls.claim.replace('__ID__', b.dataset.claim), { takeover, device: navigator.userAgent.slice(0, 120) });
                off.setTerminal(r);
                D.terminals = D.terminals.map((t) => (t.id === r.id ? { ...t, claimed: true } : t));
                termDialog.close();
            } catch (ex) {
                err.textContent = ex.message;
                err.classList.remove('hidden');
            }
        });

        // ---- the catalog: refreshed while online, read back from the device when the page opened offline ----
        function applyCatalog(c) {
            if (!c) return;
            ['items', 'categories', 'tables', 'terminals', 'service', 'tax', 'shift', 'offlineHours', 'receiptHeader'].forEach((k) => { if (k in c) D[k] = c[k]; });
            Object.keys(items).forEach((k) => delete items[k]);
            D.items.forEach((i) => { items[i.id] = i; });
            state.lines = state.lines.filter((l) => items[l.item_id]);
            renderCats();
            renderItems();
            render();
            renderNet();
        }
        async function refreshCatalog() {
            try {
                applyCatalog(await off.refreshCatalog());
            } catch {}
        }
        off.onChange(renderNet);
        (async () => {
            const saved = await off.loadCatalog();
            if (saved && saved.fetchedAt > D.generatedAt) applyCatalog(saved);
            if (await off.ping()) {
                off.saveShell();
                refreshCatalog();
            }
            off.start();
            renderNet();
        })();
        setInterval(() => { if (off.online) refreshCatalog(); }, 5 * 60000);
        renderNet();
    }

    // ---- held tickets ("تعليق"): on the server for every terminal of the branch, on the device while offline ----
    let refreshHolds = async () => {};
    if (!CC && $('[data-hold]')) {
        const holdDialog = $('[data-hold-dialog]');
        const holdsDialog = $('[data-holds-dialog]');
        let holds = [];
        const cartNow = () => ({
            type: state.type, table: state.table || null, lines: state.lines.map((l) => ({ ...l })),
            customer_name: $('[data-customer-name]').value.trim(), customer_phone: $('[data-customer-phone]').value.trim(), notes: $('[data-notes]').value.trim(),
            discount: Number($('[data-discount]')?.value || 0), discount_kind: $('[data-discount-kind]')?.value || 'amount',
        });
        $('[data-hold]').addEventListener('click', () => {
            if (!state.lines.length) return;
            const table = D.tables.find((x) => String(x.id) === String(state.table));
            $('[data-hold-label]').value = state.type === 'dine_in' && table ? T.table.replace('__N__', table.name) : $('[data-customer-name]').value.trim();
            $('[data-hold-error]').classList.add('hidden');
            holdDialog.showModal();
            $('[data-hold-label]').focus();
        });
        async function holdNow() {
            const label = $('[data-hold-label]').value.trim();
            const err = $('[data-hold-error]');
            if (!label) { err.textContent = T.holdName; err.classList.remove('hidden'); return; }
            const body = { label, total: Math.round(totals().total * 100) / 100, cart: cartNow() };
            const local = () => off?.saveLocalHold({ id: 'L' + uuid(), ...body, count: state.lines.reduce((n, l) => n + l.qty, 0), time: new Date().toISOString(), local: true });
            try {
                if (off && !off.online) await local();
                else await api(D.urls.holdsStore, body);
            } catch (e) {
                if (!(e instanceof NetworkError) || !off) { err.textContent = e.message; err.classList.remove('hidden'); return; }
                await local();
            }
            holdDialog.close();
            reset();
            refreshHolds();
        }
        $('[data-hold-confirm]').addEventListener('click', holdNow);
        $('[data-hold-label]').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); holdNow(); } });

        refreshHolds = async () => {
            let server = [];
            if (!off || off.online) {
                try { server = (await getJson(D.urls.holds)).holds; } catch {}
            }
            const local = off ? (await off.localHolds()).map((h) => ({ ...h, time: new Date(h.time).toLocaleTimeString('ar-EG', { hour: 'numeric', minute: '2-digit' }) })) : [];
            holds = [...local, ...server];
            $('[data-holds-count]').textContent = holds.length;
            $('[data-holds-list]').innerHTML = holds.length ? holds.map((h, i) => `
                <li class="flex items-center justify-between gap-3 py-3" data-held="${esc(h.label)}">
                    <div class="min-w-0"><b>${esc(h.label)}</b>${h.local ? ` <span class="badge bg-amber-100 text-amber-800">${esc(T.onThisDevice)}</span>` : ''}
                        <div class="text-xs text-navy-500">${esc(T.itemsCount.replace('__N__', h.count))} · ${esc(h.time)}${h.by ? ' · ' + esc(h.by) : ''}${h.terminal ? ' · ' + esc(h.terminal) : ''}</div></div>
                    <div class="flex shrink-0 items-center gap-2"><b>${fmt(h.total)}</b>
                        <button type="button" class="btn-primary min-h-11 px-4" data-resume="${i}">${esc(T.resume)}</button>
                        ${D.canDiscardHold || h.local ? `<button type="button" class="grid size-11 place-items-center rounded-lg text-red-600 hover:bg-red-50" data-discard="${i}" aria-label="${esc(T.discard)}" title="${esc(T.discard)}">🗑</button>` : ''}</div>
                </li>`).join('') : `<li class="py-8 text-center text-navy-500">${esc(T.noHolds)}</li>`;
        };
        function applyCart(cart) {
            state.type = cart.type === 'dine_in' ? 'dine_in' : 'takeaway';
            state.table = cart.table ? String(cart.table) : '';
            if ($('[data-table]')) $('[data-table]').value = state.table;
            state.lines = (cart.lines || []).filter((l) => items[l.item_id]).map((l) => ({ item_id: l.item_id, qty: l.qty, option_ids: l.option_ids || [], notes: l.notes || '' }));
            state.coupon = null;
            $('[data-customer-name]').value = cart.customer_name || '';
            $('[data-customer-phone]').value = cart.customer_phone || '';
            $('[data-notes]').value = cart.notes || '';
            if ($('[data-discount]')) $('[data-discount]').value = cart.discount || 0;
            if ($('[data-discount-kind]')) $('[data-discount-kind]').value = cart.discount_kind || 'amount';
            render();
        }
        $('[data-holds-open]').addEventListener('click', async () => { await refreshHolds(); holdsDialog.showModal(); });
        $('[data-holds-list]').addEventListener('click', async (e) => {
            const resume = e.target.closest('[data-resume]');
            const discard = e.target.closest('[data-discard]');
            const h = holds[Number((resume || discard)?.dataset.resume ?? (resume || discard)?.dataset.discard)];
            if (!h) return;
            try {
                if (resume) {
                    if (state.lines.length && !confirm(T.replaceAsk)) return;
                    if (h.local) {
                        await off.deleteLocalHold(h.id);
                        applyCart(h.cart);
                    } else {
                        applyCart((await api(D.urls.holdsResume.replace('__ID__', h.id), {})).cart);
                    }
                    holdsDialog.close();
                } else if (confirm(T.discardAsk)) {
                    if (h.local) await off.deleteLocalHold(h.id);
                    else await api(D.urls.holdsDiscard.replace('__ID__', h.id), {});
                }
            } catch (ex) {
                alert(ex.message);
            }
            refreshHolds();
        });
        refreshHolds();
    }

    // ---- the printers of the EgHub cashier app: a printer per role, a test page, kitchen tickets on this device ----
    if (printer && $('[data-printers-open]')) {
        const dlg = $('[data-printers-dialog]');
        const N = T.native || {};
        const typeLabel = { bluetooth: 'Bluetooth', usb: 'USB', lan: 'LAN', sunmi: 'Sunmi' };
        function renderPrinters() {
            const printers = printer.printers();
            const byRole = Object.fromEntries(printers.filter((p) => p.role).map((p) => [p.role, p]));
            $('[data-printers-roles]').innerHTML = Object.entries(printer.roles()).map(([role, label]) => {
                const p = byRole[role];
                return `<li class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div class="min-w-0"><b>${esc(label)}</b>
                        <div class="text-xs text-navy-500">${p ? esc(p.name) + ' · ' + esc(typeLabel[p.type] || p.type) + ' · ' + esc(p.width) + 'mm' : esc(N.noPrinter)}</div></div>
                    <div class="flex shrink-0 gap-1">
                        <button type="button" class="btn-ghost min-h-11 px-3" data-printer-choose="${esc(role)}">${esc(p ? N.change : N.choose)}</button>
                        ${p ? `<button type="button" class="btn-ghost min-h-11 px-3" data-printer-test="${esc(role)}">${esc(N.test)}</button>` : ''}
                    </div></li>`;
            }).join('');
            const kitchen = $('[data-printers-kitchen]');
            kitchen.checked = printer.deviceKitchen();
            $('[data-printers-kitchen-row]').classList.toggle('hidden', !D.printing.kitchenAuto);
        }
        $('[data-printers-open]').classList.remove('hidden');
        $('[data-printers-open]').addEventListener('click', () => { renderPrinters(); dlg.showModal(); });
        $('[data-printers-roles]').addEventListener('click', (e) => {
            const c = e.target.closest('[data-printer-choose]');
            if (c) { printer.choose(c.dataset.printerChoose); setTimeout(renderPrinters, 600); return; }
            const t = e.target.closest('[data-printer-test]');
            if (t) printer.test(t.dataset.printerTest);
        });
        $('[data-printers-kitchen]').addEventListener('change', (e) => { printer.setDeviceKitchen(e.target.checked); printer.poll(); });
        // the app is shown again (back from its own printer picker): refresh the list
        document.addEventListener('visibilitychange', () => { if (!document.hidden && dlg.open) renderPrinters(); });
    }

    // ---- recent paid orders: find by number or OFF-K1-3, reprint, share, refund with a manager's OK ----
    if (!CC && $('[data-orders-open]')) {
        const ordersDialog = $('[data-orders-dialog]');
        const refundDialog = $('[data-refund-dialog]');
        let recent = [];
        let target = null;
        let returning = {}; // order item id => quantity coming back
        const r2m = (n) => Math.round(n * 100) / 100;
        async function loadRecent() {
            const list = $('[data-orders-list]');
            try {
                recent = (await getJson(D.urls.recent + '?q=' + encodeURIComponent($('[data-orders-q]').value.trim()))).orders;
            } catch (e) {
                list.innerHTML = `<li class="py-8 text-center text-red-600">${esc(e.message)}</li>`;
                return;
            }
            list.innerHTML = recent.length ? recent.map((o, i) => `
                <li class="flex flex-wrap items-center justify-between gap-2 py-3" data-recent="${o.number}">
                    <div class="min-w-0"><b>${esc(o.ref)}</b>
                        ${o.refund_status ? `<span class="badge ${o.refund_status === 'full' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'}">${esc(o.refund_status === 'full' ? T.refundedFull : T.refundedPart.replace('__A__', fmt(o.refunded)))}</span>` : ''}
                        <div class="text-xs text-navy-500">${esc(o.time)} · ${esc(o.method)} · ${fmt(o.total)}</div>
                        ${o.pager && T.pager ? pagerStatus(o.pager, T.pager) + pagerButtons({ ...o.pager, print: null }, T.pager, o.id) : ''}</div>
                    <div class="flex shrink-0 gap-1">
                        <button type="button" class="btn-ghost min-h-11 px-3" data-recent-print="${i}" title="${esc(T.printReceipt)}" aria-label="${esc(T.printReceipt)}">🖨</button>
                        ${D.share ? `<button type="button" class="btn-ghost min-h-11 px-3" data-recent-share="${i}" title="${esc(T.shareWhatsApp)}" aria-label="${esc(T.shareWhatsApp)}">📲</button>` : ''}
                        ${o.refund_status !== 'full' ? `<button type="button" class="btn-ghost min-h-11 px-3 text-red-700" data-recent-refund="${i}">↩ ${esc(T.refund)}</button>` : ''}
                    </div>
                </li>`).join('') : `<li class="py-8 text-center text-navy-500">${esc(T.noOrdersFound)}</li>`;
        }
        $('[data-orders-open]').addEventListener('click', () => {
            if (off && !off.online) return alert(T.noInternet);
            $('[data-orders-q]').value = '';
            loadRecent();
            ordersDialog.showModal();
            $('[data-orders-q]').focus();
        });
        let searchTimer = null;
        $('[data-orders-q]').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(loadRecent, 300); });
        bindPager($('[data-orders-list]'), () => loadRecent());
        $('[data-orders-list]').addEventListener('click', (e) => {
            const p = e.target.closest('[data-recent-print]');
            if (p && printer) return printer.printOrder(recent[Number(p.dataset.recentPrint)].id, ['receipt'], { reprint: true });
            if (p) return window.open(recent[Number(p.dataset.recentPrint)].receipt, '_blank', 'width=420,height=700');
            const s = e.target.closest('[data-recent-share]');
            if (s) return shareReceipt(recent[Number(s.dataset.recentShare)]);
            const r = e.target.closest('[data-recent-refund]');
            if (r) openRefund(recent[Number(r.dataset.recentRefund)]);
        });

        const isFull = () => $('[data-refund-mode]:checked')?.value === 'full';
        function refundAmount() {
            if (!target) return 0;
            if (isFull()) return r2m(target.total - target.refunded);
            return r2m(target.items.reduce((s, i) => s + r2m(i.unit * (returning[i.id] || 0) * target.factor), 0));
        }
        function renderRefund() {
            const full = isFull();
            $('[data-refund-lines]').classList.toggle('hidden', full);
            $('[data-refund-lines]').innerHTML = target.items.map((i) => `
                <li class="flex items-center justify-between gap-2 py-2 ${i.left ? '' : 'opacity-40'}">
                    <div class="min-w-0"><div class="font-semibold">${esc(i.name)}</div><div class="text-xs text-navy-500">${esc(T.leftOf.replace('__L__', i.left).replace('__Q__', i.qty))}</div></div>
                    <div class="flex items-center rounded-lg border border-navy-100">
                        <button type="button" class="size-11" data-refund-step="-1" data-id="${i.id}" ${i.left ? '' : 'disabled'}>−</button><b class="w-7 text-center">${returning[i.id] || 0}</b>
                        <button type="button" class="size-11" data-refund-step="1" data-id="${i.id}" ${i.left ? '' : 'disabled'}>+</button></div>
                </li>`).join('');
            $('[data-refund-amount]').textContent = fmt(refundAmount());
            const asPaid = $('[data-refund-method] option[value=""]');
            asPaid.disabled = !full;
            if (!full && $('[data-refund-method]').value === '') $('[data-refund-method]').value = D.offlineMethods.includes(target.paid_by) ? target.paid_by : 'cash';
            $('[data-refund-method] option[value="credit"]').disabled = !target.customer;
            $('[data-refund-corporate]').classList.toggle('hidden', !(target.corporate > 0));
        }
        function openRefund(o) {
            target = o;
            returning = {};
            $('[data-refund-title]').textContent = o.ref + ' · ' + fmt(o.total);
            $('[data-refund-mode][value="full"]').checked = true;
            $('[data-refund-method]').value = '';
            $('[data-refund-reason]').value = '';
            if ($('[data-refund-pin]')) $('[data-refund-pin]').value = '';
            $('[data-refund-error]').classList.add('hidden');
            renderRefund();
            refundDialog.showModal();
        }
        $$('[data-refund-mode]').forEach((r) => r.addEventListener('change', renderRefund));
        $('[data-refund-lines]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-refund-step]');
            if (!b) return;
            const item = target.items.find((i) => String(i.id) === b.dataset.id);
            returning[item.id] = Math.max(0, Math.min(item.left, (returning[item.id] || 0) + Number(b.dataset.refundStep)));
            renderRefund();
        });
        $('[data-refund-confirm]').addEventListener('click', async (e) => {
            const err = $('[data-refund-error]');
            err.classList.add('hidden');
            const body = {
                full: isFull(), method: $('[data-refund-method]').value || null, reason: $('[data-refund-reason]').value.trim(),
                lines: Object.entries(returning).filter(([, q]) => q > 0).map(([id, qty]) => ({ order_item_id: Number(id), qty })),
            };
            if (!D.canRefund) {
                body.approver_id = Number($('[data-refund-manager]')?.value) || null;
                body.pin = $('[data-refund-pin]')?.value || '';
            }
            const btn = e.currentTarget;
            btn.disabled = true;
            const win = window.open('about:blank', '_blank', 'width=420,height=700'); // opened on the tap so it is not blocked
            try {
                const r = await api(D.urls.refund.replace('__ID__', target.id), body);
                if (win) win.location = r.receipt; else window.open(r.receipt, '_blank');
                refundDialog.close();
                loadRecent();
            } catch (ex) {
                win?.close();
                err.textContent = ex.message;
                err.classList.remove('hidden');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- cash in / out of the drawer (float, expenses): goes on the open shift, the expected cash follows ----
    if ($('[data-cash-open]')) {
        const cashDialog = $('[data-cash-dialog]');
        const show = (sel, text) => { $(sel).textContent = text || ''; $(sel).classList.toggle('hidden', !text); };
        $('[data-cash-open]').addEventListener('click', () => {
            if (off && !off.online) return alert(T.noInternet);
            ['[data-cash-amount]', '[data-cash-reason]', '[data-cash-category]'].forEach((s) => { $(s).value = ''; });
            show('[data-cash-error]', '');
            show('[data-cash-done]', '');
            cashDialog.showModal();
            $('[data-cash-amount]').focus();
        });
        $('[data-cash-confirm]').addEventListener('click', async (e) => {
            show('[data-cash-error]', '');
            const amount = Number($('[data-cash-amount]').value);
            if (!(amount > 0)) return show('[data-cash-error]', T.enterAmount);
            if (!$('[data-cash-reason]').value.trim()) return show('[data-cash-error]', T.cashReason);
            const btn = e.currentTarget;
            btn.disabled = true;
            try {
                const r = await api(D.urls.cashMove, {
                    type: $('[data-cash-type]:checked').value, amount, reason: $('[data-cash-reason]').value.trim(), category: $('[data-cash-category]').value || null,
                });
                show('[data-cash-done]', T.cashSaved.replace('__A__', fmt(r.expected_cash)));
                $('[data-cash-amount]').value = '';
                $('[data-cash-reason]').value = '';
            } catch (ex) {
                show('[data-cash-error]', ex.message);
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ---- keyboard: / search, F2 pay, F4 hold, F8 open orders, ? the shortcuts (Esc closes any window) ----
    const typing = (el) => el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
    const anyDialogOpen = () => $$('dialog').some((d) => d.open);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F2') { e.preventDefault(); if (!anyDialogOpen()) $('[data-pay]')?.click(); return; }
        if (e.key === 'F4') { e.preventDefault(); if (!anyDialogOpen()) $('[data-hold]')?.click(); return; }
        if (e.key === 'F8') { e.preventDefault(); if (!anyDialogOpen()) $('[data-open-orders]')?.click(); return; }
        if (e.key === 'F9') { e.preventDefault(); if (!anyDialogOpen()) $('[data-orders-open]')?.click(); return; }
        if (typing(document.activeElement) || anyDialogOpen()) return;
        if (e.key === '/') { e.preventDefault(); $('[data-search]').focus(); }
        if (e.key === '?') { e.preventDefault(); $('[data-help-dialog]')?.showModal(); }
        if (e.key === 'Escape') setTicket(false);
    });
    $('[data-help-open]')?.addEventListener('click', () => $('[data-help-dialog]').showModal());

    renderCats();
    renderItems();
    render();
    refreshOpen();
    setInterval(() => { if (!off || off.online) { refreshOpen(); refreshHolds(); } }, 30000);
}
