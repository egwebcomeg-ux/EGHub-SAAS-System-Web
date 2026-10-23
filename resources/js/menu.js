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
        track('ViewContent', { content_ids: [String(current.id)], content_name: current.name, content_type: 'product', value: current.price });
        const img = $('[data-sheet-image]', sheet);
        img.classList.toggle('hidden', !current.image);
        if (current.image) img.src = current.image;
        // professional page: without a photo the circle shows the category icon (or the first letter)
        const ph = $('[data-sheet-ph]', sheet);
        if (ph) {
            ph.innerHTML = current.image ? '' : (M.placeholders && M.phIcons?.[current.icon]) || esc([...current.name][0] || '');
            ph.classList.toggle('hidden', !!current.image);
            ph.classList.toggle('grid', !current.image);
        }
        // "الأكثر طلباً" of this branch (real sales, App\Services\Menu\BestSellers)
        const best = $('[data-sheet-best]', sheet);
        if (best) {
            const on = (M.best || []).includes(current.id);
            best.classList.toggle('hidden', !on);
            best.classList.toggle('inline-flex', on);
        }
        syncFavButtons();
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
        // without a photo the button sits in the flow instead of over the picture (the full page always has its hero)
        const over = PRO || !!current?.image;
        videoPlay.classList.toggle('absolute', over);
        videoPlay.classList.toggle('m-5', has && !over);
        videoPlay.classList.toggle('mb-0', has && !over);
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
        if (!PRO) $('[data-sheet-image]', sheet).classList.add('hidden');
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

    // ---- a banner on a real offer: "order now" adds one at once when nothing must be chosen, else opens the item ----
    const needsChoice = (item) => item.groups.some((g) => g.required || g.type === 'single');
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-offer-order]');
        if (!btn) return;
        const item = M.items[btn.dataset.offerOrder];
        if (!item) return;
        if (item.soldOut || !quickOn() || needsChoice(item)) {
            openItem(item.id);
            return;
        }
        addLine(item, [], 1);
        bumpCount();
        toast(T.added.replace('__N__', item.name));
    });

    // ---- favourites: a heart on the cards and in the item sheet, kept on this phone only ----
    const favKey = `egmenu.fav.${M.tenant}`;
    let favs = [];
    try { favs = (JSON.parse(localStorage.getItem(favKey) || '[]') || []).map(Number).filter((id) => M.items[id]); } catch {}
    const search = $('[data-search]');
    const searching = () => !!search?.value.trim();
    const phTile = (item, cls) => (item.image
        ? `<img src="${esc(item.image)}" alt="" loading="lazy" class="${cls} object-cover">`
        : `<span class="m-ph ${cls} grid place-items-center">${M.placeholders ? (M.phIcons?.[item.icon] || '') : ''}</span>`);
    function syncFavButtons() {
        document.querySelectorAll('[data-fav]').forEach((b) => {
            b.setAttribute('aria-pressed', favs.includes(Number(b.dataset.fav)) ? 'true' : 'false');
        });
        sheet.querySelectorAll('[data-sheet-fav]').forEach((b) => {
            const on = !!current && favs.includes(current.id);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.setAttribute('aria-label', on ? T.favRemove : T.favAdd);
        });
    }
    function renderFavs() {
        const box = $('[data-favs]');
        const list = favs.map((id) => M.items[id]).filter(Boolean);
        box?.classList.toggle('hidden', !list.length || searching());
        document.querySelectorAll('[data-fav-chip]').forEach((chip) => chip.classList.toggle('hidden', !list.length));
        if (!box || !list.length) return;
        $('[data-favs-list]', box).innerHTML = list.map((item) => `
            <button type="button" data-item="${item.id}" class="w-32 shrink-0 overflow-hidden rounded-2xl border m-border m-surface text-start shadow-sm">
                ${phTile(item, 'aspect-square w-full')}
                <div class="p-2"><div class="line-clamp-2 text-sm font-bold">${esc(item.name)}</div>
                ${hidePrices() ? '' : `<div class="m-primary-text text-sm font-extrabold">${esc(fmt(item.price))}</div>`}</div>
            </button>`).join('');
    }
    function toggleFav(id) {
        const on = !favs.includes(id);
        favs = on ? [id, ...favs] : favs.filter((f) => f !== id);
        try { localStorage.setItem(favKey, JSON.stringify(favs)); } catch {}
        syncFavButtons();
        renderFavs();
        toast(on ? T.favOn : T.favOff);
    }
    document.addEventListener('click', (e) => {
        const card = e.target.closest('[data-fav]');
        if (card) toggleFav(Number(card.dataset.fav));
        else if (e.target.closest('[data-sheet-fav]') && current) toggleFav(current.id);
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
    const stepper = (i) => `<div class="m-stepper shrink-0">
                    <button type="button" data-line="${i}" data-step="-1" aria-label="−">−</button>
                    <button type="button" data-line="${i}" data-step="1" aria-label="+">+</button>
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
                <span class="m-line-ph" aria-hidden="true">${item.image ? `<img src="${esc(item.image)}" alt="" loading="lazy">` : (M.placeholders ? (M.phIcons?.[item.icon] || '') : '')}</span>
                <div class="min-w-0 flex-1">
                    <div class="font-bold">${esc(item.name)}</div>
                    ${opts ? `<div class="m-muted text-xs">${esc(opts)}</div>` : ''}
                    ${l.notes ? `<div class="m-primary-text text-xs">${esc(l.notes)}</div>` : ''}
                    ${price}
                </div>
                <div class="m-stepper shrink-0">
                    <button type="button" data-line="${i}" data-step="-1" aria-label="−">−</button>
                    <span>${l.qty}</span>
                    <button type="button" data-line="${i}" data-step="1" aria-label="+">+</button>
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
        $('[data-bill]', cartSheet)?.classList.toggle('hidden', hidePrices() || !cart.length);
        // savings of the running offers: the price before the offer is real data of the menu (MenuCatalog)
        const saved = cart.reduce((s, l) => { const it = M.items[l.item_id]; return s + (it.offerFrom ? (it.offerFrom - it.price) * l.qty : 0); }, 0);
        const breakdown = t.service || t.tax || t.fee || t.goal.off;
        summary.innerHTML = (breakdown ? row(T.subtotal, t.subtotal) + (t.goal.off ? row(`🎁 ${G.rewardName}`, -t.goal.off) : '') + (t.service ? row(T.service, t.service) : '') + (t.tax ? row(T.tax, t.tax) : '') + (t.fee ? row(T.deliveryFee, t.fee) : '') : '')
            + (breakdown ? `<div class="m-border mt-1 border-t pt-1.5">${row(T.total, t.total, true)}</div>` : row(T.total, t.total, true))
            + (saved > 0.005 ? `<div class="text-xs font-bold text-emerald-700" data-offers-saved>🏷 ${esc(T.offersSaved.replace('__A__', fmt(saved)))}</div>` : '')
            + (t.zone && t.zone.min > t.subtotal ? `<div class="mt-1 text-xs font-semibold text-amber-700">${esc(T.minOrder.replace('__A__', fmt(t.zone.min)))}</div>` : '');
        // the expected time: the zone's minutes for delivery, else the kitchen's preparation time (online orders)
        const eta = $('[data-cart-eta]', cartSheet);
        if (eta) {
            const minutes = mode === 'online' ? (otype === 'delivery' ? (t.zone?.minutes || null) : O?.prep) : null;
            eta.textContent = minutes ? `⏱ ${(otype === 'delivery' ? T.etaDelivery : T.etaPickup).replace('__N__', minutes)}` : '';
            eta.classList.toggle('hidden', !minutes);
            eta.classList.toggle('flex', !!minutes);
        }
        renderSendHelp(t);
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

    // the send button waits until the order can go, and the line under it says exactly what is missing
    function missingFields(t) {
        if (mode !== 'online') return [];
        const val = (sel) => $(sel)?.value.trim() || '';
        const list = [];
        if (!val('[data-customer-name]')) list.push(T.missingName);
        if (!val('[data-customer-phone]')) list.push(T.missingPhone);
        if (otype === 'delivery' && !t.zone) list.push(T.missingZone);
        if (otype === 'delivery' && !val('[data-address]')) list.push(T.missingAddress);
        return list;
    }
    function renderSendHelp(t = totals()) {
        const btn = $('[data-send-order]');
        const help = $('[data-send-help]');
        if (!btn || !help) return;
        const missing = missingFields(t);
        const short = mode === 'online' && t.zone && t.zone.min > t.subtotal ? t.zone.min - t.subtotal : 0;
        const lines = [];
        if (missing.length) lines.push(T.missing.replace('__L__', missing.join(document.documentElement.lang === 'ar' ? '، ' : ', ')));
        if (short > 0) lines.push(T.missingMin.replace('__A__', fmt(short)).replace('__M__', fmt(t.zone.min)));
        const blocked = lines.length > 0;
        if (!btn.dataset.busy) btn.disabled = blocked;
        help.textContent = lines.join(' · ');
        help.classList.toggle('hidden', !blocked);
    }
    cartSheet.addEventListener('input', (e) => {
        if (e.target.closest('[data-customer-name], [data-customer-phone], [data-address]')) renderSendHelp();
    });

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
    function chooseWay(way) {
        if (!H.table) session.set(wayKey, way);
        applyWay(way);
        // delivery: the area comes first, so the fee, the minimum and the time are known before adding items
        if (way === 'delivery' && !currentZone()) openZones();
    }
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-way], [data-online] [data-otype]');
        // the first-open sheet handles its own cards (they may move to another branch first)
        if (!b || b.closest('[data-order-choice]')) return;
        chooseWay(b.dataset.way || b.dataset.otype);
    });

    // ---- the first-open sheet, one step: the branch (2+ branches, no table) on top, a tap on an order type continues ----
    const firstSheet = $('[data-order-choice]');
    let pickedBranch = null;
    const branchMemKey = `egmenu.branch.${M.tenant}`;
    const zoneAskKey = `egmenu.zoneAsk.${M.tenant}`;
    const wayCards = () => firstSheet?.querySelectorAll('[data-way-pick] [data-way]') ?? [];
    // the way chosen before (this sheet or the cart switch) is marked, so a reopen shows what is on
    function markWays() {
        const saved = session.get(wayKey);
        wayCards().forEach((b) => {
            b.setAttribute('aria-pressed', b.dataset.way === saved ? 'true' : 'false');
            b.classList.remove('is-going');
        });
    }
    function openFirstSheet(reopen, focus) {
        if (!firstSheet) return;
        markWays();
        renderBranchPart?.();
        root.classList.add('choice-open');
        if (reopen) root.classList.add('choice-reopen');
        if (focus) setTimeout(() => $(focus, firstSheet)?.scrollIntoView({ block: 'start', behavior: reducedMotionNow() ? 'auto' : 'smooth' }), 50);
    }
    const closeFirstSheet = () => root.classList.remove('choice-open', 'choice-reopen');
    const reducedMotionNow = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    let renderBranchPart = null;
    // the guest's choice: the way (if any) is remembered and applied; another branch reloads its own menu
    function finishFirstSheet(way) {
        const current = (M.branches || []).find((b) => b.id === M.branch)?.slug;
        const moving = pickedBranch && pickedBranch !== current;
        if (way && (M.ways || []).includes(way)) {
            if (!H.table) session.set(wayKey, way);
            applyWay(way);
        }
        if (moving) {
            // the other branch's menu (prices, what is available) loads; its delivery areas are asked there
            if (way === 'delivery') session.set(zoneAskKey, '1');
            const url = new URL(location.href);
            url.searchParams.set('branch', pickedBranch);
            location.href = url.toString();
            return;
        }
        closeFirstSheet();
        if (way === 'delivery' && !currentZone()) openZones();
    }
    if (firstSheet) {
        const hasWays = wayCards().length > 0;
        firstSheet.addEventListener('click', (e) => {
            const way = e.target.closest('[data-way-pick] [data-way]');
            if (way) {
                // a short "pressed" flash on the card, then on to the menu
                way.classList.add('is-going');
                way.setAttribute('aria-pressed', 'true');
                setTimeout(() => finishFirstSheet(way.dataset.way), reducedMotionNow() ? 0 : 160);
                return;
            }
            const pick = e.target.closest('[data-branch-pick]');
            if (pick) {
                e.preventDefault();
                pickedBranch = pick.dataset.branchPick;
                renderBranchPart?.();
                // only a branch to choose: the tap is the choice
                if (!hasWays) finishFirstSheet(null);
                return;
            }
            if (e.target.closest('[data-choice-close]')) closeFirstSheet();
        });
        // the chips in the header reopen it; the branch chip brings the branch list into view
        $('[data-way-chip]')?.addEventListener('click', () => openFirstSheet(true));
        $('[data-branch-open]')?.addEventListener('click', () => openFirstSheet(true, '[data-branch-sheet]'));
    }

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

    // ---- "pin my location": a draggable pin on the map (Leaflet loaded on demand from the pinned CDN copy) ----
    // The point goes with the address (the driver navigates to it, the tracking map shows it). With drawn zones
    // (map mode) the pin picks the zone by itself and must fall inside one; with manual zones it is optional.
    const pinSheet = $('[data-pin-sheet]');
    const pinKey = `egmenu.pin.${M.tenant}`;
    let pin = null;
    try { pin = JSON.parse(session.get(pinKey) || 'null'); } catch { pin = null; }
    const zoneOf = (lat, lng) => {
        const hits = (O?.zones || []).filter((z) => z.polygon && inside(z.polygon, lat, lng));
        return hits.find((z) => z.parent) || hits[0] || null; // a drawn street inside a drawn area wins over the area
    };
    const zonesDrawn = () => M.zoneMode === 'map' && (O?.zones || []).some((z) => z.polygon);
    const pinOutside = () => !!pin && zonesDrawn() && !zoneOf(pin.lat, pin.lng);
    const pinOpenLabel = $('[data-pin-open-text]')?.textContent || '';
    function renderPin() {
        const box = $('[data-pin-box]');
        if (!box) return;
        $('[data-pin-done]', box).classList.toggle('hidden', !pin);
        $('[data-pin-done]', box).classList.toggle('flex', !!pin);
        $('[data-pin-open-text]', box).textContent = pin ? T.pinChange : pinOpenLabel;
        $('[data-pin-outside]', box).classList.toggle('hidden', !pinOutside());
    }
    function setPin(point) {
        pin = point;
        session.set(pinKey, point ? JSON.stringify(point) : '');
        renderPin();
        renderSendHelp();
    }
    let leafletLoading = null;
    function loadLeaflet() {
        if (window.L) return Promise.resolve();
        if (!M.leaflet) return Promise.reject(new Error('no leaflet'));
        leafletLoading ??= new Promise((resolve, reject) => {
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = M.leaflet.css;
            css.integrity = M.leaflet.cssIntegrity;
            css.crossOrigin = '';
            document.head.appendChild(css);
            const js = document.createElement('script');
            js.src = M.leaflet.js;
            js.integrity = M.leaflet.jsIntegrity;
            js.crossOrigin = '';
            js.onload = () => resolve();
            js.onerror = () => { leafletLoading = null; reject(new Error('leaflet failed')); };
            document.head.appendChild(js);
        });
        return leafletLoading;
    }
    let pinMap = null;
    let pinMarker = null;
    function pinCenter() {
        if (pin) return [pin.lat, pin.lng];
        const zone = currentZone();
        const poly = zone?.polygon || (O?.zones || []).find((z) => z.polygon)?.polygon;
        if (poly) return [poly.reduce((s, p) => s + p[0], 0) / poly.length, poly.reduce((s, p) => s + p[1], 0) / poly.length];
        return M.branchPoint || [30.0444, 31.2357];
    }
    function pinMoved() {
        const { lat, lng } = pinMarker.getLatLng();
        const zone = zonesDrawn() ? zoneOf(lat, lng) : null;
        $('[data-pin-zone]', pinSheet).classList.toggle('hidden', !zone);
        if (zone) $('[data-pin-zone]', pinSheet).textContent = T.pinInZone.replace('__Z__', zone.name);
        $('[data-pin-sheet-outside]', pinSheet).classList.toggle('hidden', !(zonesDrawn() && !zone));
    }
    async function openPin() {
        if (!pinSheet) return;
        pinSheet.showModal();
        $('[data-pin-loading]', pinSheet).classList.remove('hidden');
        try {
            await loadLeaflet();
        } catch {
            $('[data-pin-loading]', pinSheet).textContent = T.pinMapFailed;
            return;
        }
        $('[data-pin-loading]', pinSheet).classList.add('hidden');
        const center = pinCenter();
        if (!pinMap) {
            pinMap = L.map($('[data-pin-map]', pinSheet), { zoomControl: true }).setView(center, pin ? 17 : 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(pinMap);
            // the delivery areas as faint outlines, so the guest sees where the pin may go
            (O?.zones || []).filter((z) => z.polygon).forEach((z) => L.polygon(z.polygon, { color: '#0A2540', weight: 1.5, fillOpacity: 0.06 }).addTo(pinMap));
            pinMarker = L.marker(center, { draggable: true, autoPan: true }).addTo(pinMap);
            pinMarker.on('dragend', pinMoved);
            pinMap.on('click', (e) => { pinMarker.setLatLng(e.latlng); pinMoved(); });
        } else {
            pinMarker.setLatLng(center);
            pinMap.setView(center, pin ? 17 : 14);
        }
        setTimeout(() => pinMap.invalidateSize(), 50); // the dialog was hidden when the map measured itself
        pinMoved();
    }
    $('[data-pin-open]')?.addEventListener('click', openPin);
    $('[data-pin-clear]')?.addEventListener('click', () => setPin(null));
    pinSheet?.addEventListener('click', (e) => {
        if (e.target.closest('[data-pin-locate]')) {
            const btn = e.target.closest('[data-pin-locate]');
            if (!navigator.geolocation || !pinMarker) return;
            btn.disabled = true;
            navigator.geolocation.getCurrentPosition((pos) => {
                btn.disabled = false;
                const here = [pos.coords.latitude, pos.coords.longitude];
                pinMarker.setLatLng(here);
                pinMap.setView(here, 17);
                pinMoved();
            }, () => {
                btn.disabled = false;
                alert(T.pinNoLocation);
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
        } else if (e.target.closest('[data-pin-confirm]')) {
            if (!pinMarker) return;
            const { lat, lng } = pinMarker.getLatLng();
            const zone = zonesDrawn() ? zoneOf(lat, lng) : null;
            if (zonesDrawn() && !zone) return pinMoved(); // outside every drawn area: the warning stays up
            setPin({ lat: Math.round(lat * 1e6) / 1e6, lng: Math.round(lng * 1e6) / 1e6 });
            if (zone) pickZone(zone.id);
            pinSheet.close();
        }
    });
    renderPin();

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
    // the first-open sheet opened before the first paint (intro-head): show the remembered way picked in it
    if (firstSheet && root.classList.contains('choice-open')) openFirstSheet(false);
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
    // delivery picked together with another branch in the first-open sheet: that branch's areas come next
    if (session.get(zoneAskKey) === '1') {
        session.set(zoneAskKey, '');
        if (mode === 'online' && otype === 'delivery' && !currentZone()) openZones();
    }

    // ---- send order (a scanned table, or online pickup / delivery) ----
    // a coupon from a campaign link (?coupon=CODE) is kept for this visit and filled in the cart
    try {
        const fromLink = new URLSearchParams(location.search).get('coupon');
        if (fromLink) sessionStorage.setItem('egm-coupon', fromLink.slice(0, 30));
        const couponField = $('[data-coupon]');
        if (couponField && !couponField.value) couponField.value = sessionStorage.getItem('egm-coupon') || '';
        // a code already filled in (from a campaign link) keeps the optional extras open so the guest sees it
        if (couponField?.value) couponField.closest('details')?.setAttribute('open', '');
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
            if (otype === 'delivery' && pinOutside()) return fail(T.pinOutside);
        }
        sendBtn.disabled = true;
        sendBtn.dataset.busy = '1';
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
                        ...(otype === 'delivery' && pin ? { lat: pin.lat, lng: pin.lng } : {}),
                    } : {}),
                }),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) {
                const first = body.errors ? Object.values(body.errors)[0][0] : null;
                throw new Error(body.message || first || res.status);
            }
            rememberLastOrder();
            cart = [];
            save();
            window.location.href = body.url;
        } catch (err) {
            errorBox.textContent = err.message;
            errorBox.classList.remove('hidden');
            // a refused coupon: show the field it is about
            if ($('[data-coupon]')?.value) $('[data-coupon]').closest('details')?.setAttribute('open', '');
            delete sendBtn.dataset.busy;
            sendBtn.disabled = false;
            sendBtn.textContent = label;
            renderSendHelp();
        }
    });

    // ---- "اطلب تاني": the last order placed from this phone, and ?reorder={token} from the order page ----
    const lastKey = `egmenu.last.${M.tenant}`;
    function rememberLastOrder() {
        try {
            localStorage.setItem(lastKey, JSON.stringify({ at: Date.now(), lines: cart.map((l) => ({ item_id: l.item_id, option_ids: l.option_ids, qty: l.qty, notes: l.notes })) }));
        } catch {}
    }
    // puts lines back in the cart with today's prices; an item that left the menu, is sold out or lost an option is skipped
    function putBack(lines, skippedBefore = 0) {
        let added = 0;
        let skipped = skippedBefore;
        lines.forEach((l) => {
            const item = M.items[l.item_id];
            const ids = item ? item.groups.flatMap((g) => g.options.filter((o) => !o.soldOut).map((o) => o.id)) : [];
            const optionIds = (l.option_ids || []).map(Number);
            const requiredOk = item && item.groups.every((g) => !g.required || g.options.some((o) => optionIds.includes(o.id)));
            if (!item || item.soldOut || !optionIds.every((id) => ids.includes(id)) || !requiredOk) { skipped++; return; }
            const count = Math.min(30, Math.max(1, Number(l.qty) || 1));
            addLine(item, optionIds, count, String(l.notes || '').slice(0, 200));
            added += count;
        });
        if (added) bumpCount();
        const parts = [added ? T.reordered.replace('__N__', added) : T.reorderNone];
        if (added && skipped) parts.push(T.reorderSkipped.replace('__N__', skipped));
        toast(parts.join(' · '));
        return added;
    }
    const lastBox = $('[data-last-order]');
    let lastOrder = null;
    try { lastOrder = JSON.parse(localStorage.getItem(lastKey) || 'null'); } catch {}
    if (lastBox && lastOrder?.lines?.length && Date.now() - (lastOrder.at || 0) < 60 * 86400000) {
        const names = lastOrder.lines.map((l) => (M.items[l.item_id] ? `${l.qty}× ${M.items[l.item_id].name}` : null)).filter(Boolean);
        if (names.length) {
            $('[data-last-order-lines]', lastBox).textContent = names.join(document.documentElement.lang === 'ar' ? '، ' : ', ');
            lastBox.classList.remove('hidden');
            lastBox.dataset.ready = '1';
            $('[data-last-order-again]', lastBox).addEventListener('click', () => {
                if (!quickOn()) return;
                if (putBack(lastOrder.lines)) { renderCart(); cartSheet.showModal(); }
            });
        }
    }
    if (M.reorder && quickOn()) {
        // the order page's "order again": once, then the address goes back to the plain menu
        const added = putBack(M.reorder.lines, M.reorder.skipped);
        try {
            const url = new URL(location.href);
            url.searchParams.delete('reorder');
            history.replaceState(history.state, '', url.toString());
        } catch {}
        if (added) { renderCart(); cartSheet.showModal(); }
    }

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
            const price = H.prices ? `<span class="m-muted shrink-0 text-sm font-bold">${fmt(unitPrice(item, l.option_ids) * l.qty)}</span>` : '';
            return `<li class="flex items-center gap-3 py-3">
                <span class="m-qty m-primary shrink-0" style="min-width:2.75rem;height:2.75rem;font-size:1.15rem" dir="ltr">${l.qty}×</span>
                <div class="min-w-0 flex-1">
                    <div class="text-lg font-extrabold leading-snug">${esc(item.name)}</div>
                    ${opts ? `<div class="m-muted text-sm font-semibold">${esc(opts)}</div>` : ''}
                    ${l.notes ? `<div class="m-primary-text text-sm font-bold">✎ ${esc(l.notes)}</div>` : ''}
                </div>
                ${price}
            </li>`;
        }).join('');
        const t = totals();
        const pieces = cart.reduce((n, l) => n + l.qty, 0);
        $('[data-waiter-count]', waiterView).textContent = T.waiterCount.replace('__L__', cart.length).replace('__Q__', pieces);
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
    const pickedBadges = new Set();
    function applyFilter() {
        const q = search ? search.value.trim().toLowerCase() : '';
        let any = false;
        const nav = $('[data-cat-nav]');
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
            // live count on the category chip / circle
            const link = nav?.querySelector(`[data-cat-link="${sec.id}"]`);
            if (link) {
                link.dataset.matches = String(shown);
                const count = $('[data-cat-count]', link);
                if (count) count.textContent = String(shown);
            }
        });
        const filtering = !!q || pickedBadges.size > 0;
        nav?.toggleAttribute('data-searching', filtering);
        // while searching, the rows above the categories step aside so the results come first
        document.querySelectorAll('[data-top-row], [data-banners]').forEach((el) => el.classList.toggle('hidden', filtering));
        lastBoxHidden(filtering);
        renderFavs();
        $('[data-no-results]').classList.toggle('hidden', any);
    }
    function lastBoxHidden(filtering) {
        const box = $('[data-last-order]');
        if (box && box.dataset.ready) box.classList.toggle('hidden', filtering);
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
                if (a.hasAttribute('data-cat-circle')) {
                    // circles: the active one is ringed in the accent
                    a.setAttribute('aria-current', on ? 'true' : 'false');
                } else {
                    a.classList.toggle('m-primary', on);
                    a.classList.toggle('m-surface-2', !on);
                }
                if (on) a.scrollIntoView({ inline: 'center', block: 'nearest' });
            });
        });
    }, { rootMargin: '-40% 0px -55% 0px' });
    document.querySelectorAll('[data-category], [data-favs]').forEach((s) => observer.observe(s));

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

    // ---- branch part of the first-open sheet (2+ branches, no table): nearest first (location asked on a tap, or
    // used at once when already allowed), current, last visit, open now, map ----
    const branchPart = $('[data-branch-sheet]');
    const B = M.branches || [];
    if (branchPart && B.length > 1) {
        let mem = {};
        try { mem = JSON.parse(localStorage.getItem(branchMemKey) || '{}') || {}; } catch {}
        const lastVisit = mem.current && mem.current !== M.branch ? mem.current : (mem.previous ?? null);
        try { localStorage.setItem(branchMemKey, JSON.stringify({ current: M.branch, previous: lastVisit })); } catch {}
        let distances = null;
        const nearBtn = $('[data-branch-near]', branchPart);
        const note = $('[data-branch-note]', branchPart);
        const tag = (text, strong) => `<span class="rounded-full px-2 py-0.5 text-[11px] font-extrabold ${strong ? 'm-primary' : 'm-surface-2 m-muted'}">${esc(text)}</span>`;
        const km = (d) => T.km.replace('__N__', d < 10 ? d.toFixed(1) : Math.round(d));
        const search = $('[data-branch-search]', branchPart);
        const fold = (s) => String(s || '').toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي');
        renderBranchPart = () => {
            const current = B.find((b) => b.id === M.branch)?.slug;
            const chosen = pickedBranch || current;
            const q = fold(search?.value.trim());
            // nearest first once the location is known; branches without coordinates after the ones with
            const rows = B.filter((b) => !q || fold(`${b.name} ${b.address || ''}`).includes(q));
            if (distances) rows.sort((a, b) => (distances[a.id] ?? 1e9) - (distances[b.id] ?? 1e9));
            const nearest = distances ? rows.find((r) => distances[r.id] != null)?.id : null;
            $('[data-branch-list]', branchPart).innerHTML = rows.map((b) => {
                const on = b.slug === chosen;
                const tags = [
                    b.id === nearest ? tag(T.nearest, true) : '',
                    b.id === lastVisit && b.id !== nearest ? tag(T.lastVisit, false) : '',
                ].join('');
                const open = b.open === null ? '' : `<span class="text-[11px] font-bold ${b.open ? 'text-emerald-700' : 'text-red-600'}">● ${esc(b.open ? T.openNow : T.closedNow)}</span>`;
                const dist = distances && distances[b.id] != null ? `<span class="m-primary-text text-xs font-extrabold" dir="ltr" data-branch-km>${esc(km(distances[b.id]))}</span>` : '';
                return `<a href="?branch=${encodeURIComponent(b.slug)}" class="m-branch-row ${on ? 'is-on' : ''}" data-branch-row="${b.id}" data-branch-pick="${esc(b.slug)}" role="radio" aria-checked="${on ? 'true' : 'false'}">
                    <span class="m-branch-dot" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5"><b class="truncate">${esc(b.name)}</b>${tags}</span>
                        <span class="flex items-center gap-2">${b.address ? `<span class="m-muted min-w-0 truncate text-xs">${esc(b.address)}</span>` : ''}${open}</span>
                    </span>
                    ${dist}
                </a>`;
            }).join('') || `<p class="m-muted py-3 text-center text-sm">${esc(T.noBranchMatch)}</p>`;
        };
        search?.addEventListener('input', () => renderBranchPart());
        const locate = (silent) => {
            if (!navigator.geolocation) { if (!silent) { note.textContent = T.noLocation; note.classList.remove('hidden'); } return; }
            if (!silent) { note.textContent = T.locating; note.classList.remove('hidden'); }
            if (nearBtn) nearBtn.disabled = true;
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
                nearBtn?.classList.add('hidden');
                // a tap on "nearest to me", or a first visit with no branch chosen: the nearest one is picked for the
                // guest (they can change it)
                if (!silent || (!M.branchChosen && !pickedBranch)) {
                    const near = [...B].filter((b) => distances[b.id] != null).sort((a, b) => distances[a.id] - distances[b.id])[0];
                    if (near) pickedBranch = near.slug;
                }
                renderBranchPart();
            }, () => {
                if (nearBtn) nearBtn.disabled = false;
                if (!silent) note.textContent = T.noLocation;
            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
        };
        nearBtn?.addEventListener('click', () => locate(false));
        // location already allowed for this menu: nearest first without asking (the browser shows no prompt)
        if (nearBtn && navigator.permissions?.query) {
            navigator.permissions.query({ name: 'geolocation' }).then((state) => { if (state.state === 'granted') locate(true); }).catch(() => {});
        }
        renderBranchPart();
    }

    // ---- popup banner: never over the intro, the first-open sheet or another open sheet; once per visit unless the
    // owner chose a day / only once / every time; counted (views & taps only) ----
    const P = M.popup;
    const popupBox = $('[data-popup]');
    if (P && popupBox) {
        const key = `egmenu.popup.${M.tenant}.${P.id}`;
        let store = null; // reading window.sessionStorage itself throws when the browser blocks site data
        try { store = P.frequency === 'session' ? sessionStorage : localStorage; } catch {}
        let seen = null;
        try { seen = store?.getItem(key); } catch {}
        const due = P.frequency === 'always' || !seen || (P.frequency === 'day' && seen !== new Date().toDateString());
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
        const count = (event) => fetch(P.eventUrl.replace('__E__', event), { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': csrf() } }).catch(() => {});
        const covered = () => root.classList.contains('intro-open') || root.classList.contains('choice-open') || !!document.querySelector('dialog[open]');
        let pending = false;
        const show = () => {
            pending = false;
            if (covered()) { wait(); return; }
            const img = $('[data-popup-image]', popupBox);
            img.classList.toggle('hidden', !P.image);
            if (P.image) img.src = P.image;
            const action = $('[data-popup-action]', popupBox);
            if (action) {
                action.onclick = () => {
                    count('click');
                    popupBox.close();
                    if (P.item) openItem(P.item);
                    else if (P.category) document.getElementById(`cat-${P.category}`)?.scrollIntoView({ behavior: reducedMotionNow() ? 'auto' : 'smooth' });
                    else if (P.link) window.open(P.link, '_blank', 'noopener');
                };
            }
            popupBox.showModal();
            try { store?.setItem(key, new Date().toDateString()); } catch {}
            count('view');
        };
        // after the intro and the first-open sheet are gone, a short breath, then the popup
        function wait() {
            const watch = new MutationObserver(() => go());
            // a sheet the guest opened (an item, the cart) closes without touching the root classes
            const onClose = () => go();
            function go() {
                if (pending || covered()) return;
                pending = true;
                watch.disconnect();
                document.removeEventListener('close', onClose, true);
                setTimeout(show, 900);
            }
            watch.observe(root, { attributes: true, attributeFilter: ['class'] });
            document.addEventListener('close', onClose, true);
            go();
        }
        if (due) wait();
    }

    syncQuick();
    renderBar();
    syncFavButtons();
    renderFavs();
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
