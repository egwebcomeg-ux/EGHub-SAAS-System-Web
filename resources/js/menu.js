// Public menu: item sheet with sizes/extras, cart kept per restaurant in localStorage, ordering from the table or
// online, the hall list for the waiter, bill split, the order-type screen and the intro splash.
// Prices here are only for display; the server recomputes every amount when the order is placed.

const dataEl = document.getElementById('menu-data');
if (dataEl) {
    const M = JSON.parse(dataEl.textContent);
    const T = JSON.parse(document.getElementById('menu-i18n').textContent);
    const $ = (sel, root = document) => root.querySelector(sel);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = (n) => `${Math.round(n * 100) / 100} ${M.currency}`;
    const storeKey = `egmenu.cart.${M.tenant}`;
    const session = {
        get(key) { try { return sessionStorage.getItem(key); } catch { return null; } },
        set(key, value) { try { sessionStorage.setItem(key, value); } catch {} },
    };

    // ---- how the guest orders ----
    // mode: table (send from the scanned table), list (hall: a list to show the waiter), online (takeaway / delivery
    // from the menu link) or none (menu only). The hall setting comes from the restaurant (App\Support\DineIn).
    const H = M.hall || { mode: 'off', prices: true };
    const O = M.online;
    const hallCart = H.mode === 'order' ? 'table' : H.mode === 'list' ? 'list' : 'none';
    let mode = M.mode;
    let otype = O ? (O.takeaway ? 'takeaway' : 'delivery') : null;
    const inHall = () => mode === 'table' || mode === 'list';
    const hidePrices = () => inHall() && !H.prices;
    const wayKey = `egmenu.way.${M.tenant}`;

    // ---- cart storage (each restaurant has its own cart) ----
    let cart = [];
    try { cart = JSON.parse(localStorage.getItem(storeKey) || '[]'); } catch {}
    // Drop lines whose item or options left the menu since they were added.
    cart = cart.filter((l) => {
        const item = M.items[l.item_id];
        if (!item || item.soldOut) return false;
        const ids = item.groups.flatMap((g) => g.options.map((o) => o.id));
        return l.option_ids.every((id) => ids.includes(id));
    });
    const save = () => { try { localStorage.setItem(storeKey, JSON.stringify(cart)); } catch {} };

    const unitPrice = (item, optionIds) =>
        item.price + item.groups.flatMap((g) => g.options).filter((o) => optionIds.includes(o.id)).reduce((s, o) => s + o.price, 0);

    function renderBar() {
        const count = cart.reduce((s, l) => s + l.qty, 0);
        const total = cart.reduce((s, l) => s + unitPrice(M.items[l.item_id], l.option_ids) * l.qty, 0);
        $('[data-cart-bar]').classList.toggle('hidden', count === 0 || mode === 'none');
        $('[data-cart-count]').textContent = count;
        $('[data-cart-total]').textContent = hidePrices() ? '' : fmt(total);
        $('[data-cart-label]').textContent = mode === 'list' ? T.myOrder : T.viewOrder;
    }

    // ---- item sheet ----
    const sheet = $('[data-item-sheet]');
    let current = null;
    let qty = 1;

    function chosenIds() {
        return [...sheet.querySelectorAll('[data-option]:checked')].map((i) => Number(i.value));
    }

    function refreshSheetTotal() {
        $('[data-sheet-total]', sheet).textContent = fmt(unitPrice(current, chosenIds()) * qty);
        $('[data-sheet-qty]', sheet).textContent = qty;
    }

    // ad pixels of the restaurant (menu/partials/pixels): no-op when none is set
    const track = (event, params) => { try { window.egmTrack?.(event, params); } catch {} };

    function openItem(id) {
        current = M.items[id];
        if (!current) return;
        qty = 1;
        track('ViewContent', { content_ids: [String(current.id)], content_name: current.name, content_type: 'product', value: current.price });
        const img = $('[data-sheet-image]', sheet);
        img.classList.toggle('hidden', !current.image);
        if (current.image) img.src = current.image;
        $('[data-sheet-name]', sheet).textContent = current.name;
        $('[data-sheet-desc]', sheet).textContent = current.description || '';
        $('[data-sheet-cal]', sheet).textContent = current.calories ? T.cal.replace('__N__', current.calories) : '';
        const allergenBox = $('[data-sheet-allergens]', sheet);
        if (allergenBox) {
            const chips = (current.allergens || []).map((a) => `<span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-900 ring-1 ring-amber-200">${esc(a.icon)} ${esc(a.label)}</span>`);
            if (current.spicy) chips.unshift(`<span class="rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700 ring-1 ring-red-200">${esc(current.spicy)} ${esc(T.spicy)}</span>`);
            allergenBox.innerHTML = chips.length ? (current.allergens?.length ? `<span class="m-muted">${esc(T.contains)}</span>` : '') + chips.join('') : '';
            allergenBox.classList.toggle('hidden', !chips.length);
            allergenBox.classList.toggle('flex', chips.length > 0);
        }
        const comboBox = $('[data-sheet-combo]', sheet);
        comboBox.classList.toggle('hidden', !current.combo);
        if (current.combo) {
            comboBox.innerHTML = `<b>${esc(T.comboHas)}</b><ul class="mt-1 list-disc ps-5">${current.combo.parts.map((p) => `<li>${p.qty}× ${esc(p.name)}</li>`).join('')}</ul>`
                + (current.combo.saving > 0 ? `<div class="mt-1 font-bold text-emerald-700">${esc(T.save.replace('__A__', fmt(current.combo.saving)))}</div>` : '');
        }
        $('[data-sheet-notes]', sheet).value = '';
        $('[data-sheet-groups]', sheet).innerHTML = current.groups.map((g) => {
            const hint = g.type === 'single' ? T.chooseOne : g.max ? T.chooseUpTo.replace('__N__', g.max) : T.optional;
            const options = g.options.map((o) => `
                <label class="flex min-h-11 items-center justify-between gap-3 m-border rounded-xl border px-3 ${o.soldOut ? 'opacity-50' : ''}">
                    <span class="flex items-center gap-3">
                        <input type="${g.type === 'single' ? 'radio' : 'checkbox'}" name="g${g.id}" value="${o.id}" data-option data-group="${g.id}"
                            class="size-5 accent-[var(--menu-primary)]" ${o.soldOut ? 'disabled' : ''} ${g.type === 'single' && o.default && !o.soldOut ? 'checked' : ''}>
                        ${esc(o.name)}
                    </span>
                    ${o.price ? `<span class="m-muted text-sm">+${fmt(o.price)}</span>` : ''}
                </label>`).join('');
            return `<fieldset data-group-box="${g.id}">
                <legend class="mb-2 flex w-full items-center justify-between font-bold">${esc(g.name)}
                    <span class="badge ${g.required ? 'bg-brand-100 text-brand-700' : 'badge-soft'}">${g.required ? T.required : esc(hint)}</span></legend>
                <div class="space-y-2">${options}</div></fieldset>`;
        }).join('');
        // menu only in the hall (and no takeaway / delivery): nothing to add
        const soldOut = current.soldOut || mode === 'none';
        $('[data-sheet-soldout]', sheet).classList.toggle('hidden', !current.soldOut);
        $('[data-add-to-cart]', sheet).classList.toggle('hidden', soldOut);
        refreshSheetTotal();
        sheet.showModal();
        countView(id);
    }

    // one anonymous "opened" count per item and page visit, for the restaurant's item reports
    const viewed = new Set();
    function countView(id) {
        if (!M.viewUrl || viewed.has(id)) return;
        viewed.add(id);
        fetch(M.viewUrl.replace(/\/0\/view$/, `/${id}/view`), {
            method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        }).catch(() => {});
    }

    sheet.addEventListener('change', (e) => {
        const input = e.target.closest('[data-option]');
        if (input && input.type === 'checkbox') {
            const g = current.groups.find((x) => x.id === Number(input.dataset.group));
            const picked = sheet.querySelectorAll(`[data-group="${g.id}"]:checked`);
            if (g.max && picked.length > g.max) input.checked = false;
        }
        refreshSheetTotal();
    });
    sheet.addEventListener('click', (e) => {
        const step = e.target.closest('[data-qty]');
        if (step) {
            qty = Math.min(30, Math.max(1, qty + Number(step.dataset.qty)));
            refreshSheetTotal();
        }
    });
    $('[data-add-to-cart]', sheet).addEventListener('click', () => {
        const ids = chosenIds();
        const missing = current.groups.find((g) => g.required && !g.options.some((o) => ids.includes(o.id)));
        if (missing) {
            alert(T.pickRequired.replace('__G__', missing.name));
            return;
        }
        const notes = $('[data-sheet-notes]', sheet).value.trim();
        const same = cart.find((l) => l.item_id === current.id && l.notes === notes && l.option_ids.join() === ids.join());
        if (same) same.qty = Math.min(30, same.qty + qty);
        else cart.push({ item_id: current.id, option_ids: ids, qty, notes });
        track('AddToCart', { content_ids: [String(current.id)], content_name: current.name, content_type: 'product', value: unitPrice(current, ids) * qty, num_items: qty });
        save();
        renderBar();
        sheet.close();
        showPairings(current);
    });

    // ---- "goes well with" ----
    const pairSheet = $('[data-pair-sheet]');
    function showPairings(item) {
        const list = (item.pairings || []).map((id) => M.items[id]).filter((p) => p && !p.soldOut);
        if (!list.length) return;
        $('[data-pair-list]', pairSheet).innerHTML = list.map((p) => `
            <div class="flex items-center gap-3 m-border rounded-xl border p-2">
                ${p.image ? `<img src="${esc(p.image)}" alt="" class="size-12 rounded-lg object-cover">` : ''}
                <div class="flex-1"><div class="font-bold">${esc(p.name)}</div><div class="text-sm m-primary-text">${fmt(p.price)}</div></div>
                <button type="button" class="m-primary min-h-10 rounded-lg px-4 text-sm font-bold" data-pair-add="${p.id}">${esc(T.add)}</button>
            </div>`).join('');
        pairSheet.showModal();
    }
    pairSheet.addEventListener('click', (e) => {
        const b = e.target.closest('[data-pair-add]');
        if (!b) return;
        const p = M.items[b.dataset.pairAdd];
        if (p.groups.some((g) => g.required)) { pairSheet.close(); openItem(p.id); return; }
        const same = cart.find((l) => l.item_id === p.id && !l.notes && !l.option_ids.length);
        if (same) same.qty++; else cart.push({ item_id: p.id, option_ids: [], qty: 1, notes: '' });
        save();
        renderBar();
        b.disabled = true;
        b.textContent = '✓';
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-item]');
        if (btn) openItem(btn.dataset.item);
    });

    // ---- cart sheet ----
    const cartSheet = $('[data-cart-sheet]');

    const optionNames = (item, ids) => item.groups.flatMap((g) => g.options).filter((o) => ids.includes(o.id)).map((o) => o.name).join('، ');
    const stepper = (i) => `<div class="m-border flex shrink-0 items-center rounded-xl border">
                    <button type="button" class="size-10 text-lg" data-line="${i}" data-step="-1" aria-label="−">−</button>
                    <button type="button" class="size-10 text-lg" data-line="${i}" data-step="1" aria-label="+">+</button>
                </div>`;

    // bill of the cart: service on the food in the hall, tax on food + service, the zone fee for delivery
    function totals() {
        const subtotal = cart.reduce((s, l) => s + unitPrice(M.items[l.item_id], l.option_ids) * l.qty, 0);
        const service = inHall() ? subtotal * M.service / 100 : 0;
        const tax = mode === 'none' ? 0 : (subtotal + service) * M.tax / 100;
        const zone = currentZone();
        const fee = zone ? zone.fee : 0;
        return { subtotal, service, tax, fee, zone, total: subtotal + service + tax + fee };
    }

    function renderCart() {
        const list = mode === 'list';
        const lines = cart.map((l, i) => {
            const item = M.items[l.item_id];
            const opts = optionNames(item, l.option_ids);
            const price = hidePrices() ? '' : `<div class="text-sm font-semibold">${fmt(unitPrice(item, l.option_ids) * l.qty)}</div>`;
            if (list) {
                // a list for the waiter: big quantity, name, options and notes (Desoky / Gold style)
                return `<li class="flex items-center gap-3 py-3">
                    <span class="m-qty m-primary" dir="ltr">${l.qty}×</span>
                    <div class="min-w-0 flex-1">
                        <div class="text-base font-extrabold leading-snug">${esc(item.name)}</div>
                        ${opts ? `<div class="m-muted text-xs">${esc(opts)}</div>` : ''}
                        ${l.notes ? `<div class="m-primary-text text-xs font-semibold">${esc(l.notes)}</div>` : ''}
                        ${price}
                    </div>
                    ${stepper(i)}
                </li>`;
            }
            return `<li class="flex items-center gap-3 py-3">
                <div class="min-w-0 flex-1">
                    <div class="font-bold">${esc(item.name)}</div>
                    ${opts ? `<div class="m-muted text-xs">${esc(opts)}</div>` : ''}
                    ${l.notes ? `<div class="m-primary-text text-xs">${esc(l.notes)}</div>` : ''}
                    ${price}
                </div>
                <div class="m-border flex items-center rounded-xl border">
                    <button type="button" class="size-10" data-line="${i}" data-step="-1">−</button>
                    <span class="w-6 text-center font-bold">${l.qty}</span>
                    <button type="button" class="size-10" data-line="${i}" data-step="1">+</button>
                </div>
            </li>`;
        }).join('');
        $('[data-cart-lines]', cartSheet).innerHTML = lines || `<li class="m-muted py-6 text-center">${esc(T.empty)}</li>`;

        const t = totals();
        const row = (label, v, bold) => `<div class="flex justify-between ${bold ? 'text-base font-extrabold' : ''}"><span>${esc(label)}</span><span>${fmt(v)}</span></div>`;
        const summary = $('[data-cart-summary]', cartSheet);
        summary.classList.toggle('hidden', hidePrices() || !cart.length);
        summary.innerHTML = (t.service || t.tax || t.fee ? row(T.subtotal, t.subtotal) + (t.service ? row(T.service, t.service) : '') + (t.tax ? row(T.tax, t.tax) : '') + (t.fee ? row(T.deliveryFee, t.fee) : '') : '')
            + row(T.total, t.total, true)
            + (t.zone && t.zone.min > t.subtotal ? `<div class="mt-1 text-xs font-semibold text-amber-700">${esc(T.minOrder.replace('__A__', fmt(t.zone.min)))}</div>` : '');
        // prepaid balance: the whole order is paid from it, or not at all (no partial payment)
        const walletBox = $('[data-pay-wallet]', cartSheet);
        if (walletBox && M.wallet) {
            const enough = Math.round(M.wallet.balance * 100) >= Math.round(t.total * 100);
            walletBox.disabled = !enough;
            if (!enough) walletBox.checked = false;
            $('[data-wallet-note]', cartSheet).textContent = enough ? T.payFromBalance : T.notEnoughBalance;
            const phone = $('[data-customer-phone]', cartSheet);
            if (phone && !phone.value) phone.value = M.wallet.phone;
        }
    }

    $('[data-open-cart]').addEventListener('click', () => { renderCart(); cartSheet.showModal(); });
    cartSheet.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-step]');
        if (!btn) return;
        const line = cart[Number(btn.dataset.line)];
        line.qty += Number(btn.dataset.step);
        if (line.qty < 1) cart.splice(Number(btn.dataset.line), 1);
        line.qty = Math.min(30, line.qty);
        save();
        renderCart();
        renderBar();
        if (!cart.length) cartSheet.close();
    });

    // ---- the way of ordering: hall (table / list) or online pickup / delivery ----
    function currentZone() {
        if (mode !== 'online' || otype !== 'delivery') return null;
        return O.zones.find((z) => String(z.id) === $('[data-zone]')?.value) || null;
    }
    const show = (sel, on) => document.querySelectorAll(sel).forEach((el) => el.classList.toggle('hidden', !on));
    function renderMode() {
        const way = inHall() ? 'dine' : mode === 'online' ? otype : null;
        document.querySelectorAll('[data-way-switch] [data-way], [data-online] [data-otype]').forEach((b) => {
            const on = (b.dataset.way || b.dataset.otype) === way;
            b.classList.toggle('m-way-on', on);
        });
        show('[data-delivery-fields]', mode === 'online' && otype === 'delivery');
        show('[data-online]', mode === 'online');
        show('[data-order-fields]', mode === 'table' || mode === 'online');
        show('[data-hall-notice], [data-scan-hint], [data-show-waiter], [data-hall-tools] [data-hall-call]', mode === 'list');
        show('[data-hall-tools]', inHall());
        show('[data-open-split]', inHall() && H.prices);
        const title = $('[data-cart-title]');
        if (title) title.textContent = mode === 'list' ? T.myOrder : T.yourOrder;
        renderBar();
        if (cartSheet.open) renderCart();
    }
    function applyWay(way) {
        if (way === 'dine') mode = hallCart;
        else if (O && O[way]) { mode = 'online'; otype = way; }
        renderMode();
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-way], [data-online] [data-otype]');
        if (!b) return;
        const way = b.dataset.way || b.dataset.otype;
        if (!H.table) session.set(wayKey, way);
        applyWay(way);
        if (b.closest('[data-order-choice]')) document.documentElement.classList.remove('choice-open');
    });
    // a remembered choice on the general link (the "how will you order?" screen or the cart switch)
    if (!H.table && (M.ways || []).length > 1) {
        const saved = session.get(wayKey);
        if (saved && M.ways.includes(saved)) applyWay(saved);
    }
    if (O) {
        $('[data-zone]')?.addEventListener('change', renderCart);
        const when = $('[data-scheduled]');
        if (when) {
            const pad = (n) => String(n).padStart(2, '0');
            const local = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
            when.min = local(new Date(Date.now() + O.prep * 60000));
            when.max = local(new Date(Date.now() + 7 * 86400000));
        }
    }
    renderMode();

    // ---- send order (a scanned table, or online pickup / delivery) ----
    // a coupon from a campaign link (?coupon=CODE) is kept for this visit and filled in the cart
    try {
        const fromLink = new URLSearchParams(location.search).get('coupon');
        if (fromLink) sessionStorage.setItem('egm-coupon', fromLink.slice(0, 30));
        const couponField = $('[data-coupon]');
        if (couponField && !couponField.value) couponField.value = sessionStorage.getItem('egm-coupon') || '';
    } catch {}
    const sendBtn = $('[data-send-order]');
    let clientUuid = null;
    sendBtn?.addEventListener('click', async () => {
        if (!cart.length) return;
        const errorBox = $('[data-order-error]');
        errorBox.classList.add('hidden');
        const fail = (msg) => { errorBox.textContent = msg; errorBox.classList.remove('hidden'); };
        if (mode === 'online') {
            if (!$('[data-customer-phone]').value.trim()) return fail(T.phoneRequired);
            if (otype === 'delivery' && !currentZone()) return fail(T.chooseZone);
            if (otype === 'delivery' && !$('[data-address]').value.trim()) return fail(T.enterAddress);
        }
        sendBtn.disabled = true;
        const label = sendBtn.textContent;
        sendBtn.textContent = T.sending;
        clientUuid ??= crypto.randomUUID?.() ?? ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, (c) => (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
        try {
            const res = await fetch(M.orderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({
                    client_uuid: clientUuid,
                    items: cart.map((l) => ({ item_id: l.item_id, qty: l.qty, option_ids: l.option_ids, notes: l.notes })),
                    customer_name: $('[data-customer-name]').value.trim(),
                    customer_phone: $('[data-customer-phone]').value.trim(),
                    notes: $('[data-order-notes]').value.trim(),
                    coupon: $('[data-coupon]')?.value.trim() || null,
                    pay_wallet: !!$('[data-pay-wallet]')?.checked,
                    ...(mode === 'online' ? {
                        type: otype, branch_id: O.branch, zone_id: currentZone()?.id ?? null,
                        address: $('[data-address]')?.value.trim() || null, scheduled_for: $('[data-scheduled]')?.value || null,
                    } : {}),
                }),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) {
                const first = body.errors ? Object.values(body.errors)[0][0] : null;
                throw new Error(body.message || first || res.status);
            }
            cart = [];
            save();
            window.location.href = body.url;
        } catch (err) {
            errorBox.textContent = err.message;
            errorBox.classList.remove('hidden');
            sendBtn.disabled = false;
            sendBtn.textContent = label;
        }
    });

    // ---- call the waiter / ask for the bill (scanned table): the 🛎 sheet, the hall list and the waiter view ----
    const callSheet = $('[data-call-sheet]');
    $('[data-call-open]')?.addEventListener('click', () => { $('[data-call-done]').classList.add('hidden'); callSheet.showModal(); });
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-call]');
        if (!btn || !M.callUrl) return;
        btn.disabled = true;
        try {
            const res = await fetch(M.callUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ kind: btn.dataset.call }),
            });
            const body = await res.json().catch(() => ({}));
            const inSheet = btn.closest('[data-call-sheet]');
            const done = inSheet ? $('[data-call-done]') : btn.closest('[data-cart-sheet]') ? $('[data-hall-call-done]') : null;
            if (done) {
                done.textContent = body.message || '';
                done.classList.remove('hidden');
            } else if (body.message) {
                btn.textContent = `✓ ${body.message}`;
            }
            if (res.ok && inSheet) setTimeout(() => callSheet.close(), 1800);
        } finally {
            btn.disabled = false;
        }
    });

    // ---- the list in big type for the waiter ----
    const waiterView = $('[data-waiter-view]');
    $('[data-show-waiter]')?.addEventListener('click', () => {
        if (!cart.length || !waiterView) return;
        $('[data-waiter-lines]', waiterView).innerHTML = cart.map((l) => {
            const item = M.items[l.item_id];
            const opts = optionNames(item, l.option_ids);
            return `<li class="flex items-start gap-4 py-4">
                <span class="m-qty m-primary shrink-0 text-2xl" style="min-width:3.75rem;height:3.75rem" dir="ltr">${l.qty}×</span>
                <div class="min-w-0 flex-1">
                    <div class="text-2xl font-black leading-snug">${esc(item.name)}</div>
                    ${opts ? `<div class="m-muted mt-0.5 text-lg font-semibold">${esc(opts)}</div>` : ''}
                    ${l.notes ? `<div class="m-primary-text mt-0.5 text-lg font-bold">✎ ${esc(l.notes)}</div>` : ''}
                </div>
            </li>`;
        }).join('');
        const t = totals();
        $('[data-waiter-total]', waiterView).innerHTML = H.prices
            ? `<div class="flex justify-between font-black"><span>${esc(T.total)}</span><span>${fmt(t.total)}</span></div>` : '';
        $('[data-waiter-time]', waiterView).textContent = new Date().toLocaleTimeString(document.documentElement.lang || undefined, { hour: 'numeric', minute: '2-digit' });
        cartSheet.close();
        waiterView.showModal();
    });
    waiterView?.querySelector('[data-waiter-close]').addEventListener('click', () => { waiterView.close(); renderCart(); cartSheet.showModal(); });

    // ---- split the bill (Desoky): equally, or by who ordered what ----
    // Each unit of each line (2 × pizza = 2 units) goes to one or more people; a unit shared by several people is
    // divided between them, a unit nobody took is shared by everyone. Service is on the food, tax on food + service,
    // both in proportion to each one's food. Worked in piasters so the shares add up exactly to the bill.
    const splitSheet = $('[data-split-sheet]');
    const split = { people: 2, mode: 'equal', assign: {}, names: [] };
    const splitUnits = () => cart.flatMap((l, li) => Array.from({ length: l.qty }, (_, n) => ({
        key: `${l.item_id}:${l.option_ids.join('-')}:${l.notes}#${n}`, line: l, li, price: unitPrice(M.items[l.item_id], l.option_ids),
    })));

    function splitCalc() {
        const P = split.people;
        const everyone = Array.from({ length: P }, (_, i) => i);
        const food = Array(P).fill(0);
        let unclaimed = 0;
        splitUnits().forEach((u) => {
            let who = split.mode === 'equal' ? [] : [...(split.assign[u.key] || [])].filter((i) => i < P);
            if (!who.length) { who = everyone; if (split.mode !== 'equal') unclaimed++; }
            who.forEach((i) => { food[i] += u.price / who.length; });
        });
        const people = food.map((f) => {
            const service = f * M.service / 100;
            const tax = (f + service) * M.tax / 100;
            return { food: f, service, tax, total: f + service + tax };
        });
        const cents = people.map((p) => Math.round(p.total * 100));
        const grand = Math.round(people.reduce((s, p) => s + p.total, 0) * 100);
        const diff = grand - cents.reduce((s, c) => s + c, 0);
        if (diff && cents.length) cents[cents.indexOf(Math.max(...cents))] += diff;
        people.forEach((p, i) => { p.pay = cents[i] / 100; });
        const sum = (k) => people.reduce((s, p) => s + p[k], 0);
        return { people, unclaimed, food: sum('food'), service: sum('service'), tax: sum('tax'), grand: grand / 100 };
    }

    function renderSplit() {
        const P = split.people;
        const calc = splitCalc();
        const custom = (i) => (split.names[i] || '').trim();
        const label = (i) => custom(i) || T.person.replace('__N__', i + 1);
        const modeBtn = (key, text) => `<button type="button" data-split-mode="${key}" class="min-h-11 flex-1 rounded-xl border text-sm font-bold ${split.mode === key ? 'm-primary border-transparent' : 'm-border'}">${esc(text)}</button>`;
        const units = splitUnits();
        const assign = split.mode !== 'items' ? '' : `<div>
                <div class="font-extrabold">${esc(T.whoOrdered)}</div>
                <p class="m-muted mb-2 text-xs">${esc(T.whoHint)}</p>
                <div class="space-y-2">${units.map((u, idx) => {
                    const item = M.items[u.line.item_id];
                    const opts = optionNames(item, u.line.option_ids);
                    const picked = split.assign[u.key] || new Set();
                    return `<div class="m-border rounded-2xl border p-2.5">
                        <div class="mb-2 flex items-center justify-between gap-2 text-sm">
                            <span class="min-w-0 font-bold">${esc(item.name)}${opts ? ` <span class="m-muted text-xs font-normal">(${esc(opts)})</span>` : ''}</span>
                            <span class="m-muted shrink-0 text-xs font-bold">${fmt(u.price)}</span>
                        </div>
                        <div class="flex flex-wrap gap-1.5">${Array.from({ length: P }, (_, i) => `<button type="button" data-split-unit="${idx}" data-split-person="${i}" class="h-9 min-w-9 rounded-full border px-2.5 text-xs font-extrabold ${picked.has(i) ? 'm-primary border-transparent' : 'm-border'}">${custom(i) ? esc(custom(i)) : i + 1}</button>`).join('')}</div>
                    </div>`;
                }).join('')}</div>
                ${calc.unclaimed ? `<p class="mt-2 text-xs font-bold text-amber-700">${esc(T.unclaimed.replace('__N__', calc.unclaimed))}</p>` : ''}
            </div>`;
        const rate = (v) => `${Number.isInteger(v) ? v : v.toFixed(1)}%`;
        $('[data-split-body]', splitSheet).innerHTML = `
            <div>
                <div class="mb-2 font-extrabold">${esc(T.people)}</div>
                <div class="flex items-center gap-3">
                    <button type="button" class="m-surface-2 size-11 rounded-xl text-xl" data-split-people="-1" aria-label="−">−</button>
                    <span class="w-12 text-center text-3xl font-black">${P}</span>
                    <button type="button" class="m-surface-2 size-11 rounded-xl text-xl" data-split-people="1" aria-label="+">+</button>
                </div>
            </div>
            <div>
                <div class="m-muted mb-1.5 text-xs">${esc(T.names)}</div>
                <div class="grid grid-cols-2 gap-2">${Array.from({ length: P }, (_, i) => `<input class="field" maxlength="20" data-split-name="${i}" value="${esc(split.names[i] || '')}" placeholder="${esc(T.person.replace('__N__', i + 1))}">`).join('')}</div>
            </div>
            <div class="flex gap-2">${modeBtn('equal', T.equal)}${modeBtn('items', T.byItems)}</div>
            ${assign}
            <div>
                <div class="mb-2 font-extrabold">${esc(T.eachPays)}</div>
                <div class="space-y-2" data-split-result>${calc.people.map((p, i) => `
                    <div class="m-border flex items-center justify-between gap-3 rounded-2xl border p-3">
                        <div class="min-w-0">
                            <div class="font-extrabold">${esc(label(i))}</div>
                            <div class="m-muted text-xs">${esc(T.food)} ${fmt(p.food)}${M.service ? ` · ${esc(T.service)} ${fmt(p.service)}` : ''}${M.tax ? ` · ${esc(T.tax)} ${fmt(p.tax)}` : ''}</div>
                        </div>
                        <div class="text-xl font-black whitespace-nowrap" data-split-pay>${fmt(p.pay)}</div>
                    </div>`).join('')}</div>
            </div>
            <div class="m-primary space-y-1 rounded-2xl p-4 text-sm">
                <div class="flex justify-between opacity-85"><span>${esc(T.food)}</span><span>${fmt(calc.food)}</span></div>
                ${M.service ? `<div class="flex justify-between opacity-85"><span>${esc(T.service)} ${rate(M.service)}</span><span>${fmt(calc.service)}</span></div>` : ''}
                ${M.tax ? `<div class="flex justify-between opacity-85"><span>${esc(T.tax)} ${rate(M.tax)}</span><span>${fmt(calc.tax)}</span></div>` : ''}
                <div class="mt-1 flex justify-between border-t border-current/25 pt-2 text-lg font-black"><span>${esc(T.billTotal)}</span><span>${fmt(calc.grand)}</span></div>
            </div>
            <p class="m-muted text-xs leading-relaxed">${esc(T.splitNote)}</p>`;
    }

    $('[data-open-split]')?.addEventListener('click', () => {
        if (!cart.length || !splitSheet) return;
        renderSplit();
        cartSheet.close();
        splitSheet.showModal();
    });
    splitSheet?.addEventListener('close', () => { renderCart(); cartSheet.showModal(); });
    splitSheet?.addEventListener('click', (e) => {
        const body = $('[data-split-body]', splitSheet);
        const keep = (fn) => { const top = splitSheet.querySelector('form').scrollTop; fn(); renderSplit(); splitSheet.querySelector('form').scrollTop = top; };
        const people = e.target.closest('[data-split-people]');
        const modeBtn = e.target.closest('[data-split-mode]');
        const unit = e.target.closest('[data-split-unit]');
        if (people) keep(() => { split.people = Math.max(2, Math.min(12, split.people + Number(people.dataset.splitPeople))); });
        else if (modeBtn) keep(() => { split.mode = modeBtn.dataset.splitMode; });
        else if (unit && body.contains(unit)) {
            keep(() => {
                const key = splitUnits()[Number(unit.dataset.splitUnit)]?.key;
                if (!key) return;
                const set = split.assign[key] || (split.assign[key] = new Set());
                const who = Number(unit.dataset.splitPerson);
                if (set.has(who)) set.delete(who); else set.add(who);
            });
        }
    });
    // typed names show in the results without re-rendering the field being typed in
    splitSheet?.addEventListener('input', (e) => {
        const field = e.target.closest('[data-split-name]');
        if (!field) return;
        split.names[Number(field.dataset.splitName)] = field.value;
        splitSheet.addEventListener('change', renderSplit, { once: true });
    });

    // ---- intro splash: tap to skip; the CSS fades it out by itself ----
    const intro = $('[data-intro]');
    if (intro) {
        const end = () => { document.documentElement.classList.remove('intro-open'); };
        intro.addEventListener('click', () => { intro.classList.add('is-leaving'); setTimeout(end, 300); });
        intro.addEventListener('animationend', (e) => { if (e.target === intro) end(); });
    }

    // ---- search & active category chip ----
    const search = $('[data-search]');
    search?.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        let any = false;
        document.querySelectorAll('[data-category]').forEach((sec) => {
            let shown = 0;
            sec.querySelectorAll('[data-search-text]').forEach((el) => {
                const hit = !q || el.dataset.searchText.includes(q);
                el.classList.toggle('hidden', !hit);
                shown += hit ? 1 : 0;
            });
            sec.classList.toggle('hidden', shown === 0);
            any ||= shown > 0;
        });
        $('[data-featured]')?.classList.toggle('hidden', !!q);
        $('[data-no-results]').classList.toggle('hidden', any);
    });

    const links = [...document.querySelectorAll('[data-cat-link]')];
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            links.forEach((a) => {
                const on = a.dataset.catLink === entry.target.id;
                a.classList.toggle('m-primary', on);
                a.classList.toggle('m-surface-2', !on);
                if (on) a.scrollIntoView({ inline: 'center', block: 'nearest' });
            });
        });
    }, { rootMargin: '-40% 0px -55% 0px' });
    document.querySelectorAll('[data-category]').forEach((s) => observer.observe(s));

    // ---- popup: shown once per always/session/day/once, counted (views & clicks only) ----
    const P = M.popup;
    if (P) {
        const key = `egmenu.popup.${M.tenant}.${P.id}`;
        let store = null; // reading window.sessionStorage itself throws when the browser blocks site data
        try { store = P.frequency === 'session' ? sessionStorage : localStorage; } catch {}
        let seen = null;
        try { seen = store?.getItem(key); } catch {}
        const due = P.frequency === 'always' || !seen || (P.frequency === 'day' && seen !== new Date().toDateString());
        if (due) {
            setTimeout(() => {
                const dlg = $('[data-popup]');
                const img = $('[data-popup-image]', dlg);
                img.classList.toggle('hidden', !P.image);
                if (P.image) img.src = P.image;
                $('[data-popup-title]', dlg).textContent = P.title || (P.item ? M.items[P.item]?.name : '');
                $('[data-popup-body]', dlg).textContent = P.body || '';
                const action = $('[data-popup-action]', dlg);
                action.textContent = P.button || (P.item ? T.order : T.view);
                action.classList.toggle('hidden', !P.item && !P.link);
                action.onclick = () => {
                    fetch(P.eventUrl.replace('__E__', 'click'), { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }).catch(() => {});
                    dlg.close();
                    if (P.item) openItem(P.item); else if (P.link) window.open(P.link, '_blank', 'noopener');
                };
                dlg.showModal();
                try { store?.setItem(key, new Date().toDateString()); } catch {}
                fetch(P.eventUrl.replace('__E__', 'view'), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }).catch(() => {});
            }, (P.delay || 0) * 1000);
        }
    }

    renderBar();
}
