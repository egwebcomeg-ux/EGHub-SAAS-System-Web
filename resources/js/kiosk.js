// Self-order kiosk: idle screen → menu (categories on the side, big tiles) → item sheet with options → cart
// drawer → name / order type / table → big order number "pay at the cashier" → back to idle. Nothing is kept
// between guests; the server recomputes every amount (OrderService).
// Inside the EgHub kiosk app the tablet is locked to this page (no home / back) and the screen stays on.
import { call, inApp } from './native';

const dataEl = document.getElementById('kiosk-data');
if (dataEl && inApp('kiosk')) {
    call('setKiosk', true);
    call('keepAwake', true);
}
if (dataEl) {
    const D = JSON.parse(dataEl.textContent);
    const T = JSON.parse(document.getElementById('kiosk-i18n').textContent);
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const round = (n) => Math.round(n * 100) / 100;
    const fmt = (n) => `${round(n).toLocaleString('en')} ${D.currency}`;
    const csrf = $('meta[name="csrf-token"]').content;
    const items = Object.fromEntries(D.items.map((i) => [i.id, i]));
    const uuid = () => crypto.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15;
        return (c === 'x' ? r : (r & 3) | 8).toString(16);
    });

    const IDLE_MS = D.options.idle_seconds * 1000;
    const types = D.options.order_types;
    let state;
    let lastTouch = Date.now();
    let idleShownAt = Date.now();

    function fresh() {
        state = { lines: [], category: D.categories[0]?.id ?? null, type: types.length === 1 ? types[0] : null, uuid: uuid() };
    }

    // ---- screens ----
    function show(name) {
        $$('[data-screen]').forEach((s) => s.classList.toggle('is-on', s.dataset.screen === name));
        if (name === 'idle') idleShownAt = Date.now();
    }
    function closeDialogs() {
        $$('dialog[open]').forEach((d) => d.close());
    }
    function toIdle() {
        closeDialogs();
        fresh();
        render();
        show('idle');
    }

    // ---- menu ----
    function renderCats() {
        $('[data-cats]').innerHTML = D.categories.map((c) => `
            <button type="button" data-cat="${c.id}" class="flex min-h-20 w-full items-center gap-3 rounded-2xl p-3 text-start text-lg font-extrabold leading-tight ${c.id === state.category ? 'k-primary' : 'bg-navy-50'}">
                ${c.image ? `<img src="${esc(c.image)}" alt="" class="size-12 shrink-0 rounded-xl object-cover">` : ''}<span>${esc(c.name)}</span>
            </button>`).join('');
    }
    function renderItems() {
        $('[data-items]').innerHTML = D.items.filter((i) => i.category === state.category).map((i) => `
            <button type="button" data-item="${i.id}" ${i.soldOut ? 'disabled' : ''}
                class="flex flex-col overflow-hidden rounded-3xl bg-white text-start shadow-sm ring-1 ring-navy-100 active:scale-[.98] disabled:opacity-40">
                ${i.image ? `<img src="${esc(i.image)}" alt="" loading="lazy" class="aspect-[4/3] w-full object-cover">` : '<div class="k-secondary flex aspect-[4/3] w-full items-center justify-center text-5xl opacity-90">🍽</div>'}
                <span class="flex flex-1 flex-col justify-between gap-2 p-4">
                    <span class="text-xl font-extrabold leading-snug">${esc(i.name)}</span>
                    <span class="text-xl font-black k-primary-text">${i.soldOut ? esc(T.soldOut) : fmt(i.price)}
                        ${i.offerFrom && !i.soldOut ? `<span class="ms-1 text-base font-semibold text-navy-400 line-through">${fmt(i.offerFrom)}</span>` : ''}</span>
                </span>
            </button>`).join('');
        $('[data-items]').parentElement.scrollTop = 0;
    }
    $('[data-cats]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-cat]');
        if (!b) return;
        state.category = Number(b.dataset.cat);
        renderCats();
        renderItems();
    });

    // ---- item sheet ----
    const itemDialog = $('[data-item-dialog]');
    let sheet = null; // {item, qty}
    const optionsOf = (item) => item.groups.flatMap((g) => g.options);
    function chosenIds() {
        return $$('[data-opt].k-ring', itemDialog).map((b) => Number(b.dataset.opt));
    }
    function sheetTotal() {
        const ids = chosenIds();
        return (sheet.item.price + optionsOf(sheet.item).filter((o) => ids.includes(o.id)).reduce((s, o) => s + o.price, 0)) * sheet.qty;
    }
    function renderSheet() {
        $('[data-qty]').textContent = sheet.qty;
        $('[data-item-add]').textContent = `${T.add} · ${fmt(sheetTotal())}`;
    }
    function openItem(item) {
        sheet = { item, qty: 1 };
        const img = $('[data-item-image]');
        img.classList.toggle('hidden', !item.image);
        if (item.image) img.src = item.image;
        $('[data-item-name]').textContent = item.name;
        $('[data-item-description]').textContent = item.description || '';
        $('[data-item-groups]').innerHTML = item.groups.map((g) => `
            <fieldset data-group="${g.id}">
                <legend class="mb-2 text-xl font-extrabold">${esc(g.name)} ${g.required ? `<span class="ms-2 rounded-full bg-navy-100 px-3 py-0.5 text-sm">${esc(T.required)}</span>` : ''}</legend>
                <div class="grid grid-cols-2 gap-3">${g.options.map((o) => `
                    <button type="button" data-opt="${o.id}" data-group-id="${g.id}" ${o.soldOut ? 'disabled' : ''}
                        class="min-h-16 rounded-2xl border-2 border-navy-100 px-4 text-start text-lg font-bold disabled:opacity-40 ${g.type === 'single' && o.default && !o.soldOut ? 'k-ring' : ''}">
                        ${esc(o.name)}${o.price ? ` <span class="text-navy-500">+${fmt(o.price)}</span>` : ''}
                    </button>`).join('')}</div>
            </fieldset>`).join('');
        renderSheet();
        itemDialog.showModal();
    }
    $('[data-item-groups]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-opt]');
        if (!b || b.disabled) return;
        const g = sheet.item.groups.find((x) => x.id === Number(b.dataset.groupId));
        const siblings = $$(`[data-group-id="${g.id}"]`, itemDialog);
        if (g.type === 'single') {
            const was = b.classList.contains('k-ring');
            siblings.forEach((s) => s.classList.remove('k-ring'));
            if (!was || g.required) b.classList.add('k-ring');
        } else if (b.classList.contains('k-ring')) {
            b.classList.remove('k-ring');
        } else if (!g.max || siblings.filter((s) => s.classList.contains('k-ring')).length < g.max) {
            b.classList.add('k-ring');
        }
        renderSheet();
    });
    $$('[data-qty-step]').forEach((b) => b.addEventListener('click', () => {
        sheet.qty = Math.max(1, Math.min(30, sheet.qty + Number(b.dataset.qtyStep)));
        renderSheet();
    }));
    $('[data-item-add]').addEventListener('click', () => {
        const ids = chosenIds();
        const missing = sheet.item.groups.find((g) => g.required && !g.options.some((o) => ids.includes(o.id)));
        if (missing) {
            const box = $(`[data-group="${missing.id}"]`, itemDialog);
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
            box.classList.add('animate-pulse');
            setTimeout(() => box.classList.remove('animate-pulse'), 1500);
            return;
        }
        const same = state.lines.find((l) => l.item_id === sheet.item.id && l.option_ids.join() === ids.join());
        if (same) same.qty = Math.min(30, same.qty + sheet.qty);
        else state.lines.push({ item_id: sheet.item.id, option_ids: ids, qty: sheet.qty });
        itemDialog.close();
        render();
    });
    $('[data-items]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-item]');
        if (b) openItem(items[b.dataset.item]);
    });

    // ---- cart ----
    const unit = (l) => items[l.item_id].price + optionsOf(items[l.item_id]).filter((o) => l.option_ids.includes(o.id)).reduce((s, o) => s + o.price, 0);
    function totals() {
        const subtotal = state.lines.reduce((s, l) => s + unit(l) * l.qty, 0);
        const service = state.type === 'dine_in' ? subtotal * D.service / 100 : 0;
        const tax = (subtotal + service) * D.tax / 100;
        return { subtotal, service, tax, total: subtotal + service + tax };
    }
    function render() {
        const count = state.lines.reduce((s, l) => s + l.qty, 0);
        const t = totals();
        $('[data-cart-count]').textContent = count;
        $('[data-cart-total]').textContent = count ? fmt(t.subtotal) : '';
        $('[data-open-cart]').disabled = !count;
        $('[data-lines]').innerHTML = state.lines.length ? state.lines.map((l, i) => {
            const item = items[l.item_id];
            const opts = optionsOf(item).filter((o) => l.option_ids.includes(o.id)).map((o) => o.name).join('، ');
            return `<li class="flex items-center gap-4 py-4">
                <div class="min-w-0 flex-1"><div class="text-xl font-extrabold">${esc(item.name)}</div>${opts ? `<div class="text-navy-500">${esc(opts)}</div>` : ''}
                    <div class="k-primary-text font-black">${fmt(unit(l) * l.qty)}</div></div>
                <div class="flex items-center rounded-2xl border-2 border-navy-100">
                    <button type="button" class="size-14 text-3xl font-bold" data-step="-1" data-i="${i}">−</button><b class="w-10 text-center text-2xl">${l.qty}</b>
                    <button type="button" class="size-14 text-3xl font-bold" data-step="1" data-i="${i}">+</button></div>
            </li>`;
        }).join('') : `<li class="py-16 text-center text-xl text-navy-500">${esc(T.emptyCart)}</li>`;
        const row = (label, v, cls = '') => `<div class="flex justify-between ${cls}"><span>${esc(label)}</span><span>${fmt(v)}</span></div>`;
        $('[data-totals]').innerHTML = (t.service || t.tax ? row(T.subtotal, t.subtotal) : '') + (t.service ? row(T.service, t.service) : '')
            + (t.tax ? row(T.tax, t.tax) : '') + row(T.total, t.total, 'text-2xl font-black');
        $('[data-checkout]').disabled = !count;
        $$('[data-type]').forEach((b) => {
            b.classList.toggle('k-primary', b.dataset.type === state.type);
            b.classList.toggle('bg-navy-50', b.dataset.type !== state.type);
        });
        $('[data-table-box]').classList.toggle('hidden', state.type !== 'dine_in');
        $('[data-types]').classList.toggle('hidden', types.length < 2);
    }
    $('[data-lines]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-step]');
        if (!b) return;
        const l = state.lines[Number(b.dataset.i)];
        l.qty = Math.min(30, l.qty + Number(b.dataset.step));
        if (l.qty < 1) state.lines.splice(Number(b.dataset.i), 1);
        render();
    });
    const cartDialog = $('[data-cart-dialog]');
    $('[data-open-cart]').addEventListener('click', () => cartDialog.showModal());

    // ---- checkout ----
    const checkoutDialog = $('[data-checkout-dialog]');
    const error = (msg) => {
        $('[data-error]').textContent = msg || '';
        $('[data-error]').classList.toggle('hidden', !msg);
    };
    $('[data-checkout]').addEventListener('click', () => {
        if (!state.lines.length) return;
        cartDialog.close();
        error('');
        render();
        checkoutDialog.showModal();
    });
    $$('[data-type]').forEach((b) => b.addEventListener('click', () => { state.type = b.dataset.type; render(); }));
    $$('[data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));

    $('[data-checkout-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = $('[data-name]').value.trim();
        const phone = $('[data-phone]')?.value.trim() ?? '';
        const table = $('[data-table]').value.trim();
        if (!state.type) return error(T.error);
        if (state.type === 'dine_in' && !table) return error(T.tableFirst);
        if (!name) return error(T.nameFirst);
        if (D.options.require_phone && !phone) return error(T.phoneFirst);
        const btn = $('[data-place]');
        btn.disabled = true;
        try {
            const res = await fetch(D.orderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ client_uuid: state.uuid, type: state.type, table, items: state.lines, customer_name: name, customer_phone: phone }),
            });
            if (res.status === 419) return location.reload(); // the page sat open past the session: start fresh
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(body.message || (body.errors ? Object.values(body.errors)[0][0] : T.error));
            done(body);
        } catch (err) {
            error(err.message || T.error);
        } finally {
            btn.disabled = false;
        }
    });

    // ---- order number ----
    let doneTimer = null;
    function done(order) {
        closeDialogs();
        $('[data-done-number]').textContent = order.number;
        $('[data-done-name]').textContent = order.name;
        ['[data-name]', '[data-phone]', '[data-table]'].forEach((s) => { if ($(s)) $(s).value = ''; });
        show('done');
        if (D.options.print_number) {
            $('[data-ticket]').innerHTML = `<div style="font-size:14px;font-weight:700">${esc(T.brand)}</div>
                <div style="font-size:12px">${esc(T.number)}</div><div style="font-size:56px;font-weight:900;line-height:1">${esc(order.number)}</div>
                <div style="font-size:16px;font-weight:700">${esc(order.name)}</div><div style="font-size:12px">${esc(order.type)} · ${esc(order.time)}</div>
                <hr><div style="text-align:start;font-size:12px">${order.items.map((i) => `${i.qty}× ${esc(i.name)}`).join('<br>')}</div><hr>
                <div style="font-size:16px;font-weight:900">${esc(T.total)}: ${esc(order.total)} ${esc(D.currency)}</div>
                <div style="font-size:14px;font-weight:700">${esc(T.payAtCashier)}</div>`;
            setTimeout(() => window.print(), 300);
        }
        let left = 20;
        const tick = () => {
            $('[data-done-countdown]').textContent = T.back.replace('__N__', left);
            if (left-- <= 0) finish();
        };
        clearInterval(doneTimer);
        tick();
        doneTimer = setInterval(tick, 1000);
    }
    function finish() {
        clearInterval(doneTimer);
        toIdle();
    }
    $('[data-finish]').addEventListener('click', finish);

    // ---- start / restart ----
    $('[data-screen="idle"]').addEventListener('click', (e) => {
        if (e.target.closest('a')) return;
        fresh();
        renderCats();
        renderItems();
        render();
        show('shop');
    });
    $('[data-restart]').addEventListener('click', toIdle);

    // ---- idle: a guest who walked away gets "still there?" for 10 seconds, then the kiosk resets ----
    const idleDialog = $('[data-idle-dialog]');
    let idleCount = 0;
    ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, () => { lastTouch = Date.now(); }, { passive: true }));
    $('[data-still-here]').addEventListener('click', () => { idleDialog.close(); lastTouch = Date.now(); });
    setInterval(() => {
        const screen = $('[data-screen].is-on')?.dataset.screen;
        if (screen === 'idle') {
            // refresh the menu (prices, sold out) and the session now and then while nobody is ordering
            if (Date.now() - idleShownAt > 15 * 60 * 1000) location.reload();
            return;
        }
        if (screen !== 'shop') return;
        if (idleDialog.open) {
            idleCount--;
            $('[data-idle-count]').textContent = Math.max(0, idleCount);
            if (idleCount <= 0) toIdle();
        } else if (Date.now() - lastTouch > IDLE_MS) {
            closeDialogs();
            idleCount = 10;
            $('[data-idle-count]').textContent = idleCount;
            idleDialog.showModal();
        }
    }, 1000);

    fresh();
    render();
}
