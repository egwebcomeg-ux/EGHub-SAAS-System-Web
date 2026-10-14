// Marketing site interactions (layouts/landing). Vanilla JS, no dependencies.
// Every block checks its own markup first, so the legal pages load this file without errors.

const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const nf = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* private mode */ } },
};
const root = $('#lp');
let T = {};
try { T = JSON.parse(root?.dataset.i18n || '{}'); } catch { /* keep defaults */ }

/* ---------- header: compact on scroll, mobile menu, active section ---------- */
const header = $('[data-header]');
if (header) {
    const onScroll = () => header.classList.toggle('is-compact', window.scrollY > 12);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    const toggle = $('[data-menu-toggle]');
    const panel = $('[data-mobile-nav]');
    toggle?.addEventListener('click', () => {
        const open = panel.hidden;
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
    });
    panel?.addEventListener('click', (e) => {
        if (e.target.closest('a')) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); }
    });

    const links = $$('[data-nav]');
    if (links.length && 'IntersectionObserver' in window) {
        const spy = new IntersectionObserver((entries) => {
            entries.forEach((en) => {
                if (!en.isIntersecting) return;
                links.forEach((a) => a.classList.toggle('is-active', a.dataset.nav === en.target.id));
            });
        }, { rootMargin: '-45% 0px -50% 0px' });
        links.forEach((a) => { const s = document.getElementById(a.dataset.nav); if (s) spy.observe(s); });
    }
}

/* ---------- scroll reveal + counters ---------- */
function countUp(el) {
    const to = Number(el.dataset.countTo) || 0;
    if (still || to === 0) { el.textContent = nf.format(to); return; }
    const start = performance.now();
    const dur = 1100;
    const tick = (now) => {
        const p = Math.min(1, (now - start) / dur);
        el.textContent = nf.format(Math.round(to * (1 - Math.pow(1 - p, 3))));
        if (p < 1) requestAnimationFrame(tick);
    };
    el.textContent = '0';
    requestAnimationFrame(tick);
}

if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
            if (!en.isIntersecting) return;
            en.target.classList.add('is-in');
            $$('[data-count-to]', en.target).forEach(countUp);
            io.unobserve(en.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    $$('[data-reveal]').forEach((el) => io.observe(el));
} else {
    $$('[data-reveal]').forEach((el) => el.classList.add('is-in'));
}

/* ---------- hero phone: add items, count the cart, send the order ---------- */
const phoneTimers = [];
function clearPhone() { phoneTimers.splice(0).forEach(clearTimeout); }
function runPhone(phone) {
    clearPhone();
    if (!phone) return;
    const items = $$('[data-item]', phone);
    const cart = $('[data-cart]', phone);
    const count = $('[data-count]', phone);
    const total = $('[data-total]', phone);
    const toast = $('[data-toast]', phone);
    const ding = $('[data-ding]');
    let n = 0;
    let sum = 0;
    const later = (ms, fn) => phoneTimers.push(setTimeout(fn, ms));
    const add = (item) => {
        n += 1;
        sum += Number(item.dataset.price);
        count.textContent = n;
        total.textContent = nf.format(sum);
        cart.classList.add('is-up');
        count.classList.remove('lp-badge-pop');
        void count.offsetWidth;
        count.classList.add('lp-badge-pop');
    };
    const reset = () => {
        n = 0; sum = 0;
        count.textContent = '0'; total.textContent = '0';
        cart.classList.remove('is-up');
        toast.classList.remove('is-on');
        items.forEach((i) => i.classList.remove('is-hit'));
    };
    if (still) { reset(); add(items[0]); add(items[2]); return; }

    const order = [0, 2, 1];
    const loop = () => {
        reset();
        order.forEach((idx, step) => {
            later(900 + step * 1300, () => { items[idx].classList.add('is-hit'); add(items[idx]); });
            later(900 + step * 1300 + 650, () => items[idx].classList.remove('is-hit'));
        });
        later(5200, () => { toast.classList.add('is-on'); cart.classList.remove('is-up'); });
        later(5600, () => {
            const bell = ding && $('svg', ding);
            if (bell) { bell.classList.remove('lp-ring'); void bell.offsetWidth; bell.classList.add('lp-ring'); }
        });
        later(8200, loop);
    };
    loop();
}

