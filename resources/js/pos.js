// POS: item grid, ticket, takeaway/table, discount, payment with change, open orders, receipts and the
// customer-facing display (mirrored through BroadcastChannel). The server recomputes every amount.

const dataEl = document.getElementById('pos-data');
if (dataEl) {
    const D = JSON.parse(dataEl.textContent);
    const T = JSON.parse(document.getElementById('pos-i18n').textContent);
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = (n) => `${Math.round(n * 100) / 100} ${D.currency}`;
    const csrf = $('meta[name="csrf-token"]').content;
    const items = Object.fromEntries(D.items.map((i) => [i.id, i]));
    const display = 'BroadcastChannel' in window ? new BroadcastChannel('egmenu-pos') : null;
    const uuid = () => crypto.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (crypto.getRandomValues(new Uint8Array(1))[0] & 15);
        return (c === 'x' ? r : (r & 3) | 8).toString(16);
    });

    const CC = D.mode === 'callcenter'; // call-center mode: delivery / pickup, caller lookup, no payment here
    let ccPoint = () => null; // the pinned customer location (set by the call-center map below)
    let resetPoint = () => {};
    const zones = Object.fromEntries((D.zones || []).map((z) => [z.id, z]));
    let state = { type: CC ? 'delivery' : 'takeaway', table: '', lines: [], category: 'all' };
    let lastOrderId = null;

    async function api(url, body, method = 'POST') {
        const res = await fetch(url, {
            method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || (data.errors ? Object.values(data.errors)[0][0] : res.status));
        return data;
    }
    async function getJson(url) {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || (data.errors ? Object.values(data.errors)[0][0] : res.status));
        return data;
    }

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
        const discount = Math.min(subtotal, $('[data-discount-kind]')?.value === 'percent' ? subtotal * raw / 100 : raw);
        const service = state.type === 'dine_in' ? (subtotal - discount) * D.service / 100 : 0;
        const tax = (subtotal - discount + service) * D.tax / 100;
        const zone = CC && state.type === 'delivery' ? zones[$('[data-zone]').value] : null;
        const delivery = zone ? zone.fee : 0;
        return { subtotal, discount: Math.round(discount * 100) / 100, service, tax, delivery, zone, total: subtotal - discount + service + tax + delivery };
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
                    <button type="button" class="size-8" data-step="-1" data-i="${i}">−</button><b class="w-6 text-center">${l.qty}</b>
                    <button type="button" class="size-8" data-step="1" data-i="${i}">+</button></div>
                <div class="w-16 text-end text-sm font-bold">${Math.round(unit(l) * l.qty * 100) / 100}</div>
            </li>`;
        }).join('') : `<li class="py-10 text-center text-sm text-navy-500">${esc(T.empty)}</li>`;
        const t = totals();
        const row = (label, v, cls = '') => `<div class="flex justify-between ${cls}"><span>${esc(label)}</span><span>${fmt(v)}</span></div>`;
        $('[data-totals]').innerHTML = (t.discount || t.service || t.tax ? row(T.subtotal, t.subtotal) : '')
            + (t.discount ? row(T.discount, -t.discount, 'text-emerald-700') : '') + (t.service ? row(T.service, t.service) : '')
            + (t.tax ? row(T.tax, t.tax) : '') + (t.delivery ? row(T.deliveryFee, t.delivery) : '') + row(T.total, t.total, 'text-lg font-extrabold');
        if (CC) {
            const short = t.zone && t.zone.min > 0 && t.subtotal - t.discount < t.zone.min;
            $('[data-min-warning]').textContent = short ? T.minOrder.replace('__A__', fmt(t.zone.min)) : '';
            $('[data-min-warning]').classList.toggle('hidden', !short);
        }
        display?.postMessage({ lines: state.lines.map((l) => ({ name: items[l.item_id].name, qty: l.qty, amount: unit(l) * l.qty })), totals: t, currency: D.currency });
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
        state = { ...state, lines: [], table: '' };
        if ($('[data-table]')) $('[data-table]').value = '';
        ['[data-customer-name]', '[data-customer-phone]', '[data-notes]', '[data-phone2]', '[data-zone]', '[data-address]', '[data-address-label]']
            .forEach((sel) => { if ($(sel)) $(sel).value = ''; });
        if (CC) {
            resetPoint();
            state.type = 'delivery';
            $('[data-caller]').classList.add('hidden');
            $('[data-addresses]').innerHTML = '';
        }
        if ($('[data-discount]')) $('[data-discount]').value = 0;
        render();
    }

    function payload(pay) {
        const p = {
            client_uuid: uuid(), type: state.type, table_id: state.type === 'dine_in' ? Number(state.table) || null : null,
            items: state.lines, discount: totals().discount, pay: pay || null,
            customer_name: $('[data-customer-name]').value.trim(), customer_phone: $('[data-customer-phone]').value.trim(), notes: $('[data-notes]').value.trim(),
        };
        if (CC) p.phone_2 = $('[data-phone2]').value.trim();
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
            if (!$('[data-customer-phone]').value.trim()) { alert(T.phoneFirst); $('[data-customer-phone]').focus(); return false; }
            if (state.type === 'delivery') {
                const t = totals();
                if (!t.zone) { alert(T.chooseZone); return false; }
                if (!$('[data-address]').value.trim()) { alert(T.enterAddress); $('[data-address]').focus(); return false; }
                if (t.zone.min > 0 && t.subtotal - t.discount < t.zone.min) { alert(T.minOrder.replace('__A__', fmt(t.zone.min))); return false; }
            }
        }
        return true;
    }

    // ---- call center: caller lookup and saved addresses ----
    if (CC) {
        $('[data-zone]').innerHTML += D.zones.map((z) => `<option value="${z.id}">${esc(z.name)} · ${fmt(z.fee)}${z.minutes ? ' · ' + esc(T.minutes.replace('__N__', z.minutes)) : ''}</option>`).join('');
        $('[data-zone]').addEventListener('change', render);
        let saved = [];

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
            $('[data-pinned]').textContent = p ? '✓ ' + T.pinned : '';
            if (!map) return;
            if (marker) marker.remove();
            marker = p ? L.marker(p).addTo(map) : null;
            if (p && pickZone) {
                const zone = drawn.find((z) => inside(p, z.polygon));
                if (zone) { $('[data-zone]').value = zone.id; render(); } else alert(T.outside);
            }
        }
        $('[data-map-toggle]').addEventListener('click', () => {
            const box = $('[data-cc-map]');
            box.classList.toggle('hidden');
            if (map || !window.L || box.classList.contains('hidden')) return;
            map = L.map(box).setView(drawn[0]?.polygon[0] || [30.0444, 31.2357], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
            drawn.forEach((z) => L.polygon(z.polygon, { color: '#1E2D4F', weight: 1, fillOpacity: 0.08 }).bindTooltip(z.name).addTo(map));
            if (drawn.length) map.fitBounds(drawn.flatMap((z) => z.polygon));
            map.on('click', (e) => setPoint([+e.latlng.lat.toFixed(6), +e.latlng.lng.toFixed(6)]));
            setPoint(point, false);
            if (point) map.setView(point, 15);
        });

        const pick = (a) => {
            $('[data-zone]').value = a.zone_id;
            $('[data-address]').value = a.text;
            $('[data-address-label]').value = a.label || '';
            setPoint(a.lat != null ? [a.lat, a.lng] : null, false);
            render();
        };
        $('[data-addresses]').addEventListener('click', (e) => {
            const b = e.target.closest('[data-pick-address]');
            if (b) pick(saved[Number(b.dataset.pickAddress)]);
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
                    box.innerHTML = `<b class="text-emerald-700">${esc(T.newCaller)}</b>`;
                    $('[data-addresses]').innerHTML = '';
                    $('[data-customer-name]').focus();
                    return;
                }
                $('[data-customer-name]').value = c.name || '';
                $('[data-phone2]').value = c.phone_2 || '';
                box.innerHTML = `<div class="font-bold">${esc(c.name || c.phone)}${c.orders_count ? ' · ' + esc(T.ordersCount.replace('__N__', c.orders_count)) : ''}</div>`
                    + (c.notes ? `<div class="mt-1 text-brand-700">⚠ ${esc(c.notes)}</div>` : '')
                    + (c.orders.length ? `<ul class="mt-1 space-y-0.5 text-navy-500">${c.orders.map((o) => `<li>#${o.number} · ${esc(o.date)} · ${esc(o.type)} · ${esc(o.total)} · ${esc(o.status)}</li>`).join('')}</ul>` : '');
                saved = c.addresses.filter((a) => zones[a.zone_id]); // addresses in zones this branch still serves
                $('[data-addresses]').innerHTML = saved.map((a, i) =>
                    `<button type="button" class="btn-ghost min-h-8 px-2 text-xs" data-pick-address="${i}" title="${esc(a.text)}">📍 ${esc(a.label || zones[a.zone_id].name)}</button>`).join('');
                if (saved[0]) pick(saved[0]);
            } catch (err) {
                box.innerHTML = `<b class="text-red-600">${esc(err.message)}</b>`;
            }
        }
        ccPoint = () => point;
        resetPoint = () => setPoint(null, false);
        $('[data-lookup]').addEventListener('click', lookup);
        $('[data-customer-phone]').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });
    }

    // ---- send / pay ----
    const doneDialog = $('[data-done-dialog]');
    function done(order, status) {
        lastOrderId = order.id;
        $('[data-done-number]').textContent = '#' + order.number;
        $('[data-done-status]').textContent = status;
        reset();
        refreshOpen();
        doneDialog.showModal();
    }
    $$('[data-print]').forEach((b) => b.addEventListener('click', () => {
        if (lastOrderId) window.open(D.urls.receipt.replace('__ID__', lastOrderId) + (b.dataset.print === 'kitchen' ? '?copy=kitchen' : ''), '_blank', 'width=420,height=700');
    }));

    $('[data-send]').addEventListener('click', async () => {
        if (!canSubmit()) return;
        try { done(await api(D.urls.order, payload(null)), CC ? T.placed : T.sent); } catch (err) { alert(err.message); }
    });

    const payDialog = $('[data-pay-dialog]');
    let payTarget = null; // null = current ticket, else {id,total} of an open order
    let method = 'cash';
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
        const round = (n) => Math.ceil(total / n) * n;
        $('[data-quick]').innerHTML = [...new Set([Math.ceil(total), round(50), round(100), round(200)])]
            .map((v) => `<button type="button" class="btn-ghost min-h-9 px-3" data-quick-v="${v}">${v}</button>`).join('');
        setMethod('cash');
        payDialog.showModal();
    }
    function setMethod(m) {
        method = m;
        $$('[data-method]').forEach((b) => {
            b.classList.toggle('ring-2', b.dataset.method === m);
            b.classList.toggle('ring-brand-500', b.dataset.method === m);
        });
        $('[data-cash-box]').classList.toggle('hidden', m !== 'cash');
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
    function showChange() {
        const total = payTarget ? payTarget.total : totals().total;
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
        try {
            if (payTarget) {
                await api(D.urls.pay.replace('__ID__', payTarget.id), { method, phone: creditPhone || null });
                payDialog.close();
                $('[data-open-dialog]').close();
                done({ id: payTarget.id, number: payTarget.number }, T.paid);
            } else {
                if (creditPhone) $('[data-customer-phone]').value = creditPhone;
                const order = await api(D.urls.order, payload(method));
                payDialog.close();
                done(order, T.paid);
            }
        } catch (e) {
            err.textContent = e.message;
            err.classList.remove('hidden');
        }
    });

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
            const { orders } = await (await fetch(D.urls.open, { headers: { Accept: 'application/json' } })).json();
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

    renderCats();
    renderItems();
    render();
    refreshOpen();
    setInterval(refreshOpen, 30000);
}
