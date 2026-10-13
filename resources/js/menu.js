// Public menu: item sheet with sizes/extras, cart kept per restaurant in localStorage, and ordering from the table.
// Prices here are only for display; the server recomputes every amount when the order is placed.

const dataEl = document.getElementById('menu-data');
if (dataEl) {
    const M = JSON.parse(dataEl.textContent);
    const T = JSON.parse(document.getElementById('menu-i18n').textContent);
    const $ = (sel, root = document) => root.querySelector(sel);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = (n) => `${Math.round(n * 100) / 100} ${M.currency}`;
    const storeKey = `egmenu.cart.${M.tenant}`;

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
        $('[data-cart-bar]').classList.toggle('hidden', count === 0);
        $('[data-cart-count]').textContent = count;
        $('[data-cart-total]').textContent = fmt(total);
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

    function openItem(id) {
        current = M.items[id];
        if (!current) return;
        qty = 1;
        const img = $('[data-sheet-image]', sheet);
        img.classList.toggle('hidden', !current.image);
        if (current.image) img.src = current.image;
        $('[data-sheet-name]', sheet).textContent = current.name;
        $('[data-sheet-desc]', sheet).textContent = current.description || '';
        $('[data-sheet-cal]', sheet).textContent = current.calories ? T.cal.replace('__N__', current.calories) : '';
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
                <label class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-navy-100 px-3 ${o.soldOut ? 'opacity-50' : ''}">
                    <span class="flex items-center gap-3">
                        <input type="${g.type === 'single' ? 'radio' : 'checkbox'}" name="g${g.id}" value="${o.id}" data-option data-group="${g.id}"
                            class="size-5 accent-[var(--menu-primary)]" ${o.soldOut ? 'disabled' : ''} ${g.type === 'single' && o.default && !o.soldOut ? 'checked' : ''}>
                        ${esc(o.name)}
                    </span>
                    ${o.price ? `<span class="text-sm text-navy-500">+${fmt(o.price)}</span>` : ''}
                </label>`).join('');
            return `<fieldset data-group-box="${g.id}">
                <legend class="mb-2 flex w-full items-center justify-between font-bold">${esc(g.name)}
                    <span class="badge ${g.required ? 'bg-brand-100 text-brand-700' : 'bg-navy-100 text-navy-500'}">${g.required ? T.required : esc(hint)}</span></legend>
                <div class="space-y-2">${options}</div></fieldset>`;
        }).join('');
        const soldOut = current.soldOut || !M.canOrder && false;
        $('[data-sheet-soldout]', sheet).classList.toggle('hidden', !current.soldOut);
        $('[data-add-to-cart]', sheet).classList.toggle('hidden', soldOut);
        refreshSheetTotal();
        sheet.showModal();
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
            <div class="flex items-center gap-3 rounded-xl border border-navy-100 p-2">
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

    function renderCart() {
        const lines = cart.map((l, i) => {
            const item = M.items[l.item_id];
            const opts = item.groups.flatMap((g) => g.options).filter((o) => l.option_ids.includes(o.id)).map((o) => o.name).join('، ');
            return `<li class="flex items-center gap-3 py-3">
                <div class="min-w-0 flex-1">
                    <div class="font-bold">${esc(item.name)}</div>
                    ${opts ? `<div class="text-xs text-navy-500">${esc(opts)}</div>` : ''}
                    ${l.notes ? `<div class="text-xs text-brand-700">${esc(l.notes)}</div>` : ''}
                    <div class="text-sm font-semibold">${fmt(unitPrice(item, l.option_ids) * l.qty)}</div>
                </div>
                <div class="flex items-center rounded-xl border border-navy-100">
                    <button type="button" class="size-10" data-line="${i}" data-step="-1">−</button>
                    <span class="w-6 text-center font-bold">${l.qty}</span>
                    <button type="button" class="size-10" data-line="${i}" data-step="1">+</button>
                </div>
            </li>`;
        }).join('');
        $('[data-cart-lines]', cartSheet).innerHTML = lines || `<li class="py-6 text-center text-navy-500">${esc(T.empty)}</li>`;

        const subtotal = cart.reduce((s, l) => s + unitPrice(M.items[l.item_id], l.option_ids) * l.qty, 0);
        // service is charged on table orders only; online delivery adds the zone fee
        const service = M.mode === 'table' ? subtotal * M.service / 100 : 0;
        const tax = M.canOrder ? (subtotal + service) * M.tax / 100 : 0;
        const zone = currentZone();
        const fee = zone ? zone.fee : 0;
        const row = (label, v, bold) => `<div class="flex justify-between ${bold ? 'text-base font-extrabold' : ''}"><span>${esc(label)}</span><span>${fmt(v)}</span></div>`;
        $('[data-cart-summary]', cartSheet).innerHTML = (service || tax || fee ? row(T.subtotal, subtotal) + (service ? row(T.service, service) : '') + (tax ? row(T.tax, tax) : '') + (fee ? row(T.deliveryFee, fee) : '') : '')
            + row(T.total, subtotal + service + tax + fee, true)
            + (zone && zone.min > subtotal ? `<div class="mt-1 text-xs font-semibold text-amber-700">${esc(T.minOrder.replace('__A__', fmt(zone.min)))}</div>` : '');
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

    // ---- online ordering (no table): pickup or delivery ----
    const O = M.online;
    let otype = O ? (O.takeaway ? 'takeaway' : 'delivery') : null;
    function currentZone() {
        if (M.mode !== 'online' || otype !== 'delivery') return null;
        return O.zones.find((z) => String(z.id) === $('[data-zone]')?.value) || null;
    }
    function renderType() {
        document.querySelectorAll('[data-otype]').forEach((b) => {
            const on = b.dataset.otype === otype;
            b.classList.toggle('m-primary', on);
            b.classList.toggle('border-transparent', on);
        });
        $('[data-delivery-fields]')?.classList.toggle('hidden', otype !== 'delivery');
    }
    if (O) {
        document.querySelectorAll('[data-otype]').forEach((b) => b.addEventListener('click', () => { otype = b.dataset.otype; renderType(); renderCart(); }));
        $('[data-zone]')?.addEventListener('change', renderCart);
        const when = $('[data-scheduled]');
        if (when) {
            const pad = (n) => String(n).padStart(2, '0');
            const local = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
            when.min = local(new Date(Date.now() + O.prep * 60000));
            when.max = local(new Date(Date.now() + 7 * 86400000));
        }
        renderType();
    }

    // ---- send order (a scanned table, or online pickup / delivery) ----
    const sendBtn = $('[data-send-order]');
    let clientUuid = null;
    sendBtn?.addEventListener('click', async () => {
        if (!cart.length) return;
        const errorBox = $('[data-order-error]');
        errorBox.classList.add('hidden');
        const fail = (msg) => { errorBox.textContent = msg; errorBox.classList.remove('hidden'); };
        if (M.mode === 'online') {
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
                    ...(M.mode === 'online' ? {
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
                a.classList.toggle('bg-navy-50', !on);
                if (on) a.scrollIntoView({ inline: 'center', block: 'nearest' });
            });
        });
    }, { rootMargin: '-40% 0px -55% 0px' });
    document.querySelectorAll('[data-category]').forEach((s) => observer.observe(s));

    // ---- popup: shown once per always/session/day/once, counted (views & clicks only) ----
    const P = M.popup;
    if (P) {
        const key = `egmenu.popup.${M.tenant}.${P.id}`;
        const store = P.frequency === 'session' ? sessionStorage : localStorage;
        let seen = null;
        try { seen = store.getItem(key); } catch {}
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
                try { store.setItem(key, new Date().toDateString()); } catch {}
                fetch(P.eventUrl.replace('__E__', 'view'), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } }).catch(() => {});
            }, (P.delay || 0) * 1000);
        }
    }

    renderBar();
}
