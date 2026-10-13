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

    let state = { type: 'takeaway', table: '', lines: [], category: 'all' };
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
        return { subtotal, discount: Math.round(discount * 100) / 100, service, tax, total: subtotal - discount + service + tax };
    }
    function render() {
        $$('[data-type]').forEach((b) => {
            const on = b.dataset.type === state.type;
            b.classList.toggle('bg-navy-800', on);
            b.classList.toggle('text-white', on);
            b.classList.toggle('bg-navy-50', !on);
        });
        $('[data-table-box]').classList.toggle('hidden', state.type !== 'dine_in');
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
            + (t.tax ? row(T.tax, t.tax) : '') + row(T.total, t.total, 'text-lg font-extrabold');
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
    $('[data-table]').innerHTML += D.tables.map((t) => `<option value="${t.id}">${esc(T.table.replace('__N__', t.name))}</option>`).join('');
    $('[data-table]').addEventListener('change', (e) => (state.table = e.target.value));
    $('[data-discount]')?.addEventListener('input', render);
    $('[data-discount-kind]')?.addEventListener('change', render);
    $('[data-clear]').addEventListener('click', () => { if (state.lines.length && confirm(T.clearAsk)) reset(); });

    function reset() {
        state = { ...state, lines: [], table: '' };
        $('[data-table]').value = '';
        ['[data-customer-name]', '[data-customer-phone]', '[data-notes]'].forEach((s) => ($(s).value = ''));
        if ($('[data-discount]')) $('[data-discount]').value = 0;
        render();
    }

    function payload(pay) {
        return {
            client_uuid: uuid(), type: state.type, table_id: state.type === 'dine_in' ? Number(state.table) || null : null,
            items: state.lines, discount: totals().discount, pay: pay || null,
            customer_name: $('[data-customer-name]').value.trim(), customer_phone: $('[data-customer-phone]').value.trim(), notes: $('[data-notes]').value.trim(),
        };
    }
    function canSubmit() {
        if (!state.lines.length) return false;
        if (state.type === 'dine_in' && !state.table) { alert(T.chooseTable); return false; }
        return true;
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
        try { done(await api(D.urls.order, payload(null)), T.sent); } catch (err) { alert(err.message); }
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
    }
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
    $('[data-pay]').addEventListener('click', () => { if (canSubmit()) openPay(null); });
    $('[data-pay-confirm]').addEventListener('click', async () => {
        const err = $('[data-pay-error]');
        err.classList.add('hidden');
        try {
            if (payTarget) {
                await api(D.urls.pay.replace('__ID__', payTarget.id), { method });
                payDialog.close();
                $('[data-open-dialog]').close();
                done({ id: payTarget.id, number: payTarget.number }, T.paid);
            } else {
                const order = await api(D.urls.order, payload(method));
                payDialog.close();
                done(order, T.paid);
            }
        } catch (e) {
            err.textContent = e.message;
            err.classList.remove('hidden');
        }
    });

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
