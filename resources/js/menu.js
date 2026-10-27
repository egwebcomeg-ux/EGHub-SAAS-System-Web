// Public menu: item sheet with sizes/extras, cart kept per restaurant in localStorage, ordering from the table or
// online, the hall list for the waiter, bill split, the order-type screen and the intro splash.
// Prices here are only for display; the server recomputes every amount when the order is placed.

import { quoteAt, zoneAt } from './delivery-geo';

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
    // delivery priced by the guest's pin (drawn zones or distance rings): the map comes first, the list of areas is
    // the fallback ("deliveryBy" = map | list). The pin and its quote are the preview only; the server prices again.
    const mapFirst = !!O?.pricing?.map;
    const byKey = `egmenu.deliveryBy.${M.tenant}`;
    let deliveryBy = mapFirst && session.get(byKey) === 'list' && (O?.zones || []).length ? 'list' : 'map';
    const byMap = () => mapFirst && deliveryBy === 'map';
    const pinKey = `egmenu.pin.${M.tenant}`;
    let pin = null;
    try { pin = JSON.parse(session.get(pinKey) || 'null'); } catch { pin = null; }
    // the place found for the confirmed pin («شارع البحر، قسم أول طنطا»), asked from this host (M.geo), one call per ~25 m
    const GEO = M.geo;
    const placeKey = `egmenu.place.${M.tenant}`;
    let place = null;
    try { place = JSON.parse(session.get(placeKey) || 'null'); } catch { place = null; }
    const placeCache = new Map();
    const cell = (lat, lng) => `${Math.round(lat / 0.00025)},${Math.round(lng / 0.00025)}`;
    const pinQuote = (lat, lng) => quoteAt(O?.pricing, O?.zones, lat, lng, (d) => T.distanceName.replace('__K__', d.toFixed(1)));
    const hallCart = H.mode === 'order' ? 'table' : H.mode === 'list' ? 'list' : 'none';
    let mode = M.mode;
    let otype = O ? (O.takeaway ? 'takeaway' : 'delivery') : null;
    const inHall = () => mode === 'table' || mode === 'list';
    const hidePrices = () => inHall() && !H.prices;
    // inside the place the owner may show item prices but no bill total; takeaway and delivery always show it
    const hideTotal = () => hidePrices() || (H.hideTotal && inHall());
    const wayKey = `egmenu.way.${M.tenant}`;
    // 2+ ways on the general link: nothing is picked for the guest. Until they choose (the sheet, the header chip or
    // the cart switch) the cart shows the switch alone and nothing can be sent
    let wayPicked = !!H.table || (M.ways || []).length < 2;
    // the cart's coupon preview (see quoteCoupon) and its re-check timer
    let couponQuote = null;
    let couponTimer = null;

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
        $('[data-cart-total]').textContent = hideTotal() ? '' : fmt(total);
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

    // ---- the choices of the item sheet ----
    // Required groups come first, open, with a "required" chip. Optional groups fold into one row each (what is picked
    // shows on the end side) and open on a tap; an optional single choice (e.g. "make it a combo") starts on "without".
    const chevron = '<svg viewBox="0 0 24 24" class="m-opt-chev" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

    // the option a single-choice group starts on: the owner's default, else (a required one) the cheapest in stock
    function startOption(g) {
        if (g.type !== 'single') return null;
        const inStock = g.options.filter((o) => !o.soldOut);
        const preset = inStock.find((o) => o.default);
        if (preset) return preset.id;
        if (!g.required || !inStock.length) return null;
        return inStock.reduce((cheapest, o) => (o.price < cheapest.price ? o : cheapest)).id;
    }

    function renderGroups(item) {
        const ordered = [...item.groups].sort((a, b) => Number(b.required) - Number(a.required));
        return ordered.map((g) => {
            const start = startOption(g);
            const kind = g.type === 'single' ? 'radio' : 'checkbox';
            const rows = g.options.map((o) => `
                <label class="m-opt-row">
                    <input type="${kind}" name="g${g.id}" value="${o.id}" data-option data-group="${g.id}" ${o.soldOut ? 'disabled' : ''} ${o.id === start ? 'checked' : ''}>
                    <span class="m-opt-name">${esc(o.name)}</span>
                    ${o.price ? `<span class="m-opt-price">+${esc(fmt(o.price))}</span>` : ''}
                </label>`).join('');
            const limit = g.type === 'single' ? T.chooseOne : g.max ? T.chooseUpTo.replace('__N__', g.max) : '';
            if (g.required) {
                return `<div class="m-opt-group" role="group" aria-labelledby="sheet-gt${g.id}" data-group-box="${g.id}">
                    <div class="m-opt-head">
                        <span class="min-w-0"><span id="sheet-gt${g.id}" class="m-opt-title">${esc(g.name)}</span>${limit ? `<span class="m-opt-sub">${esc(limit)}</span>` : ''}</span>
                        <span class="m-req">${esc(T.requiredChip)}</span>
                    </div>
                    <p class="m-opt-alert text-red-600 hidden" role="alert" data-group-alert></p>
                    <div class="m-opt-list">${rows}</div>
                </div>`;
            }
            const prices = g.options.filter((o) => !o.soldOut && o.price > 0).map((o) => o.price);
            const sub = [prices.length ? T.fromPrice.replace('__A__', `+${fmt(Math.min(...prices))}`) : T.optional, g.type === 'multiple' ? limit : '']
                .filter(Boolean).join(' · ');
            const none = g.type === 'single'
                ? `<label class="m-opt-row"><input type="radio" name="g${g.id}" value="" data-option-none data-group="${g.id}" ${start ? '' : 'checked'}><span class="m-opt-name">${esc(T.without)}</span></label>`
                : '';
            return `<div class="m-opt-group" data-group-box="${g.id}">
                <button type="button" class="m-opt-head" aria-expanded="false" aria-controls="sheet-gp${g.id}" data-group-toggle>
                    <span class="min-w-0"><span id="sheet-gt${g.id}" class="m-opt-title">${esc(g.name)}</span><span class="m-opt-sub">${esc(sub)}</span></span>
                    <span class="m-opt-sum" data-group-sum><span></span>${chevron}</span>
                </button>
                <div id="sheet-gp${g.id}" class="m-opt-list" role="group" aria-labelledby="sheet-gt${g.id}" hidden>${none}${rows}</div>
            </div>`;
        }).join('');
    }

    // the end side of a folded group: what is picked ("without", the one option, or "2 chosen")
    function syncGroupSummary(g) {
        const sum = sheet.querySelector(`[data-group-box="${g.id}"] [data-group-sum]`);
        if (!sum) return;
        const picked = g.options.filter((o) => sheet.querySelector(`[data-group="${g.id}"][value="${o.id}"]`)?.checked);
        sum.firstElementChild.textContent = !picked.length
            ? (g.type === 'single' ? T.without : '')
            : (g.type === 'single' ? picked[0].name : T.nChosen.replace('__N__', picked.length));
        sum.classList.toggle('is-set', picked.length > 0);
    }

    // a required group left empty: scroll to it, mark it and say what is missing (instead of a silent fail)
    function flagMissing(g) {
        const box = sheet.querySelector(`[data-group-box="${g.id}"]`);
        if (!box) {
            alert(T.pickRequired.replace('__G__', g.name));
            return;
        }
        const note = $('[data-group-alert]', box);
        if (note) {
            note.textContent = T.pickRequired.replace('__G__', g.name);
            note.classList.remove('hidden');
        }
        box.classList.remove('m-opt-missing');
        void box.offsetWidth; // restart the nudge
        box.classList.add('m-opt-missing');
        const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
        box.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'center' });
        box.querySelector('input:not(:disabled)')?.focus({ preventScroll: true });
    }

    // the kitchen note: folded behind one link, opened on a tap
    const notesToggle = $('[data-sheet-notes-toggle]', sheet);
    const notesField = $('#sheet-notes-field', sheet);
    function resetNotes() {
        $('[data-sheet-notes]', sheet).value = '';
        notesField.hidden = true;
        notesToggle.setAttribute('aria-expanded', 'false');
    }
    notesToggle.addEventListener('click', () => {
        const open = notesField.hidden;
        notesField.hidden = !open;
        notesToggle.setAttribute('aria-expanded', String(open));
        if (open) $('[data-sheet-notes]', sheet).focus();
    });

    // a long description: three lines, then "read more"
    const descEl = $('[data-sheet-desc]', sheet);
    const descMore = $('[data-sheet-more]', sheet);
    function syncDescMore() {
        descEl.classList.add('line-clamp-3');
        descMore.setAttribute('aria-expanded', 'false');
        descMore.textContent = T.readMore;
        descMore.classList.toggle('hidden', descEl.scrollHeight <= descEl.clientHeight + 1);
    }
    descMore.addEventListener('click', () => {
        const open = descEl.classList.toggle('line-clamp-3') === false;
        descMore.setAttribute('aria-expanded', String(open));
        descMore.textContent = open ? T.showLess : T.readMore;
    });

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
        resetNotes();
        $('[data-sheet-groups]', sheet).innerHTML = renderGroups(current);
        current.groups.forEach((g) => syncGroupSummary(g));
        // menu only in the hall (and no takeaway / delivery): nothing to add
        const soldOut = current.soldOut || mode === 'none';
        $('[data-sheet-soldout]', sheet).classList.toggle('hidden', !current.soldOut);
        $('[data-add-to-cart]', sheet).classList.toggle('hidden', soldOut);
        // nothing to add: no quantity, notes or add bar either
        $('[data-sheet-actions]', sheet).classList.toggle('hidden', soldOut);
        $('[data-sheet-notes-box]', sheet).classList.toggle('hidden', soldOut);
        refreshSheetTotal();
        if (!sheet.open) sheet.showModal();
        $('[data-item-form]', sheet).scrollTop = 0;
        syncDescMore();
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
        const box = e.target.closest('[data-group-box]');
        if (box && current) {
            const g = current.groups.find((x) => x.id === Number(box.dataset.groupBox));
            if (g) syncGroupSummary(g);
            box.classList.remove('m-opt-missing');
            $('[data-group-alert]', box)?.classList.add('hidden');
        }
        refreshSheetTotal();
    });
    sheet.addEventListener('click', (e) => {
        const step = e.target.closest('[data-qty]');
        if (step) {
            qty = Math.min(30, Math.max(1, qty + Number(step.dataset.qty)));
            refreshSheetTotal();
        }
        const fold = e.target.closest('[data-group-toggle]');
        if (fold) {
            const open = fold.getAttribute('aria-expanded') !== 'true';
            fold.setAttribute('aria-expanded', String(open));
            sheet.querySelector(`#${fold.getAttribute('aria-controls')}`).hidden = !open;
        }
    });
    $('[data-add-to-cart]', sheet).addEventListener('click', () => {
        const ids = chosenIds();
        const missing = current.groups.find((g) => g.required && !g.options.some((o) => ids.includes(o.id)));
        if (missing) {
            flagMissing(missing);
            return;
        }
        const item = current;
        addLine(item, ids, qty, $('[data-sheet-notes]', sheet).value.trim());
        sheet.close();
        // back on the menu: the cart bar counts up and a short "added" note shows
        bumpCount();
        toast(T.added.replace('__N__', item.name));
        showPairings(item);
    });

    // the one way a line enters the cart (item sheet, "goes well with", quick "+"): same item, options and notes add up
    function addLine(item, optionIds, count, notes = '') {
        const same = cart.find((l) => l.item_id === item.id && l.notes === notes && l.option_ids.join() === optionIds.join());
        if (same) same.qty = Math.min(30, same.qty + count);
        else cart.push({ item_id: item.id, option_ids: optionIds, qty: count, notes });
        track('AddToCart', { content_ids: [String(item.id)], content_name: item.name, content_type: 'product', value: unitPrice(item, optionIds) * count, num_items: count });
        save();
        renderBar();
        askWayAfterAdd();
    }

    // the first item in the cart and no way chosen yet (2+ ways, setting order_type_prompt): the "how will you order?"
    // sheet opens once, after any open sheet (the item, "goes well with") is closed; delivery then asks for the area
    const wayAskedKey = `egmenu.wayAsked.${M.tenant}`;
    function askWayAfterAdd() {
        if (!M.askWayOnAdd || wayPicked || session.get(wayAskedKey)) return;
        session.set(wayAskedKey, '1');
        const open = () => {
            if (wayPicked) return;
            if (document.querySelector('dialog[open]')) {
                // the guest went to the cart instead: the switch there asks, no sheet after it
                document.addEventListener('close', (e) => { if (!e.target.matches?.('[data-cart-sheet]')) setTimeout(open, 150); }, { capture: true, once: true });
                return;
            }
            openFirstSheet(true);
        };
        setTimeout(open, 250);
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
        // the coupon the server priced for exactly these lines and this code (couponFor)
        const coupon = couponFor();
        const couponOff = coupon ? Math.min(coupon.discount, subtotal - goal.off) : 0;
        const service = inHall() ? (subtotal - goal.off - couponOff) * M.service / 100 : 0;
        const tax = mode === 'none' ? 0 : (subtotal - goal.off - couponOff + service) * M.tax / 100;
        const zone = currentZone();
        const fee = zone ? zone.fee : 0;
        return { subtotal, goal, coupon, service, tax, fee, zone, total: subtotal - goal.off - couponOff + service + tax + fee };
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
    $('[data-coupon]')?.addEventListener('input', () => { if (G || couponQuote) renderCart(); });

    // ---- coupon preview before sending: the server prices the code for these lines (Menu\OrderController::coupon);
    // the bill shows its line and the new total, and asks again when the lines change ----
    const couponField = () => $('[data-coupon]');
    const linesKey = () => JSON.stringify(cart.map((l) => [l.item_id, l.qty, l.option_ids]));
    const couponCode = () => (couponField()?.value || '').trim().toUpperCase();
    function couponFor() {
        if (!couponQuote || mode === 'list' || mode === 'none') return null;
        return couponQuote.typed === couponCode() && couponQuote.key === linesKey() ? couponQuote : null;
    }
    function couponStatus(text, ok) {
        const box = $('[data-coupon-status]');
        if (!box) return;
        box.textContent = text || '';
        box.classList.toggle('hidden', !text);
        box.classList.toggle('text-emerald-700', !!ok);
        box.classList.toggle('text-red-600', ok === false);
    }
    async function quoteCoupon() {
        const code = couponCode();
        if (!couponField() || !M.couponUrl) return;
        if (!code || !cart.length) { couponQuote = null; couponStatus(''); renderCart(); return; }
        couponStatus(T.couponChecking);
        const key = linesKey();
        try {
            const res = await fetch(M.couponUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({
                    coupon: code, customer_phone: $('[data-customer-phone]')?.value.trim() || null, branch_id: O?.branch ?? null,
                    items: cart.map((l) => ({ item_id: l.item_id, qty: l.qty, option_ids: l.option_ids })),
                }),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) {
                couponQuote = { typed: code, key, discount: 0, failed: true };
                couponStatus(body.message || (body.errors ? Object.values(body.errors)[0][0] : ''), false);
            } else {
                couponQuote = { typed: code, key, discount: Number(body.discount) || 0, code: body.code };
                couponStatus(T.couponApplied.replace('__A__', fmt(couponQuote.discount)), true);
            }
        } catch {
            couponQuote = null;
            couponStatus('');
        }
        renderCart();
    }
    $('[data-coupon-apply]')?.addEventListener('click', quoteCoupon);
    couponField()?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); quoteCoupon(); } });
    couponField()?.addEventListener('input', () => { if (couponQuote && couponQuote.typed !== couponCode()) { couponQuote = null; couponStatus(''); } });
    // the lines changed after a code was applied: price it again (a refused code waits for another tap)
    function requoteCoupon() {
        if (!couponQuote || couponQuote.failed || couponQuote.typed !== couponCode() || couponQuote.key === linesKey()) return;
        clearTimeout(couponTimer);
        couponTimer = setTimeout(quoteCoupon, 400);
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
        summary.classList.toggle('hidden', hideTotal() || !cart.length);
        $('[data-bill]', cartSheet)?.classList.toggle('hidden', hideTotal() || !cart.length);
        // savings of the running offers: the price before the offer is real data of the menu (MenuCatalog)
        const saved = cart.reduce((s, l) => { const it = M.items[l.item_id]; return s + (it.offerFrom ? (it.offerFrom - it.price) * l.qty : 0); }, 0);
        const couponOff = t.coupon ? Math.min(t.coupon.discount, t.subtotal - t.goal.off) : 0;
        const breakdown = t.service || t.tax || t.fee || t.goal.off || couponOff;
        requoteCoupon();
        summary.innerHTML = (breakdown ? row(T.subtotal, t.subtotal) + (t.goal.off ? row(`🎁 ${G.rewardName}`, -t.goal.off) : '') + (couponOff ? row(`🎟 ${T.couponLine.replace('__C__', t.coupon.code)}`, -couponOff) : '') + (t.service ? row(T.service, t.service) : '') + (t.tax ? row(T.tax, t.tax) : '') + (t.fee ? row(T.deliveryFee, t.fee) : '') : '')
            + (breakdown ? `<div class="m-border mt-1 border-t pt-1.5">${row(T.total, t.total, true)}</div>` : row(T.total, t.total, true))
            + (saved > 0.005 ? `<div class="text-xs font-bold text-emerald-700" data-offers-saved>🏷 ${esc(T.offersSaved.replace('__A__', fmt(saved)))}</div>` : '')
            + (t.zone && t.zone.min > t.subtotal ? `<div class="mt-1 text-xs font-semibold text-amber-700">${esc(T.minOrder.replace('__A__', fmt(t.zone.min)))}</div>` : '');
        // the expected time, as the tracking page counts it: preparation + the zone's minutes for delivery, else the
        // kitchen's preparation time (online orders)
        const eta = $('[data-cart-eta]', cartSheet);
        if (eta) {
            const minutes = wayPicked && mode === 'online' ?(otype === 'delivery' ? (t.zone?.eta || null) : O?.prep) : null;
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
        if (otype === 'delivery' && !t.zone) list.push(byMap() ? T.missingPin : T.missingZone);
        if (otype === 'delivery' && !val('[data-addr="street"]')) list.push(T.missingStreet);
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
        if (e.target.closest('[data-customer-name], [data-customer-phone], [data-addr]')) renderSendHelp();
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
        if (byMap()) return pin ? pinQuote(pin.lat, pin.lng) : null;
        return O.zones.find((z) => String(z.id) === $('[data-zone]')?.value) || null;
    }
    // the delivery step: the map when the pin prices it, else the list of areas
    function openDelivery() {
        if (byMap()) openPin();
        else openZones();
    }
    function setDeliveryBy(by) {
        deliveryBy = by;
        session.set(byKey, by);
    }
    const show = (sel, on) => document.querySelectorAll(sel).forEach((el) => el.classList.toggle('hidden', !on));
    function renderMode() {
        const way = !wayPicked ? null : inHall() ? 'dine' : mode === 'online' ? otype : null;
        document.querySelectorAll('[data-way-switch] [data-way], [data-online] [data-otype]').forEach((b) => {
            const on = (b.dataset.way || b.dataset.otype) === way;
            b.classList.toggle('m-way-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        show('[data-way-ask]', !wayPicked);
        show('[data-delivery-fields]', wayPicked && mode === 'online' && otype === 'delivery');
        show('[data-online]', wayPicked && mode === 'online');
        show('[data-order-fields]', wayPicked && (mode === 'table' || mode === 'online'));
        show('[data-hall-notice], [data-scan-hint], [data-show-waiter], [data-hall-tools] [data-hall-call]', wayPicked && mode === 'list');
        show('[data-hall-tools]', wayPicked && inHall());
        show('[data-open-split]', inHall() && H.prices);
        renderLocation();
        const title = $('[data-cart-title]');
        if (title) title.textContent = mode === 'list' ? T.myOrder : T.yourOrder;
        // the order-type chip in the header: "🛵 Delivery · Maadi ▾" (nothing chosen yet: "How will you order? ▾")
        const chip = $('[data-way-chip-label]');
        if (chip && !wayPicked) chip.textContent = T.wayAsk;
        else if (chip && way && T.wayChips[way]) {
            const zone = way === 'delivery' ? currentZone() : null;
            chip.textContent = T.wayChips[way] + (zone ? ` · ${zone.name.split('› ').pop()}` : '');
        }
        syncQuick();
        renderBar();
        if (cartSheet.open) renderCart();
    }
    function applyWay(way) {
        wayPicked = true;
        if (way === 'dine') mode = hallCart;
        else if (O && O[way]) { mode = 'online'; otype = way; }
        renderMode();
    }
    const root = document.documentElement;
    function chooseWay(way) {
        if (!H.table) session.set(wayKey, way);
        applyWay(way);
        // delivery: the area comes first, so the fee, the minimum and the time are known before adding items
        if (way === 'delivery' && !currentZone()) openDelivery();
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
        syncWayCards();
        root.classList.add('choice-open');
        if (reopen) root.classList.add('choice-reopen');
        if (focus) setTimeout(() => $(focus, firstSheet)?.scrollIntoView({ block: 'start', behavior: reducedMotionNow() ? 'auto' : 'smooth' }), 50);
    }
    const closeFirstSheet = () => root.classList.remove('choice-open', 'choice-reopen');
    const reducedMotionNow = () => !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    let renderBranchPart = null;
    // a known branch shows alone in the sheet; this opens the whole list (the branch chip, "change the branch")
    let openBranchList = null;
    let branchDistances = null;
    // the branch picked in the sheet (or the one on screen) and the ways it offers
    const sheetBranch = () => {
        const list = M.branches || [];
        const current = list.find((b) => b.id === M.branch);
        return list.find((b) => b.slug === (pickedBranch || current?.slug)) || current || null;
    };
    const waysOf = (b) => (b ? b.ways || [] : M.ways || []);
    // 2+ branches: a way the picked branch lacks (delivery without a zone there) is greyed out, with a note and a
    // one-tap switch to the nearest branch that has it; the guest's way is never changed behind their back
    const wayMissing = firstSheet?.querySelector('[data-way-missing]');
    function syncWayCards() {
        if (!(M.branches || []).length) return;
        const chosen = sheetBranch();
        let missing = null;
        wayCards().forEach((card) => {
            const here = waysOf(chosen).includes(card.dataset.way);
            card.disabled = !here;
            if (!here && !missing) missing = card.dataset.way;
        });
        if (!wayMissing) return;
        wayMissing.classList.toggle('hidden', !missing);
        if (!missing) return;
        const others = (M.branches || []).filter((b) => b !== chosen && waysOf(b).includes(missing));
        if (branchDistances) others.sort((a, b) => (branchDistances[a.id] ?? 1e9) - (branchDistances[b.id] ?? 1e9));
        const other = others[0];
        const name = chosen?.name || '';
        $('[data-way-missing-text]', wayMissing).textContent = missing === 'delivery'
            ? (other ? T.noDeliveryTry.replace('__B__', name).replace('__O__', other.name) : T.noDeliveryHere.replace('__B__', name))
            : T.wayNotHere;
        const btn = $('[data-way-missing-switch]', wayMissing);
        btn.classList.toggle('hidden', !other);
        if (other) {
            btn.textContent = T.switchBranch.replace('__B__', other.name);
            btn.onclick = () => { pickedBranch = other.slug; renderBranchPart?.(); syncWayCards(); };
        }
    }
    // the guest's choice: the way (if any) is remembered and applied; another branch reloads its own menu
    function finishFirstSheet(way) {
        const current = (M.branches || []).find((b) => b.id === M.branch)?.slug;
        const moving = pickedBranch && pickedBranch !== current;
        // a way the picked branch does not offer is not taken (its card is greyed out with a note)
        if (way && (M.branches || []).length && !waysOf(sheetBranch()).includes(way)) {
            syncWayCards();
            return;
        }
        if (way && !H.table && (moving || (M.ways || []).includes(way))) session.set(wayKey, way);
        if (way && !moving && (M.ways || []).includes(way)) applyWay(way);
        if (moving) {
            // the other branch's menu (prices, what is available) loads; its delivery areas are asked there
            if (way === 'delivery') session.set(zoneAskKey, '1');
            const url = new URL(location.href);
            url.searchParams.set('branch', pickedBranch);
            location.href = url.toString();
            return;
        }
        closeFirstSheet();
        if (way === 'delivery' && !currentZone()) openDelivery();
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
                syncWayCards();
                // only a branch to choose: the tap is the choice
                if (!hasWays) finishFirstSheet(null);
                return;
            }
            if (e.target.closest('[data-choice-close]')) closeFirstSheet();
        });
        // the chips in the header reopen it; the branch chip brings the branch list into view
        $('[data-way-chip]')?.addEventListener('click', () => openFirstSheet(true));
        $('[data-branch-open]')?.addEventListener('click', () => {
            // the branch chip is a wish to change the branch: the whole list, not the known one alone
            openBranchList?.(false);
            openFirstSheet(true, '[data-branch-sheet]');
        });
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
        if (mapFirst) setDeliveryBy('list');
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
    // "pin my location on the map instead" (zone sheet, cart): back to the map
    document.addEventListener('click', (e) => {
        if (!e.target.closest('[data-use-map]')) return;
        setDeliveryBy('map');
        zoneSheet?.close();
        renderMode();
        openPin();
    });
    zoneSheet?.addEventListener('input', (e) => {
        const box = e.target.closest('[data-zone-search]');
        if (!box) return;
        const q = box.value.trim().toLowerCase();
        box.parentElement.querySelectorAll('[data-zone-text]').forEach((b) => b.classList.toggle('hidden', !!q && !b.dataset.zoneText.includes(q)));
    });
    function detectZone(btn) {
        if (!navigator.geolocation) return;
        const label = btn.textContent;
        btn.disabled = true;
        btn.textContent = T.locating;
        navigator.geolocation.getCurrentPosition((pos) => {
            btn.disabled = false;
            btn.textContent = label;
            const { latitude: lat, longitude: lng } = pos.coords;
            // a drawn street inside a drawn area wins over the area
            const zone = zoneAt(O.zones, lat, lng);
            if (zone) pickZone(zone.id);
            else $('[data-zone-outside]', zoneSheet).classList.remove('hidden');
        }, () => {
            btn.disabled = false;
            btn.textContent = label;
            $('[data-zone-outside]', zoneSheet).classList.remove('hidden');
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 120000 });
    }

    // ---- the delivery map: a draggable pin (Leaflet loaded on demand from the pinned CDN copy) ----
    // Map first (mapFirst): the pin prices the delivery live (area or distance, fee, minimum, time, or "we do not
    // deliver here") and "confirm" makes it the delivery; the list of areas is the fallback. Without it (areas typed
    // by hand) the pin is optional and only shows the driver the door. Either way the pin's street fills the address
    // fields, like the delivery apps (asked from this host: Menu\GeoController, cached there).
    const pinSheet = $('[data-pin-sheet]');
    const pinOpenLabel = $('[data-pin-open-text]')?.textContent || '';
    const pinDoneLabel = $('[data-pin-done-text]')?.textContent || '';
    // "Delivery 25 EGP · Minimum 100 EGP · About 45 min"
    const quoteFacts = (q) => [
        T.factFee.replace('__A__', fmt(q.fee)),
        q.min > 0 ? T.factMin.replace('__A__', fmt(q.min)) : null,
        q.eta ? T.factEta.replace('__N__', q.eta) : null,
    ].filter(Boolean).join(' · ');

    // ---- the address in parts (area, street, building, floor, apartment, landmark, «احفظه باسم») ----
    const ADDR = ['area', 'street', 'building', 'floor', 'apartment', 'landmark'];
    const addrField = (k) => $(`[data-addr="${k}"]`);
    function addrParts() {
        const parts = {};
        ADDR.forEach((k) => { const v = addrField(k)?.value.trim(); if (v) parts[k] = v; });
        const label = $('[data-addr-label]:checked')?.value;
        if (label) parts.label = label;
        return parts;
    }
    // auto: from the pin, only into empty fields or fields the pin filled before (what the guest typed stays)
    function fillAddress(parts, { auto = false } = {}) {
        let filled = false;
        ADDR.forEach((k) => {
            const field = addrField(k);
            if (!field || (auto && !(k in parts))) return;
            const value = String(parts[k] ?? '').slice(0, Number(field.maxLength) > 0 ? Number(field.maxLength) : 150);
            if (auto && field.value.trim() && field.dataset.auto !== '1') return;
            field.value = value;
            if (auto && value) { field.dataset.auto = '1'; filled ||= k === 'street'; } else delete field.dataset.auto;
        });
        if (!auto && parts.label) document.querySelectorAll('[data-addr-label]').forEach((r) => { r.checked = r.value === parts.label; });
        $('[data-addr-auto]')?.classList.toggle('hidden', !(auto && filled));
        renderSendHelp();
    }
    document.addEventListener('input', (e) => {
        const field = e.target.closest('[data-addr]');
        if (!field) return;
        delete field.dataset.auto; // typed by the guest: a new pin never writes over it
        $('[data-addr-auto]')?.classList.add('hidden');
    });

    // ---- the place at the pin («شارع البحر، قسم أول طنطا»): one call per ~25 m, kept for this visit ----
    function lookupPlace(lat, lng) {
        if (!GEO) return Promise.resolve(null);
        const key = cell(lat, lng);
        if (!placeCache.has(key)) {
            const url = new URL(GEO.reverse, location.href);
            url.searchParams.set('lat', lat.toFixed(6));
            url.searchParams.set('lng', lng.toFixed(6));
            if (O?.branch) url.searchParams.set('branch', O.branch);
            const ask = fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : null)).catch(() => null);
            ask.then((d) => { if (!d) placeCache.delete(key); }); // busy or offline: asked again next time
            placeCache.set(key, ask);
        }
        return placeCache.get(key);
    }
    // a place already known for a point (a saved address, a search result): no call for it
    const knowPlace = (lat, lng, p) => placeCache.set(cell(lat, lng), Promise.resolve({ found: true, ...p }));
    function setPlace(p) {
        place = p;
        session.set(placeKey, p && !p.pending ? JSON.stringify(p) : '');
        renderPin();
        renderLocation();
    }
    if (pin && place && cell(pin.lat, pin.lng) !== cell(place.lat, place.lng)) setPlace(null);
    if (pin && place) knowPlace(pin.lat, pin.lng, place);

    function renderPin() {
        const box = $('[data-pin-box]');
        if (!box) return;
        $('[data-pin-done]', box).classList.toggle('hidden', !pin);
        $('[data-pin-done]', box).classList.toggle('flex', !!pin);
        $('[data-pin-open-text]', box).textContent = pin ? T.pinChange : pinOpenLabel;
        const done = $('[data-pin-done-text]', box);
        if (done) done.textContent = place?.pending ? T.placeLoading : place?.formatted_ar ? T.pinnedAt.replace('__P__', place.formatted_ar) : pinDoneLabel;
    }
    // the cart's delivery place: the confirmed pin with its street and price (map), or the list of areas (fallback);
    // the address fields come below once the pin is confirmed
    function renderLocation() {
        const box = $('[data-loc-box]');
        const q = byMap() && pin ? pinQuote(pin.lat, pin.lng) : null;
        $('[data-addr-form]')?.classList.toggle('hidden', byMap() && !q);
        if (!box) return;
        box.classList.toggle('hidden', !byMap());
        $('[data-zone-field]')?.classList.toggle('hidden', byMap());
        $('[data-loc-start]', box).classList.toggle('hidden', !!q);
        $('[data-loc-start]', box).classList.toggle('flex', !q);
        $('[data-loc-card]', box).classList.toggle('hidden', !q);
        $('[data-loc-card]', box).classList.toggle('flex', !!q);
        if (q) {
            $('[data-loc-place]', box).textContent = place?.pending ? T.placeLoading : `📍 ${place?.formatted_ar || q.name}`;
            $('[data-loc-facts]', box).textContent = (place?.formatted_ar ? `${q.name} · ` : '') + quoteFacts(q);
        }
    }
    function setPin(point) {
        pin = point;
        session.set(pinKey, point ? JSON.stringify(point) : '');
        if (!point) setPlace(null);
        renderPin();
        renderLocation();
        renderSendHelp();
    }
    // the pin is confirmed: its area right away (drawn zone / ring), then its street into the fields
    async function applyPlaceFor(point) {
        const quote = mapFirst && byMap() ? pinQuote(point.lat, point.lng) : null;
        if (!byMap()) {
            const zone = zoneAt(O?.zones, point.lat, point.lng);
            if (zone && $('[data-zone]')?.value !== String(zone.id)) pickZone(zone.id);
        }
        if (!GEO) return;
        setPlace({ pending: true, lat: point.lat, lng: point.lng });
        const p = await lookupPlace(point.lat, point.lng);
        if (!pin || cell(pin.lat, pin.lng) !== cell(point.lat, point.lng)) return; // moved again meanwhile
        const found = p?.found && p.formatted_ar;
        setPlace(found ? { formatted_ar: p.formatted_ar, street: p.street, house_number: p.house_number, area: p.area, city: p.city, lat: point.lat, lng: point.lng } : null);
        // the area: the district of the pin, or the name of its delivery area (never "2.3 km from the restaurant")
        const zoneName = quote?.id ? quote.name : (!byMap() ? currentZone()?.name : null);
        fillAddress({ street: p?.street || '', building: p?.house_number || '', area: p?.area || zoneName || '' }, { auto: true });
        // areas typed by hand: the one named like the pin's district, when the server is sure of it
        if (!byMap() && p?.zone?.id && !$('[data-zone]')?.value) pickZone(p.zone.id);
        if (cartSheet.open) {
            const next = ['street', 'building'].map(addrField).find((f) => f && !f.value.trim()) || addrField('floor');
            setTimeout(() => next?.focus(), 80);
        }
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
    let pinFrame = 0;
    function pinCenter() {
        if (pin) return [pin.lat, pin.lng];
        const start = O?.pricing?.center || M.branchPoint;
        if (start) return start;
        const zone = currentZone();
        const poly = zone?.polygon || (O?.zones || []).find((z) => z.polygon)?.polygon;
        if (poly) return [poly.reduce((s, p) => s + p[0], 0) / poly.length, poly.reduce((s, p) => s + p[1], 0) / poly.length];
        return [30.0444, 31.2357];
    }
    // the street under the map while the pin moves: asked ~0.7 s after it stops (a known square answers at once)
    let placeTimer = 0;
    let placeSeq = 0;
    function schedulePlace(lat, lng) {
        const line = $('[data-pin-place]', pinSheet);
        if (!GEO || !line) return;
        clearTimeout(placeTimer);
        const seq = ++placeSeq;
        const known = placeCache.has(cell(lat, lng));
        if (!known) {
            line.textContent = T.placeLoading;
            line.classList.add('m-muted');
            line.classList.remove('hidden');
        }
        placeTimer = setTimeout(async () => {
            const p = await lookupPlace(lat, lng);
            if (seq !== placeSeq) return;
            line.classList.toggle('m-muted', !p?.found);
            line.textContent = p?.found && p.formatted_ar ? `📍 ${p.formatted_ar}` : T.placeUnknown;
            line.classList.remove('hidden');
        }, known ? 0 : 700);
    }
    // the live price under the map while the pin moves
    function pinMoved() {
        if (!pinMarker) return;
        const { lat, lng } = pinMarker.getLatLng();
        schedulePlace(lat, lng);
        if (!mapFirst) return;
        const q = pinQuote(lat, lng);
        const inBox = $('[data-pin-quote-in]', pinSheet);
        inBox.classList.toggle('hidden', !q);
        $('[data-pin-quote-out]', pinSheet).classList.toggle('hidden', !!q);
        if (q) {
            $('[data-pin-quote-name]', inBox).textContent = q.name;
            $('[data-pin-quote-facts]', inBox).textContent = quoteFacts(q);
        }
        $('[data-pin-confirm]', pinSheet).disabled = !q;
    }
    function locateMe(btn) {
        if (!navigator.geolocation || !pinMarker) return;
        if (btn) btn.disabled = true;
        navigator.geolocation.getCurrentPosition((pos) => {
            if (btn) btn.disabled = false;
            const here = [pos.coords.latitude, pos.coords.longitude];
            pinMarker.setLatLng(here);
            pinMap.setView(here, 17);
            pinMoved();
        }, () => {
            if (btn) {
                btn.disabled = false;
                toast(T.pinNoLocation);
            }
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
    }
    async function openPin() {
        if (!pinSheet) return;
        if (!pinSheet.open) pinSheet.showModal();
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
            pinMap = L.map($('[data-pin-map]', pinSheet), { zoomControl: true }).setView(center, pin ? 17 : 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(pinMap);
            // where the pin may go: the drawn areas, or the farthest distance ring around the restaurant
            const ink = getComputedStyle(document.documentElement).getPropertyValue('--menu-primary').trim() || '#0A2540';
            if (O?.pricing?.method === 'distance' && O.pricing.center && O.pricing.rings.length) {
                const far = Math.max(...O.pricing.rings.map((r) => r.km));
                L.circle(O.pricing.center, { radius: far * 1000, color: ink, weight: 1.5, dashArray: '6 6', fillOpacity: 0.04 }).addTo(pinMap);
                L.circleMarker(O.pricing.center, { radius: 7, color: '#fff', weight: 2, fillColor: ink, fillOpacity: 1 }).bindTooltip(T.restaurantHere).addTo(pinMap);
            } else {
                (O?.zones || []).filter((z) => z.polygon).forEach((z) => L.polygon(z.polygon, { color: ink, weight: 1.5, fillOpacity: 0.06 }).addTo(pinMap));
            }
            pinMarker = L.marker(center, { draggable: true, autoPan: true }).addTo(pinMap);
            pinMarker.on('drag', () => { cancelAnimationFrame(pinFrame); pinFrame = requestAnimationFrame(pinMoved); });
            pinMarker.on('dragend', pinMoved);
            pinMap.on('click', (e) => { pinMarker.setLatLng(e.latlng); pinMoved(); });
        } else {
            pinMarker.setLatLng(center);
            pinMap.setView(center, pin ? 17 : 15);
        }
        setTimeout(() => pinMap.invalidateSize(), 50); // the dialog was hidden when the map measured itself
        pinMoved();
        // no pin yet and the guest already allowed their location on this site: start from where they are
        if (mapFirst && !pin && navigator.permissions?.query) {
            navigator.permissions.query({ name: 'geolocation' }).then((p) => { if (p.state === 'granted') locateMe(null); }).catch(() => {});
        }
    }
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-pin-open]')) openPin();
    });
    $('[data-pin-clear]')?.addEventListener('click', () => setPin(null));
    pinSheet?.addEventListener('click', (e) => {
        const result = e.target.closest('[data-pin-result]');
        if (result) {
            pickSearchResult(Number(result.dataset.pinResult));
        } else if (e.target.closest('[data-pin-locate]')) {
            locateMe(e.target.closest('[data-pin-locate]'));
        } else if (e.target.closest('[data-pin-list]')) {
            // "choose your area from the list instead of the map"
            setDeliveryBy('list');
            pinSheet.close();
            renderMode();
            openZones();
        } else if (e.target.closest('[data-pin-confirm]')) {
            if (!pinMarker) return;
            const { lat, lng } = pinMarker.getLatLng();
            if (mapFirst && !pinQuote(lat, lng)) return pinMoved(); // outside: "we do not deliver here" stays up
            if (mapFirst) setDeliveryBy('map');
            const point = { lat: Math.round(lat * 1e6) / 1e6, lng: Math.round(lng * 1e6) / 1e6 };
            setPin(point);
            pinSheet.close();
            renderMode();
            renderCart();
            // next: the address in detail, its street already filled from the pin
            applyPlaceFor(point);
        }
    });

    // ---- «دوّر على شارع أو مكان»: after a pause in typing (3+ letters) or Enter; a result moves the pin ----
    const searchBox = $('[data-pin-search]', pinSheet || document);
    const resultsBox = $('[data-pin-results]', pinSheet || document);
    let searchResults = [];
    let searchTimer = 0;
    let searchSeq = 0;
    function showResults(html) {
        if (!resultsBox) return;
        resultsBox.innerHTML = html;
        resultsBox.classList.toggle('hidden', !html);
        searchBox?.setAttribute('aria-expanded', html ? 'true' : 'false');
    }
    async function runSearch() {
        const q = searchBox.value.trim();
        clearTimeout(searchTimer);
        if (q.length < 3 || !GEO) return showResults('');
        const seq = ++searchSeq;
        showResults(`<p class="m-muted p-3 text-sm">${esc(T.placeLoading)}</p>`);
        const url = new URL(GEO.search, location.href);
        url.searchParams.set('q', q.slice(0, 100));
        if (O?.branch) url.searchParams.set('branch', O.branch);
        const d = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null)).catch(() => null);
        if (seq !== searchSeq) return;
        searchResults = d?.results || [];
        if (!d?.ok) return showResults(`<p class="m-muted p-3 text-sm">${esc(T.searchFailed)}</p>`);
        if (!searchResults.length) return showResults(`<p class="m-muted p-3 text-sm">${esc(T.searchNone)}</p>`);
        showResults(searchResults.map((r, i) => `
            <button type="button" class="m-pin-result" role="option" data-pin-result="${i}">
                <span class="block truncate text-sm font-bold" dir="auto">📍 ${esc(r.label)}</span>
                ${r.city && !r.label.includes(r.city) ? `<span class="m-muted block truncate text-xs" dir="auto">${esc(r.city)}</span>` : ''}
            </button>`).join(''));
    }
    function pickSearchResult(i) {
        const r = searchResults[i];
        if (!r || !pinMarker) return;
        knowPlace(r.lat, r.lng, r);
        pinMarker.setLatLng([r.lat, r.lng]);
        pinMap.setView([r.lat, r.lng], 17);
        showResults('');
        searchBox.value = r.label;
        pinMoved();
    }
    searchBox?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 1000);
        if (searchBox.value.trim().length < 3) showResults('');
    });
    searchBox?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
        if (e.key === 'Escape' && !resultsBox.classList.contains('hidden')) { e.preventDefault(); e.stopPropagation(); showResults(''); }
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
    // the remembered way is not offered by this branch (delivery without a zone here): say it, never switch silently
    {
        const saved = !H.table ? session.get(wayKey) : null;
        if (saved && saved !== 'dine' && !(M.ways || []).includes(saved)) {
            session.set(wayKey, '');
            if (saved === 'delivery') setTimeout(() => toast(T.noDeliveryHere.replace('__B__', sheetBranch()?.name || '')), 400);
        }
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
        if (mode === 'online' && otype === 'delivery' && !currentZone()) openDelivery();
    }

    // ---- the personal link of a manual campaign (?ref=): kept 14 days on this phone and sent with the next order ----
    const refKey = `egmenu.ref.${M.tenant}`;
    try {
        const here = new URL(location.href);
        const ref = here.searchParams.get('ref');
        if (ref && /^[a-f0-9]{10}$/.test(ref)) {
            localStorage.setItem(refKey, JSON.stringify({ ref, at: Date.now() }));
            here.searchParams.delete('ref');
            history.replaceState(history.state, '', here.toString());
        }
    } catch {}
    function campaignRef() {
        try {
            const saved = JSON.parse(localStorage.getItem(refKey) || 'null');
            return saved && Date.now() - (saved.at || 0) < 14 * 86400000 ? saved.ref : null;
        } catch { return null; }
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
        if (!wayPicked) return fail(T.chooseWay);
        if (mode === 'online') {
            if (!$('[data-customer-phone]').value.trim()) return fail(T.phoneRequired);
            if (otype === 'delivery' && !currentZone()) return fail(byMap() ? T.pinFirst : T.chooseZone);
            if (otype === 'delivery' && !addrField('street')?.value.trim()) {
                addrField('street')?.focus();
                return fail(T.enterStreet);
            }
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
                    customer_email: $('[data-customer-email]')?.value.trim() || null,
                    ref: campaignRef(),
                    notes: $('[data-order-notes]').value.trim(),
                    coupon: $('[data-coupon]')?.value.trim() || null,
                    pay_wallet: !!$('[data-pay-wallet]')?.checked,
                    ...(mode === 'online' ? {
                        // map first: only the pin goes, the server finds its area or distance ring and its fee
                        type: otype, branch_id: O.branch, zone_id: byMap() ? null : currentZone()?.id ?? null,
                        // the address in parts: the server writes the one line the restaurant reads
                        address_parts: otype === 'delivery' ? addrParts() : null, scheduled_for: $('[data-scheduled]')?.value || null,
                        ...(otype === 'delivery' && pin && (!mapFirst || byMap()) ? { lat: pin.lat, lng: pin.lng } : {}),
                    } : {}),
                }),
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) {
                const first = body.errors ? Object.values(body.errors)[0][0] : null;
                throw new Error(body.message || first || res.status);
            }
            rememberLastOrder();
            rememberGuest();
            try { localStorage.removeItem(refKey); } catch {}
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

    // ---- the guest's name and mobile, kept on this phone after an order and filled in next time ("not you?" clears) ----
    const guestKey = `egmenu.guest.${M.tenant}`;
    const guestForget = $('[data-guest-forget]');
    function rememberGuest() {
        const name = $('[data-customer-name]')?.value.trim() || '';
        const phone = $('[data-customer-phone]')?.value.trim() || '';
        const email = $('[data-customer-email]')?.value.trim() || '';
        if (!name && !phone) return;
        try { localStorage.setItem(guestKey, JSON.stringify({ name, phone, email })); } catch {}
    }
    // signed out of "حسابي" on this phone (Menu\AccountController::signOut): the saved name, mobile and last order go too
    try {
        const here = new URL(location.href);
        if (here.searchParams.has('signed_out')) {
            localStorage.removeItem(guestKey);
            localStorage.removeItem(`egmenu.last.${M.tenant}`);
            here.searchParams.delete('signed_out');
            history.replaceState(history.state, '', here.toString());
        }
    } catch {}
    try {
        const saved = JSON.parse(localStorage.getItem(guestKey) || 'null');
        const nameField = $('[data-customer-name]');
        const phoneField = $('[data-customer-phone]');
        if (saved && nameField && phoneField && (saved.name || saved.phone)) {
            if (!nameField.value) nameField.value = String(saved.name || '').slice(0, 100);
            if (!phoneField.value) phoneField.value = String(saved.phone || '').slice(0, 20);
            const emailField = $('[data-customer-email]');
            if (emailField && !emailField.value) emailField.value = String(saved.email || '').slice(0, 190);
            guestForget?.classList.remove('hidden');
        }
        // the guest this phone remembers ("حسابي"): their name and mobile when nothing else is saved
        if (M.account && nameField && phoneField && !nameField.value && !phoneField.value) {
            nameField.value = String(M.account.name || '').slice(0, 100);
            phoneField.value = String(M.account.phone || '').slice(0, 20);
            guestForget?.classList.remove('hidden');
        }
    } catch {}
    guestForget?.addEventListener('click', () => {
        try { localStorage.removeItem(guestKey); } catch {}
        $('[data-customer-name]').value = '';
        $('[data-customer-phone]').value = '';
        if ($('[data-customer-email]')) $('[data-customer-email]').value = '';
        guestForget.classList.add('hidden');
        $('[data-customer-name]').focus();
        renderSendHelp?.();
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
    // ---- the returning guest («أهلاً {name}»): asked from /me/summary (no-store) so nothing personal sits in the
    // cached menu; their last order again in one tap, the dishes they order most, their saved addresses at checkout ----
    const welcomeBox = $('[data-guest-welcome]');
    if (welcomeBox && M.summaryUrl) {
        const url = new URL(M.summaryUrl, location.href);
        if (M.branch) url.searchParams.set('branch', M.branch);
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => {
                const g = d?.guest;
                if (!g) return;
                $('[data-guest-hello]', welcomeBox).textContent = g.name ? T.helloName.replace('__N__', g.name) : T.hello;
                const extra = [];
                if (g.points !== null && g.points !== undefined) extra.push(T.pointsLine.replace('__N__', g.points));
                if (g.stamps) extra.push(T.stampsLine.replace('__N__', `${g.stamps.have}/${g.stamps.of}`));
                if (g.wallet) extra.push(T.walletLine.replace('__N__', fmt(g.wallet)));
                $('[data-guest-extra]', welcomeBox).textContent = extra.join(' · ');
                $('[data-guest-account]', welcomeBox).href = g.account;
                const last = g.last;
                const lastBox = $('[data-guest-last]', welcomeBox);
                if (last && (last.lines.length || last.skipped) && quickOn()) {
                    $('[data-guest-last-title]', lastBox).textContent = T.lastOrderTitle.replace('__N__', last.number).replace('__D__', last.date);
                    $('[data-guest-last-lines]', lastBox).textContent = last.summary;
                    const skippedEl = $('[data-guest-last-skipped]', lastBox);
                    if (last.skippedNames?.length) {
                        skippedEl.textContent = T.notAvailableNow.replace('__N__', last.skippedNames.join(document.documentElement.lang === 'ar' ? '، ' : ', '));
                        skippedEl.classList.remove('hidden');
                    }
                    const again = $('[data-guest-again]', lastBox);
                    again.disabled = !last.lines.length;
                    again.addEventListener('click', () => {
                        if (!quickOn()) return;
                        if (putBack(last.lines, last.skipped)) { renderCart(); cartSheet.showModal(); }
                    });
                    lastBox.classList.remove('hidden');
                    // the phone-only "your last order" box says the same thing: one card is enough
                    $('[data-last-order]')?.classList.add('hidden');
                }
                const favs = (g.favourites || []).map((id) => M.items[id]).filter((item) => item && !item.soldOut);
                if (favs.length) {
                    $('[data-guest-favs-list]', welcomeBox).innerHTML = favs.map((item) => `
                        <button type="button" data-item="${item.id}" class="w-28 shrink-0 overflow-hidden rounded-2xl border m-border m-surface text-start shadow-sm">
                            ${phTile(item, 'aspect-square w-full')}
                            <div class="p-2"><div class="line-clamp-2 text-xs font-bold">${esc(item.name)}</div>
                            ${hidePrices() ? '' : `<div class="m-primary-text text-xs font-extrabold">${esc(fmt(item.price))}</div>`}</div>
                        </button>`).join('');
                    $('[data-guest-favs]', welcomeBox).classList.remove('hidden');
                }
                welcomeBox.classList.remove('hidden');
                // saved delivery addresses: a tap puts the pin back, the area and every field (no new street lookup)
                const pinned = (a) => a.lat !== null && a.lng !== null && a.lat !== undefined;
                const usable = (a) => (mapFirst && pinned(a) && pinQuote(a.lat, a.lng))
                    || (a.zone_id && O?.zones?.some((z) => String(z.id) === String(a.zone_id)));
                const saved = (g.addresses || []).filter(usable);
                const savedBox = $('[data-saved-addresses]');
                if (savedBox && saved.length) {
                    const partsOf = (a) => (a.parts && Object.keys(a.parts).length ? a.parts : { street: a.text });
                    $('[data-saved-addresses-list]', savedBox).innerHTML = saved.map((a, i) => `
                        <button type="button" class="m-border min-h-11 max-w-full truncate rounded-full border px-3 py-2 text-xs font-bold" data-saved-address="${i}">${a.parts?.label === 'work' ? '💼' : a.parts?.label === 'home' ? '🏠' : '📍'} ${esc(a.label || a.zone)} · ${esc((partsOf(a).street || a.text).slice(0, 40))}</button>`).join('');
                    savedBox.classList.remove('hidden');
                    savedBox.addEventListener('click', (e) => {
                        const b = e.target.closest('[data-saved-address]');
                        if (!b) return;
                        const a = saved[Number(b.dataset.savedAddress)];
                        const parts = partsOf(a);
                        if (mapFirst && pinned(a) && pinQuote(a.lat, a.lng)) {
                            setDeliveryBy('map');
                        } else {
                            pickZone(a.zone_id);
                        }
                        if (pinned(a)) {
                            const known = { formatted_ar: [parts.street, parts.area].filter(Boolean).join('، ') || a.text, street: parts.street || null, area: parts.area || null, lat: a.lat, lng: a.lng };
                            knowPlace(a.lat, a.lng, known);
                            setPin({ lat: a.lat, lng: a.lng });
                            setPlace(known);
                        }
                        fillAddress({ area: '', building: '', floor: '', apartment: '', landmark: '', ...parts });
                        savedBox.querySelectorAll('[data-saved-address]').forEach((x) => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
                        renderMode();
                        renderCart();
                    });
                }
            })
            .catch(() => {});
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

    // ---- a tap outside any bottom sheet closes it (the item, the cart, "goes well with", the area…), like delivery apps.
    // Only when the press both starts and ends on the dimmed backdrop, so a drag out of a field or the map never closes it.
    // The full-page item (pro display) and the first-open choice keep their own buttons. ----
    let backdropPress = null;
    document.addEventListener('pointerdown', (e) => { backdropPress = e.target instanceof HTMLDialogElement ? e.target : null; }, true);
    document.addEventListener('click', (e) => {
        const dialog = e.target;
        if (!(dialog instanceof HTMLDialogElement) || dialog !== backdropPress || !dialog.open) return;
        if (dialog.matches('[data-item-page], [data-first-sheet], [data-keep-open]')) return;
        dialog.close();
    });

    // Pull a bottom sheet down to close it: from its top (scrolled to the start), a drag past 110px closes, less springs back.
    document.addEventListener('touchstart', (e) => {
        const panel = e.target instanceof Element ? e.target.closest('dialog[open] > form') : null;
        const dialog = panel?.parentElement;
        if (!panel || dialog.matches('[data-item-page], [data-first-sheet], [data-keep-open]') || panel.scrollTop > 0) return;
        if (e.target.closest('input, textarea, select, [data-map], .leaflet-container, [data-no-pull]')) return;
        const startY = e.touches[0].clientY;
        let dy = 0;
        const move = (ev) => {
            dy = Math.max(0, ev.touches[0].clientY - startY);
            if (dy > 0 && panel.scrollTop <= 0) {
                panel.style.transition = 'none';
                panel.style.transform = `translateY(${dy}px)`;
            }
        };
        const end = () => {
            panel.removeEventListener('touchmove', move);
            panel.removeEventListener('touchend', end);
            panel.removeEventListener('touchcancel', end);
            panel.style.transition = 'transform .18s ease';
            if (dy > 110) {
                panel.style.transform = 'translateY(100%)';
                setTimeout(() => { dialog.close(); panel.style.transform = ''; panel.style.transition = ''; }, 170);
            } else {
                panel.style.transform = '';
            }
        };
        panel.addEventListener('touchmove', move, { passive: true });
        panel.addEventListener('touchend', end);
        panel.addEventListener('touchcancel', end);
    }, { passive: true });

    // ---- "حسابي" on a phone that does not know the guest: the sheet with the ways in (partials.account-sign-in) ----
    const accountSheet = $('[data-account-sheet]');
    if (accountSheet) {
        document.querySelectorAll('[data-account-open]').forEach((b) => b.addEventListener('click', () => accountSheet.showModal()));
        $('[data-account-close]', accountSheet)?.addEventListener('click', () => accountSheet.close());
        accountSheet.addEventListener('click', (e) => { if (e.target === accountSheet) accountSheet.close(); });
    }

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
        // the branch is known when the guest chose it (a link, the cookie) or this browser was on it last time
        const knownBranch = !!M.branchChosen || (mem.current != null && mem.current === M.branch);
        try { localStorage.setItem(branchMemKey, JSON.stringify({ current: M.branch, previous: lastVisit })); } catch {}
        let distances = null;
        // the known branch alone with "change the branch"; once the guest opens the list it stays open
        const changeBtn = $('[data-branch-change]', branchPart);
        let listOpened = false;
        const setCompact = (on) => {
            branchPart.classList.toggle('is-compact', on);
            changeBtn?.setAttribute('aria-expanded', on ? 'false' : 'true');
        };
        openBranchList = (focus) => {
            listOpened = true;
            if (!branchPart.classList.contains('is-compact')) return;
            setCompact(false);
            branchPart.classList.add('is-opening');
            setTimeout(() => branchPart.classList.remove('is-opening'), 300);
            if (focus) ($('[data-branch-search]', branchPart) || $('[data-branch-list] [aria-checked="true"]', branchPart))?.focus();
        };
        changeBtn?.addEventListener('click', () => openBranchList(true));
        setCompact(knownBranch);
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
                        <span class="flex items-center gap-2">${b.address ? `<span class="m-muted min-w-0 truncate text-xs" dir="auto">${esc(b.address)}</span>` : ''}${open}</span>
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
                branchDistances = distances;
                note.classList.add('hidden');
                nearBtn?.classList.add('hidden');
                // a tap on "nearest to me", or a first visit with no branch chosen: the nearest one is picked for the
                // guest (they can change it)
                if (!silent || (!M.branchChosen && !pickedBranch)) {
                    const near = [...B].filter((b) => distances[b.id] != null).sort((a, b) => distances[a.id] - distances[b.id])[0];
                    if (near) pickedBranch = near.slug;
                    // the location was already allowed: the nearest branch is known, it shows alone (until the
                    // guest asks for the list)
                    if (near && silent && !listOpened) setCompact(true);
                }
                renderBranchPart();
                syncWayCards();
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