/* ---------- cafe / restaurant ---------- */
function setAudience(aud, save = true) {
    if (!root || !['cafe', 'restaurant'].includes(aud)) return;
    root.dataset.aud = aud;
    $$('[data-aud-set]').forEach((b) => {
        const on = b.dataset.audSet === aud;
        b.setAttribute(b.getAttribute('role') === 'tab' ? 'aria-selected' : 'aria-pressed', String(on));
    });
    const rec = aud === 'cafe' ? ['starter', 'basic'] : ['pro', 'business'];
    $$('[data-plan-code]').forEach((c) => c.classList.toggle('is-recommended', rec.includes(c.dataset.planCode)));
    $$('[data-col]').forEach((c) => {
        const on = c.dataset.col === aud;
        c.classList.toggle('bg-brand-50/60', on);
        c.classList.toggle('font-semibold', on);
        c.classList.toggle('text-navy-900', on);
    });
    if (save) store.set('egm-aud', aud);
    runPhone($$('[data-phone]').find((p) => p.offsetParent !== null));
}
if (root) {
    $$('[data-aud-set]').forEach((b) => b.addEventListener('click', () => setAudience(b.dataset.audSet)));
    setAudience(store.get('egm-aud') || 'cafe', false);
}

/* ---------- live order board ---------- */
const board = $('[data-board]');
if (board) {
    let orders = [];
    try { orders = JSON.parse(board.dataset.orders || '[]'); } catch { /* none */ }
    const cols = { new: $('[data-board-col="new"]', board), prep: $('[data-board-col="prep"]', board), ready: $('[data-board-col="ready"]', board) };
    const card = ([table, items, note], fresh) => {
        const el = document.createElement('div');
        el.className = 'rounded-xl border border-navy-100 bg-white p-2 shadow-sm' + (fresh ? ' lp-order border-brand-200' : '');
        el.innerHTML = '<div class="font-extrabold text-navy-900"></div><div class="text-navy-600"></div>' + (note ? '<div class="mt-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-amber-800"></div>' : '');
        el.children[0].textContent = table;
        el.children[1].textContent = items;
        if (note) el.children[2].textContent = note;
        return el;
    };
    let i = 0;
    const state = { new: [], prep: [], ready: [] };
    const render = (fresh) => {
        Object.entries(state).forEach(([k, list]) => {
            cols[k].replaceChildren(...list.map((o, j) => card(o, fresh && k === 'new' && j === 0)));
        });
    };
    const step = () => {
        state.ready = state.prep.slice(0, 2);
        state.prep = state.new.slice(-1).concat(state.prep).slice(0, 2);
        state.new = [orders[i % orders.length]].concat(state.new.slice(0, 1));
        i += 1;
        render(true);
    };
    if (orders.length) {
        state.new = [orders[0]]; state.prep = [orders[1]]; state.ready = [orders[2]];
        i = 3;
        render(false);
        if (!still) {
            let timer = null;
            const vis = new IntersectionObserver(([en]) => {
                clearInterval(timer);
                if (en.isIntersecting) timer = setInterval(step, 2600);
            });
            vis.observe(board);
        }
    }
}

