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

    // professional display: the sheet is a full item page with its own address (/item/{id}); back closes it
    const PRO = M.display === 'pro';
    const itemHref = (id) => M.itemUrl.replace(/\/0$/, `/${id}`);
    let pushedItem = false; // the page pushed a history entry for the open item
    let landedOnItem = false; // the guest opened a shared /item/{id} link
    let closingFromHistory = false;

    function openItem(id, { fromHistory = false } = {}) {
        current = M.items[id];
        if (!current) return;
        qty = 1;
        if (PRO && !fromHistory && !landedOnItem) {
            history.pushState({ egmItem: current.id }, '', itemHref(current.id));
            pushedItem = true;
        }
        const price = $('[data-sheet-price]', sheet);
        price.innerHTML = hidePrices() ? '' : `<span>${esc(fmt(current.price))}</span>`
            + (current.offerFrom ? `<span class="m-muted text-sm font-semibold line-through">${esc(fmt(current.offerFrom))}</span>` : '');
        const badgeBox = $('[data-sheet-badges]', sheet);
        badgeBox.innerHTML = (current.badges || []).map((b) => `<span class="m-tag rounded-full px-2.5 py-0.5 text-xs font-semibold">${esc(b.icon)} ${esc(b.label)}</span>`).join('');
        badgeBox.classList.toggle('hidden', !current.badges?.length);
        badgeBox.classList.toggle('flex', !!current.badges?.length);
        // the full page without a photo: room for the back / share buttons on top
        $('[data-sheet-body]', sheet).classList.toggle('pt-20', PRO && !current.image);
        track('ViewContent', { content_ids: [String(current.id)], content_name: current.name, content_type: 'product', value: current.price });
        const img = $('[data-sheet-image]', sheet);
        img.classList.toggle('hidden', !current.image);
        if (current.image) img.src = current.image;
        resetVideo();
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
        // nothing to add: no quantity, notes or add bar either
        $('[data-sheet-actions]', sheet).classList.toggle('hidden', soldOut);
        $('[data-sheet-notes-box]', sheet).classList.toggle('hidden', soldOut);
        refreshSheetTotal();
        if (!sheet.open) sheet.showModal();
        if (PRO) $('[data-item-form]', sheet).scrollTop = 0;
        countView(current.id);
    }

    sheet.addEventListener('close', () => {
        if (pushedItem) {
            pushedItem = false;
            if (!closingFromHistory) history.back();
        } else if (landedOnItem) {
            // a shared item link: closing it leaves the guest on the menu's own address
            landedOnItem = false;
            history.replaceState(null, '', M.menuUrl);
        }
        closingFromHistory = false;
    });
    window.addEventListener('popstate', (e) => {
        const id = e.state?.egmItem;
        if (sheet.open && !id) {
            closingFromHistory = true;
            sheet.close();
        } else if (PRO && id && !sheet.open) {
            openItem(id, { fromHistory: true });
            pushedItem = true;
        }
    });
    // share the item page: the phone's share sheet, else copy the link
    $('[data-sheet-share]', sheet)?.addEventListener('click', async () => {
        const url = new URL(itemHref(current.id), location.href).href;
        if (navigator.share) {
            try { await navigator.share({ title: current.name, url }); } catch {}
            return;
        }
        try { await navigator.clipboard.writeText(url); toast(T.linkCopied); } catch {}
    });

    // ---- item video: a "watch" button; uploads play muted and looping inline, links load their embed on tap ----
    const videoBox = $('[data-sheet-video]', sheet);
    const videoPlay = $('[data-sheet-video-play]', sheet);
    function resetVideo() {
        videoBox.innerHTML = '';
        videoBox.classList.add('hidden');
        const has = !!current?.video;
        videoPlay.classList.toggle('hidden', !has);
        videoPlay.classList.toggle('flex', has);
        // without a photo the button sits in the flow instead of over the picture
        videoPlay.classList.toggle('absolute', !!current?.image);
        videoPlay.classList.toggle('m-5', has && !current.image);
        videoPlay.classList.toggle('mb-0', has && !current.image);
    }
    videoPlay.addEventListener('click', () => {
        const v = current?.video;
        if (!v) return;
        if (v.type === 'file') {
            videoBox.style.aspectRatio = 'auto';
            videoBox.innerHTML = `<video class="max-h-[60vh] w-full object-contain" src="${esc(v.src)}" ${current.image ? `poster="${esc(current.image)}"` : ''} autoplay muted loop playsinline controls></video>`;
            const el = $('video', videoBox);
            el.muted = true; // never with sound unless the guest turns it on
            el.play?.().catch(() => {});
        } else {
            videoBox.style.aspectRatio = v.provider === 'youtube' ? '16 / 9' : '9 / 16';
            videoBox.innerHTML = `<iframe class="mx-auto h-full max-h-[70vh] w-full" src="${esc(v.src)}" loading="lazy" title="${esc(current.name)}"
                allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>`;
        }
        videoBox.classList.remove('hidden');
        $('[data-sheet-image]', sheet).classList.add('hidden');
        videoPlay.classList.add('hidden');
        videoPlay.classList.remove('flex');
    });
    sheet.addEventListener('close', () => { videoBox.innerHTML = ''; });

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
        addLine(current, ids, qty, $('[data-sheet-notes]', sheet).value.trim());
        sheet.close();
        showPairings(current);
    });

    // the one way a line enters the cart (item sheet, "goes well with", quick "+"): same item, options and notes add up
    function addLine(item, optionIds, count, notes = '') {
        const same = cart.find((l) => l.item_id === item.id && l.notes === notes && l.option_ids.join() === optionIds.join());
        if (same) same.qty = Math.min(30, same.qty + count);
        else cart.push({ item_id: item.id, option_ids: optionIds, qty: count, notes });
        track('AddToCart', { content_ids: [String(item.id)], content_name: item.name, content_type: 'product', value: unitPrice(item, optionIds) * count, num_items: count });
        save();
        renderBar();
    }

    // ---- quick "+" on the items: one tap adds one; a size or a required choice opens the sheet instead ----
    // shown while the cart can order: a table or online order, or the hall list with prices (App\Support\MenuLayouts)
    const quickOn = () => mode === 'table' || mode === 'online' || (mode === 'list' && H.prices);
    const syncQuick = () => $('[data-menu-main]')?.classList.toggle('m-quick-off', !quickOn());
    const toastEl = $('[data-toast]');
    let toastTimer = null;
    function toast(text) {
        if (!toastEl) return;
        // over an open item page the toast must live inside the dialog (top layer)
        const host = sheet.open ? sheet : document.body;
        if (toastEl.parentElement !== host) host.append(toastEl);
        toastEl.textContent = text;
        toastEl.classList.add('is-on');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('is-on'), 1800);
    }
    function bumpCount() {
        const count = $('[data-cart-count]');
        count.classList.remove('m-bump');
        void count.offsetWidth; // restart the animation
        count.classList.add('m-bump');
    }
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-quick-add]');
        if (!btn) return;
        const item = M.items[btn.dataset.quickItem];
        if (!item || item.soldOut || !quickOn()) return;
        if (btn.dataset.quickAdd === 'sheet' || item.groups.some((g) => g.required || g.type === 'single')) {
            openItem(item.id);
            return;
        }
        addLine(item, [], 1);
        bumpCount();
        toast(T.added.replace('__N__', item.name));
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
        addLine(p, [], 1);
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
        const goal = goalState(subtotal);
        const service = inHall() ? (subtotal - goal.off) * M.service / 100 : 0;
        const tax = mode === 'none' ? 0 : (subtotal - goal.off + service) * M.tax / 100;
        const zone = currentZone();
        const fee = zone ? zone.fee : 0;
        return { subtotal, goal, service, tax, fee, zone, total: subtotal - goal.off + service + tax + fee };
    }

    // ---- cart goal ("add X and get Y free"): progress only; the server decides the reward with its own prices ----
    const G = M.goal;
    function goalState(subtotal) {
        const none = { on: false, reached: false, off: 0, left: 0, blocked: false };
        if (!G || !cart.length || mode === 'none') return none;
        const left = Math.max(0, G.amount - subtotal);
        const reached = left < 0.005;
        const blocked = reached && !G.withCoupon && !!$('[data-coupon]')?.value.trim();
        // only orders sent from the menu get it; a list for the waiter just shows the progress
        const applies = reached && !blocked && mode !== 'list';
        const off = applies && G.reward !== 'item' ? Math.min(subtotal, G.reward === 'percent' ? subtotal * G.value / 100 : G.value) : 0;
        return { on: true, reached, applies, off, left, blocked, pct: Math.min(100, (subtotal / G.amount) * 100) };
    }
    function renderGoal(t) {
        const box = $('[data-goal-box]', cartSheet);
        if (!box) return;
        const g = t.goal;
        box.classList.toggle('hidden', !g.on || hidePrices());
        if (!g.on) return;
        const isItem = G.reward === 'item';
        let text;
        if (!g.reached) text = `🎁 ${(isItem ? T.goalItemLeft : T.goalOffLeft).replace(':left', fmt(g.left)).replace(':reward', G.rewardName)}`;
        else if (mode === 'list') text = `🎉 ${T.goalListDone.replace(':reward', G.rewardName)}`;
        else if (g.blocked) text = `🎁 ${T.goalCoupon}`;
        else text = `🎉 ${(isItem ? T.goalItemDone : T.goalOffDone).replace(':reward', G.rewardName)}`;
        $('[data-goal-text]', box).textContent = text;
        $('[data-goal-track]', box).classList.toggle('hidden', mode === 'list');
        $('[data-goal-fill]', box).style.width = `${g.pct}%`;
        box.classList.toggle('m-primary-border', g.reached);
    }
    $('[data-coupon]')?.addEventListener('input', () => { if (G) renderCart(); });

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
        const t = totals();
        // the free item of a reached goal, shown as it will appear on the order
        const gift = t.goal.applies && G.reward === 'item' && M.items[G.item] ? `<li class="flex items-center gap-3 py-3" data-goal-line>
                <div class="min-w-0 flex-1"><div class="font-bold">🎁 ${esc(M.items[G.item].name)}</div></div>
                <span class="m-primary rounded-full px-3 py-1 text-xs font-extrabold">${esc(T.goalFree)}</span>
            </li>` : '';
        $('[data-cart-lines]', cartSheet).innerHTML = lines ? lines + gift : `<li class="m-muted py-6 text-center">${esc(T.empty)}</li>`;
        // an empty cart shows only "the cart is empty": no order fields and no send button
        cartSheet.classList.toggle('m-cart-empty', !cart.length);
        renderGoal(t);
        const row = (label, v, bold) => `<div class="flex justify-between ${bold ? 'text-base font-extrabold' : ''}"><span>${esc(label)}</span><span>${fmt(v)}</span></div>`;
        const summary = $('[data-cart-summary]', cartSheet);
        summary.classList.toggle('hidden', hidePrices() || !cart.length);
        summary.innerHTML = (t.service || t.tax || t.fee || t.goal.off ? row(T.subtotal, t.subtotal) + (t.goal.off ? row(`🎁 ${G.rewardName}`, -t.goal.off) : '') + (t.service ? row(T.service, t.service) : '') + (t.tax ? row(T.tax, t.tax) : '') + (t.fee ? row(T.deliveryFee, t.fee) : '') : '')
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
        // the order-type chip in the header: "🛵 Delivery · Maadi ▾"
        const chip = $('[data-way-chip-label]');
        if (chip && way && T.wayChips[way]) {
            const zone = way === 'delivery' ? currentZone() : null;
            chip.textContent = T.wayChips[way] + (zone ? ` · ${zone.name.split('› ').pop()}` : '');
        }
        syncQuick();
        renderBar();
        if (cartSheet.open) renderCart();
    }
    function applyWay(way) {
        if (way === 'dine') mode = hallCart;
        else if (O && O[way]) { mode = 'online'; otype = way; }
        renderMode();
    }
    const root = document.documentElement;
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-way], [data-online] [data-otype]');
        if (!b) return;
        const way = b.dataset.way || b.dataset.otype;
        if (!H.table) session.set(wayKey, way);
        applyWay(way);
        if (b.closest('[data-order-choice]')) root.classList.remove('choice-open', 'choice-reopen');
        // delivery: the area comes first, so the fee, the minimum and the time are known before adding items
        if (way === 'delivery' && !currentZone()) openZones();
    });
    // the chip in the header reopens the "how will you order?" sheet
    $('[data-way-chip]')?.addEventListener('click', () => root.classList.add('choice-open', 'choice-reopen'));
    $('[data-choice-close]')?.addEventListener('click', () => root.classList.remove('choice-open', 'choice-reopen'));

    // ---- delivery area: main area, then its street / sub-area; "detect my area" against drawn zones (map mode) ----
    const zoneSheet = $('[data-zone-sheet]');
    const zoneKey = `egmenu.zone.${M.tenant}`;
    function zoneStep(step) {
        zoneSheet.querySelectorAll('[data-zone-step]').forEach((el) => el.classList.toggle('hidden', el.dataset.zoneStep !== String(step)));
    }
    function openZones() {
        if (!zoneSheet) return;
        zoneStep('root');
        $('[data-zone-outside]', zoneSheet).classList.add('hidden');
        if (!zoneSheet.open) zoneSheet.showModal();
    }
    function pickZone(id) {
        const select = $('[data-zone]');
        if (!select || !O.zones.some((z) => String(z.id) === String(id))) return;
        select.value = String(id);
        session.set(zoneKey, String(id));
        zoneSheet?.close();
        renderMode();
        renderCart();
    }
    zoneSheet?.addEventListener('click', (e) => {
        const open = e.target.closest('[data-zone-open]');
        const pick = e.target.closest('[data-zone-pick]');
        if (open) zoneStep(open.dataset.zoneOpen);
        else if (pick) pickZone(pick.dataset.zonePick);
        else if (e.target.closest('[data-zone-back]')) zoneStep('root');
        else if (e.target.closest('[data-zone-detect]')) detectZone(e.target.closest('[data-zone-detect]'));
    });
    zoneSheet?.addEventListener('input', (e) => {
        const box = e.target.closest('[data-zone-search]');
        if (!box) return;
        const q = box.value.trim().toLowerCase();
        box.parentElement.querySelectorAll('[data-zone-text]').forEach((b) => b.classList.toggle('hidden', !!q && !b.dataset.zoneText.includes(q)));
    });
    // ray casting, the same test as App\Models\DeliveryZone::contains (points are [lat, lng])
    const inside = (poly, lat, lng) => {
        let hit = false;
        for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
            const [ai, oi] = poly[i];
            const [aj, oj] = poly[j];
            if ((oi > lng) !== (oj > lng) && lat < ((aj - ai) * (lng - oi)) / (oj - oi) + ai) hit = !hit;
        }
        return hit;
    };
    function detectZone(btn) {
        if (!navigator.geolocation) return;
        const label = btn.textContent;
        btn.disabled = true;
        btn.textContent = T.locating;
        navigator.geolocation.getCurrentPosition((pos) => {
            btn.disabled = false;
            btn.textContent = label;
            const { latitude: lat, longitude: lng } = pos.coords;
            const hits = O.zones.filter((z) => z.polygon && inside(z.polygon, lat, lng));
            // a drawn street inside a drawn area wins over the area
            const zone = hits.find((z) => z.parent) || hits[0];
            if (zone) pickZone(zone.id);
            else $('[data-zone-outside]', zoneSheet).classList.remove('hidden');
        }, () => {
            btn.disabled = false;
            btn.textContent = label;
            $('[data-zone-outside]', zoneSheet).classList.remove('hidden');
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 120000 });
    }

    // a remembered choice on the general link (the "how will you order?" screen or the cart switch)
    if (O) {
        const savedZone = session.get(zoneKey);
        const select = $('[data-zone]');
        if (select && savedZone && O.zones.some((z) => String(z.id) === savedZone)) select.value = savedZone;
    }
    if (!H.table && (M.ways || []).length > 1) {
        const saved = session.get(wayKey);
        if (saved && M.ways.includes(saved)) applyWay(saved);
    }
    if (O) {
        $('[data-zone]')?.addEventListener('change', () => { session.set(zoneKey, $('[data-zone]').value); renderMode(); renderCart(); });
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

    // ---- search, badge filter chips (all picked labels must match) & active category chip ----
    const search = $('[data-search]');
    const pickedBadges = new Set();
    function applyFilter() {
        const q = search ? search.value.trim().toLowerCase() : '';
        let any = false;
        document.querySelectorAll('[data-category]').forEach((sec) => {
            let shown = 0;
            sec.querySelectorAll('[data-search-text]').forEach((el) => {
                const badges = (el.dataset.badges || '').split(' ');
                const hit = (!q || el.dataset.searchText.includes(q)) && [...pickedBadges].every((b) => badges.includes(b));
                el.classList.toggle('hidden', !hit);
                shown += hit ? 1 : 0;
            });
            sec.classList.toggle('hidden', shown === 0);
            any ||= shown > 0;
        });
        $('[data-featured]')?.classList.toggle('hidden', !!q || pickedBadges.size > 0);
        $('[data-no-results]').classList.toggle('hidden', any);
    }
    search?.addEventListener('input', applyFilter);
    document.querySelectorAll('[data-badge-filter]').forEach((chip) => chip.addEventListener('click', () => {
        const key = chip.dataset.badgeFilter;
        const on = !pickedBadges.has(key);
        if (on) pickedBadges.add(key); else pickedBadges.delete(key);
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        applyFilter();
    }));

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

    // ---- the top of the menu: banner slideshow and ticker. Only one of them moves by itself (the server decides,
    // App\Support\MenuTicker); nothing moves on its own under reduced motion. Both stop while held, hovered,
    // focused or in a hidden tab, and go on afterwards. ----
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    function autoplay(box, seconds, step) {
        let timer = null;
        let held = false;
        let onScreen = true;
        const stop = () => { clearInterval(timer); timer = null; };
        const start = () => { stop(); if (!held && onScreen && !document.hidden) timer = setInterval(step, seconds * 1000); };
        const hold = () => { held = true; stop(); };
        const release = () => { held = false; start(); };
        // a real mouse hovering, a finger on it, or keyboard focus inside (a tap's focus does not hold it)
        box.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') hold(); });
        box.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') release(); });
        box.addEventListener('touchstart', hold, { passive: true });
        ['touchend', 'touchcancel', 'focusout'].forEach((ev) => box.addEventListener(ev, release, { passive: true }));
        box.addEventListener('focusin', (e) => { if (e.target.matches?.(':focus-visible')) hold(); });
        document.addEventListener('visibilitychange', start);
        new IntersectionObserver(([entry]) => { onScreen = entry.isIntersecting; start(); }).observe(box);
        return start;
    }

    const bannerBox = $('[data-banners]');
    const slides = bannerBox ? [...bannerBox.querySelectorAll('[data-banner-slide]')] : [];
    if (slides.length > 1) {
        const strip = $('[data-banner-track]', bannerBox);
        const dots = [...bannerBox.querySelectorAll('[data-banner-dot]')];
        const rtl = getComputedStyle(strip).direction === 'rtl';
        let slideAt = 0;
        const goTo = (i) => {
            const slide = slides[(i + slides.length) % slides.length];
            const s = slide.getBoundingClientRect();
            const t = strip.getBoundingClientRect();
            strip.scrollBy({ left: rtl ? s.right - t.right : s.left - t.left, behavior: reducedMotion ? 'auto' : 'smooth' });
        };
        // the dot follows the slide in view, whether swiped, played or tapped
        const inView = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                slideAt = slides.indexOf(entry.target);
                dots.forEach((d, n) => d.setAttribute('aria-current', n === slideAt ? 'true' : 'false'));
            });
        }, { root: strip, threshold: 0.6 });
        slides.forEach((s) => inView.observe(s));
        const seconds = Number(bannerBox.dataset.bannerAutoplay || 0);
        const restart = seconds && !reducedMotion ? autoplay(bannerBox, seconds, () => goTo(slideAt + 1)) : () => {};
        dots.forEach((d) => d.addEventListener('click', () => { goTo(Number(d.dataset.bannerDot)); restart(); }));
    }

    const ticker = $('[data-ticker]');
    const tickerLines = ticker ? [...ticker.querySelectorAll('[data-ticker-line]')] : [];
    if (tickerLines.length > 1) {
        let lineAt = 0;
        const showLine = (i) => {
            const prev = tickerLines[lineAt];
            lineAt = (i + tickerLines.length) % tickerLines.length;
            const next = tickerLines[lineAt];
            prev.classList.replace('is-on', 'is-out');
            prev.setAttribute('aria-hidden', 'true');
            prev.tabIndex = -1;
            next.classList.remove('is-out');
            next.classList.add('is-on');
            next.removeAttribute('aria-hidden');
            next.removeAttribute('tabindex');
            setTimeout(() => { if (!prev.classList.contains('is-on')) prev.classList.remove('is-out'); }, 600);
        };
        const nextBtn = $('[data-ticker-next]', ticker);
        const rotates = ticker.dataset.tickerMode === 'rotate' && !reducedMotion;
        // standing still: the guest steps through the lines
        if (!rotates) nextBtn?.classList.remove('hidden');
        nextBtn?.addEventListener('click', () => showLine(lineAt + 1));
        if (rotates) autoplay(ticker, Number(ticker.dataset.tickerSeconds || 4), () => showLine(lineAt + 1));
    }

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

    // ---- branch switcher (2+ branches, no table): nearest (location on tap only), current, last visit ----
    const branchSheet = $('[data-branch-sheet]');
    const B = M.branches || [];
    if (branchSheet && B.length > 1) {
        const memKey = `egmenu.branch.${M.tenant}`;
        let mem = {};
        try { mem = JSON.parse(localStorage.getItem(memKey) || '{}') || {}; } catch {}
        const firstVisit = !mem.current;
        const lastVisit = mem.current && mem.current !== M.branch ? mem.current : (mem.previous ?? null);
        try { localStorage.setItem(memKey, JSON.stringify({ current: M.branch, previous: lastVisit })); } catch {}
        let distances = null;
        const tag = (text, strong) => `<span class="rounded-full px-2 py-0.5 text-[11px] font-extrabold ${strong ? 'm-primary' : 'm-surface-2 m-muted'}">${esc(text)}</span>`;
        const km = (d) => T.km.replace('__N__', d < 10 ? d.toFixed(1) : Math.round(d));
        function renderBranches() {
            const rows = [...B];
            if (distances) rows.sort((a, b) => (distances[a.id] ?? 1e9) - (distances[b.id] ?? 1e9));
            const nearest = distances ? rows.find((r) => distances[r.id] != null)?.id : null;
            $('[data-branch-list]', branchSheet).innerHTML = rows.map((b) => {
                const tags = [
                    b.id === nearest ? tag(T.nearest, true) : '',
                    b.id === M.branch ? tag(T.current, b.id !== nearest) : '',
                    b.id === lastVisit ? tag(T.lastVisit, false) : '',
                ].join('');
                const open = b.open === null ? '' : `<span class="text-xs font-bold ${b.open ? 'text-emerald-700' : 'text-red-600'}">● ${esc(b.open ? T.openNow : T.closedNow)}</span>`;
                const dist = distances && distances[b.id] != null ? `<span class="m-muted text-xs font-bold" dir="ltr">${esc(km(distances[b.id]))}</span>` : '';
                return `<div class="m-border rounded-2xl border p-3 ${b.id === M.branch ? 'm-primary-border' : ''}" data-branch-row="${b.id}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5"><b class="text-base">${esc(b.name)}</b>${tags}</div>
                            ${b.address ? `<div class="m-muted mt-0.5 text-sm">${esc(b.address)}</div>` : ''}
                            <div class="mt-1 flex flex-wrap items-center gap-3">${open}${dist}${b.hours ? `<span class="m-muted text-xs" dir="auto">${esc(b.hours)}</span>` : ''}</div>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <a href="?branch=${encodeURIComponent(b.slug)}" class="m-primary inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-extrabold" data-branch-pick="${esc(b.slug)}">${esc(T.choose)}</a>
                            ${b.map ? `<a href="${esc(b.map)}" target="_blank" rel="noopener" class="m-primary-text text-xs font-bold">🗺 ${esc(T.onMap)}</a>` : ''}
                        </div>
                    </div>
                </div>`;
            }).join('');
        }
        const openBranches = () => { renderBranches(); if (!branchSheet.open) branchSheet.showModal(); };
        $('[data-branch-open]')?.addEventListener('click', openBranches);
        $('[data-branch-near]', branchSheet)?.addEventListener('click', (e) => {
            const note = $('[data-branch-note]', branchSheet);
            const btn = e.currentTarget;
            if (!navigator.geolocation) { note.textContent = T.noLocation; note.classList.remove('hidden'); return; }
            note.textContent = T.locating;
            note.classList.remove('hidden');
            btn.disabled = true;
            navigator.geolocation.getCurrentPosition((pos) => {
                const { latitude, longitude } = pos.coords;
                const rad = Math.PI / 180;
                distances = {};
                B.forEach((b) => {
                    if (b.lat == null || b.lng == null) return;
                    const a = Math.sin(((b.lat - latitude) * rad) / 2) ** 2
                        + Math.cos(latitude * rad) * Math.cos(b.lat * rad) * Math.sin(((b.lng - longitude) * rad) / 2) ** 2;
                    distances[b.id] = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                });
                note.classList.add('hidden');
                btn.disabled = false;
                renderBranches();
            }, () => { note.textContent = T.noLocation; btn.disabled = false; }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
        });
        // first visit on the general link with no branch chosen: offer the switcher once, after the intro / order type
        if (firstVisit && !M.branchChosen) {
            const clear = () => !root.classList.contains('intro-open') && !root.classList.contains('choice-open');
            if (clear()) setTimeout(openBranches, 300);
            else {
                const watch = new MutationObserver(() => { if (clear()) { watch.disconnect(); setTimeout(openBranches, 300); } });
                watch.observe(root, { attributes: true, attributeFilter: ['class'] });
            }
        }
    }

    syncQuick();
    renderBar();
    // a shared item link (/item/{id}) opens that item over the menu
    if (M.openItem && M.items[M.openItem]) {
        landedOnItem = true;
        openItem(M.openItem, { fromHistory: true });
    }
}

// Phones: when the keyboard opens over a sheet (cart name, phone, address…), bring the focused field into view
// once the visible area has shrunk, so the guest always sees what they type.
document.addEventListener('focusin', (event) => {
    const field = event.target;
    if (!(field instanceof HTMLElement) || !field.matches('input, textarea, select') || !field.closest('dialog')) {
        return;
    }
    setTimeout(() => field.scrollIntoView({ block: 'center', behavior: 'smooth' }), 320);
});