/* ---------- printed menu demo ---------- */
const demo = $('[data-print-demo]');
if (demo) {
    const sheet = $('[data-paper-sheet]', demo);
    const label = $('[data-paper-label]', demo);
    const flash = (el) => { el.classList.remove('lp-flash'); void el.offsetWidth; el.classList.add('lp-flash'); };
    $$('[data-price-input]', demo).forEach((input) => {
        input.addEventListener('input', () => {
            const v = Math.max(0, Math.min(9999, Number(input.value) || 0));
            $$(`[data-price-out="${input.dataset.priceInput}"]`, demo).forEach((out) => { out.textContent = nf.format(v); flash(out); });
        });
    });
    $$('[data-paper]', demo).forEach((b) => b.addEventListener('click', () => {
        $$('[data-paper]', demo).forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        const a5 = b.dataset.paper === 'a5';
        sheet.style.width = a5 ? 'min(100%, 330px)' : '';
        sheet.style.fontSize = a5 ? '0.86em' : '';
        label.textContent = (a5 ? 'A5' : 'A4') + ' · PDF';
    }));
    $$('[data-accent]', demo).forEach((b) => b.addEventListener('click', () => {
        $$('[data-accent]', demo).forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        sheet.style.setProperty('--accent', b.dataset.accent);
    }));
    const exp = $('[data-export]', demo);
    const toast = $('[data-export-toast]', demo);
    exp?.addEventListener('click', () => {
        const txt = $('[data-export-label]', exp);
        exp.disabled = true;
        txt.textContent = T.exporting || '…';
        setTimeout(() => {
            exp.disabled = false;
            txt.textContent = T.exportPdf || 'PDF';
            toast.classList.add('is-on');
            setTimeout(() => toast.classList.remove('is-on'), 2600);
        }, still ? 0 : 900);
    });
}

/* ---------- features filter ---------- */
const filters = $$('[data-filter]');
filters.forEach((b) => b.addEventListener('click', () => {
    const f = b.dataset.filter;
    filters.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    $$('[data-feature]').forEach((card) => {
        const match = f === 'all' || card.dataset.feature === 'both' || card.dataset.feature === f;
        card.style.opacity = match ? '' : '0.35';
        card.style.filter = match ? '' : 'grayscale(1)';
    });
}));

/* ---------- legal pages: list the sections beside the text ---------- */
const toc = $('[data-toc]');
const legal = $('[data-legal]');
if (toc && legal) {
    $$('h2', legal).forEach((h, i) => {
        h.id = h.id || `s${i + 1}`;
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = `#${h.id}`;
        a.textContent = h.textContent;
        a.className = 'block rounded-lg px-2 py-1.5 text-navy-600 hover:bg-navy-50 hover:text-navy-900';
        li.append(a);
        toc.append(li);
    });
}

/* ---------- pricing: monthly / 6 / 12 months ---------- */
const cycleButtons = $$('[data-cycle]');
function setCycle(months) {
    cycleButtons.forEach((b) => b.setAttribute('aria-pressed', String(Number(b.dataset.cycle) === months)));
    $$('[data-plan-code]').forEach((card) => {
        const monthly = Number(card.dataset.monthly) || 0;
        let disc = {};
        try { disc = JSON.parse(card.dataset.discounts || '{}'); } catch { /* none */ }
        const pct = Number(disc[String(months)] || 0);
        // Same rounding as Plan::priceFor(): the whole cycle is rounded to whole pounds.
        const cycleTotal = months === 1 ? monthly : Math.round(monthly * months * (1 - pct / 100));
        const perMonth = months === 1 ? monthly : Math.round(cycleTotal / months);
        const price = $('[data-price]', card);
        price.textContent = nf.format(perMonth);
        if (!still) { price.classList.remove('lp-badge-pop'); void price.offsetWidth; price.classList.add('lp-badge-pop'); }
        $('[data-billed]', card).textContent = months === 1
            ? (T.billedMonthly || '')
            : (T.billedEvery || ':total / :period')
                .replace(':total', nf.format(cycleTotal))
                .replace(':period', (T.periods || {})[months] || months)
                .replace(':saved', nf.format(Math.round(monthly * months - cycleTotal)));
    });
}
cycleButtons.forEach((b) => b.addEventListener('click', () => setCycle(Number(b.dataset.cycle))));
