// Marketing site interactions (layouts/landing). Vanilla JS, no dependencies.
// Every block checks its own markup first, so any landing page can load this file.

const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const nf = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
let T = {};
try { T = JSON.parse(document.querySelector('[data-i18n]')?.dataset.i18n || '{}'); } catch { /* keep defaults */ }

/* ---------- header: compact on scroll, mega menu, mobile menu, bottom bar ---------- */
const header = $('[data-header]');
if (header) {
    const bottom = $('[data-bottom-bar]');
    const hero = $('[data-hero]');
    // reading progress under the header pill; the page height is measured on resize, not on every scroll
    const read = $('[data-read-progress]');
    let room = 0;
    const measure = () => { room = document.documentElement.scrollHeight - window.innerHeight; };
    if (read) {
        measure();
        window.addEventListener('resize', measure, { passive: true });
        if ('ResizeObserver' in window) { new ResizeObserver(measure).observe(document.body); }
    }
    const onScroll = () => {
        header.classList.toggle('is-compact', window.scrollY > 8);
        if (read) { read.style.setProperty('--lp-read', room > 0 ? Math.min(1, window.scrollY / room).toFixed(4) : '0'); }
        if (bottom) {
            const past = hero ? hero.getBoundingClientRect().bottom < 0 : window.scrollY > 500;
            const nearEnd = window.innerHeight + window.scrollY > document.body.scrollHeight - 420;
            bottom.classList.toggle('is-on', past && !nearEnd);
        }
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    // desktop mega menu: click or hover (with a short grace period so the pointer can travel to the panel)
    const megaBtn = $('[data-mega-toggle]');
    const mega = $('[data-mega]');
    if (megaBtn && mega) {
        let closeTimer = null;
        const setMega = (open) => {
            clearTimeout(closeTimer);
            mega.classList.toggle('is-open', open);
            megaBtn.setAttribute('aria-expanded', String(open));
        };
        megaBtn.addEventListener('click', () => setMega(megaBtn.getAttribute('aria-expanded') !== 'true'));
        if (window.matchMedia('(hover: hover)').matches) {
            [megaBtn, mega].forEach((el) => {
                el.addEventListener('pointerenter', () => setMega(true));
                el.addEventListener('pointerleave', () => { closeTimer = setTimeout(() => setMega(false), 180); });
            });
        }
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { setMega(false); } });
        document.addEventListener('click', (e) => { if (!e.target.closest('[data-mega], [data-mega-toggle]')) { setMega(false); } });
    }

    // mobile drawer
    const toggle = $('[data-menu-toggle]');
    const panel = $('[data-mobile-nav]');
    const setPanel = (open) => {
        panel.hidden = !open;
        header.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('lp-locked', open);
        $$('[data-menu-icon]', toggle).forEach((i) => { i.hidden = (i.dataset.menuIcon === 'open') === open; });
    };
    toggle?.addEventListener('click', () => setPanel(panel.hidden));
    panel?.addEventListener('click', (e) => { if (e.target.closest('a')) { setPanel(false); } });
    // a tap on the dimmed page around the drawer closes it
    header.addEventListener('click', (e) => { if (e.target === header && panel && !panel.hidden) { setPanel(false); } });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && panel && !panel.hidden) { setPanel(false); toggle.focus(); } });
}

/*
 * ---------- "Show all" ----------
 * <ul data-more id="x"> with [data-more-item] children past the first few, and a
 * <button data-more-btn aria-controls="x" data-all="Show all" data-less="Show less">. Phones and tablets only (CSS).
 */
$$('[data-more-btn]').forEach((btn) => {
    const list = document.getElementById(btn.getAttribute('aria-controls'));
    if (!list) { return; }
    const label = $('[data-more-label]', btn) || btn;
    btn.addEventListener('click', () => {
        const all = !list.classList.contains('is-all');
        list.classList.toggle('is-all', all);
        btn.setAttribute('aria-expanded', String(all));
        label.textContent = all ? btn.dataset.less : btn.dataset.all;
        if (all) {
            $('[data-more-item] a, [data-more-item] summary, [data-more-item][href]', list)?.focus({ preventScroll: true });
        } else if (list.getBoundingClientRect().top < 0) {
            list.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'start' });
        }
    });
});

/* ---------- collapsible region: <button data-collapse-btn aria-controls="x"> + <div class="lp-collapse" id="x"> ---------- */
$$('[data-collapse-btn]').forEach((btn) => {
    const box = document.getElementById(btn.getAttribute('aria-controls'));
    if (!box) { return; }
    const label = $('[data-collapse-label]', btn) || btn;
    btn.addEventListener('click', () => {
        const open = !box.classList.contains('is-open');
        box.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', String(open));
        label.textContent = open ? btn.dataset.close : btn.dataset.open;
    });
});

/* ---------- real numbers count up once when they come into view ("1.4K+" counts 0 → 1.4 and keeps "K+") ---------- */
const counters = $$('[data-counter]');
if (counters.length && !still && 'IntersectionObserver' in window) {
    const countUp = (el) => {
        const m = el.textContent.trim().match(/^([\d.,]+)(.*)$/);
        if (!m) { return; }
        const target = Number(m[1].replace(/,/g, ''));
        const decimals = (m[1].split('.')[1] || '').length;
        const final = el.textContent;
        const start = performance.now();
        const step = (now) => {
            const t = Math.min(1, (now - start) / 900);
            const eased = 1 - (1 - t) ** 3;
            el.textContent = t < 1 ? (target * eased).toFixed(decimals) + m[2] : final;
            if (t < 1) { requestAnimationFrame(step); }
        };
        requestAnimationFrame(step);
    };
    const co = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
            if (!en.isIntersecting) { return; }
            co.unobserve(en.target);
            countUp(en.target);
        });
    }, { threshold: 0.6 });
    counters.forEach((el) => co.observe(el));
}

/* ---------- savings calculator on screen: the bottom bar steps aside for its own sticky total ---------- */
const calculator = $('[data-savings-calculator]');
if (calculator && 'IntersectionObserver' in window) {
    new IntersectionObserver(([en]) => {
        document.body.classList.toggle('lp-calc-on', en.isIntersecting);
    }).observe(calculator);
    const result = $('#calc-result', calculator);
    if (result) {
        new IntersectionObserver(([en]) => {
            calculator.classList.toggle('lp-result-on', en.isIntersecting);
        }, { threshold: 0.25 }).observe(result);
    }
}

/* ---------- plan details: open on wide screens, one tap away on phones ---------- */
if (window.matchMedia('(min-width: 1024px)').matches) {
    $$('details[data-open-wide]').forEach((d) => { d.open = true; });
}

/* ---------- pricing: a link to #compare opens the comparison ---------- */
const compare = $('details[data-compare]');
if (compare) {
    const openCompare = () => { if (location.hash === '#compare') { compare.open = true; } };
    openCompare();
    window.addEventListener('hashchange', openCompare);
}

/* ---------- scroll reveal ---------- */
if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
            if (!en.isIntersecting) { return; }
            en.target.classList.add('is-in');
            io.unobserve(en.target);
            // once in, hover and press transitions run without the stagger delay
            en.target.addEventListener('transitionend', () => en.target.classList.add('is-done'), { once: true });
        });
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.06 });
    $$('[data-reveal]').forEach((el) => io.observe(el));
} else {
    $$('[data-reveal]').forEach((el) => el.classList.add('is-in'));
}

/*
 * ---------- workflow scenes ----------
 * <figure data-scene> holds a stage ([data-stage], drawn at data-w × data-h and scaled to fit) and caption
 * buttons ([data-step] with data-ms). Stage elements declare when they appear:
 *   data-show="2"  only in step 2      data-show="2-"  from step 2 on      data-show="2-3"  steps 2 and 3
 *   data-on="..."  same ranges, but toggles the class is-on (style it with Tailwind's [&.is-on]: variant)
 * The player loops while the scene is on screen, pauses on hover / touch, and shows the last step when the
 * visitor prefers reduced motion.
 */
function range(v, n) {
    const [a, b] = String(v).split('-');
    return [Number(a), b === undefined ? Number(a) : (b === '' ? n : Number(b))];
}

function initScene(root) {
    const wrap = $('[data-stage-wrap]', root);
    const stage = $('[data-stage]', root);
    const caps = $$('[data-step]', root);
    const texts = $$('[data-step-text]', root);
    const n = caps.length;
    if (!stage || !n) { return; }
    const W = Number(stage.dataset.w) || 640;
    const H = Number(stage.dataset.h) || 400;
    const els = $$('[data-show], [data-on]', stage).map((el) => ({
        el,
        show: el.dataset.show !== undefined ? range(el.dataset.show, n) : null,
        on: el.dataset.on !== undefined ? range(el.dataset.on, n) : null,
    }));

    const fit = () => {
        wrap.style.aspectRatio = 'auto';
        const k = Math.min(1.4, wrap.clientWidth / W);
        stage.style.setProperty('--k', k.toFixed(4));
        wrap.style.height = `${Math.round(H * k)}px`;
    };
    fit();
    if ('ResizeObserver' in window) { new ResizeObserver(fit).observe(wrap); } else { window.addEventListener('resize', fit); }

    let at = 0;
    let timer = null;
    let started = 0;
    let left = 0;
    let paused = false;
    let visible = false;

    const render = (i) => {
        at = i;
        root.dataset.at = String(i);
        els.forEach(({ el, show, on }) => {
            if (show) { el.classList.toggle('is-shown', i >= show[0] && i <= show[1]); }
            if (on) { el.classList.toggle('is-on', i >= on[0] && i <= on[1]); }
        });
        caps.forEach((c, j) => {
            const k = j + 1;
            c.classList.toggle('is-active', k === i);
            c.classList.toggle('is-done', k < i || (still && k <= i));
            c.setAttribute('aria-current', k === i ? 'step' : 'false');
            c.style.setProperty('--ms', `${Number(c.dataset.ms) || 2600}ms`);
            // restart the progress bar animation
            const bar = $('i', c);
            if (bar && k === i) { bar.style.animation = 'none'; void bar.offsetWidth; bar.style.animation = ''; }
        });
        texts.forEach((t) => { t.hidden = Number(t.dataset.stepText) !== i; });
    };
    const schedule = (ms) => {
        clearTimeout(timer);
        started = performance.now();
        left = ms;
        timer = setTimeout(() => go(at % n + 1), ms);
    };
    const go = (i) => {
        render(i);
        if (!still && visible && !paused) { schedule(Number(caps[i - 1].dataset.ms) || 2600); }
    };
    const halt = () => {
        if (timer) { clearTimeout(timer); timer = null; left = Math.max(300, left - (performance.now() - started)); }
    };
    const resume = () => {
        if (still || paused || !visible || timer) { return; }
        if (at === 0) { go(1); } else { schedule(left || 1200); }
    };
    const setPaused = (p) => { paused = p; root.classList.toggle('is-paused', p); p ? halt() : resume(); };

    caps.forEach((c, j) => c.addEventListener('click', () => { halt(); timer = null; go(j + 1); }));
    if (still) { render(n); return; }
    render(1);
    root.classList.add('is-off');
    wrap.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') { setPaused(true); } });
    wrap.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') { setPaused(false); } });
    wrap.addEventListener('pointerup', (e) => { if (e.pointerType !== 'mouse') { setPaused(!paused); } });
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(([en]) => {
            visible = en.isIntersecting;
            root.classList.toggle('is-off', !visible);
            visible ? resume() : halt();
        }, { threshold: 0.35 }).observe(wrap);
    } else {
        visible = true;
        resume();
    }
}
$$('[data-scene]').forEach(initScene);

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

/* ---------- pricing: monthly / 6 / 12 months (paid plans only) ---------- */
const cycleButtons = $$('[data-cycle]');
function setCycle(months) {
    cycleButtons.forEach((b) => b.setAttribute('aria-pressed', String(Number(b.dataset.cycle) === months)));
    $$('[data-plan-price]').forEach((card) => {
        const monthly = Number(card.dataset.monthly) || 0;
        let disc = {};
        try { disc = JSON.parse(card.dataset.discounts || '{}'); } catch { /* none */ }
        const pct = Number(disc[String(months)] || 0);
        // Same rounding as Plan::priceFor(): the whole cycle is rounded to whole pounds.
        const cycleTotal = months === 1 ? monthly : Math.round(monthly * months * (1 - pct / 100));
        const perMonth = months === 1 ? monthly : Math.round(cycleTotal / months);
        const saved = nf.format(Math.round(monthly * months - cycleTotal));
        // data-show-total: the big figure is what the whole cycle costs (12,000 a year), not the monthly equivalent
        const showTotal = card.hasAttribute('data-show-total') && months > 1;
        const price = $('[data-price]', card);
        price.textContent = nf.format(showTotal ? cycleTotal : perMonth);
        if (!still) { price.classList.remove('lp-pop'); void price.offsetWidth; price.classList.add('lp-pop'); }
        $$('[data-monthly-only]', card).forEach((el) => { el.hidden = months > 1; });
        // a launch discount: the crossed-out regular price and its share follow the period shown
        const orig = $('[data-orig]', card);
        if (orig) {
            const regular = Number(orig.dataset.origMonth) * (showTotal ? months : 1);
            const shown = showTotal ? cycleTotal : perMonth;
            orig.textContent = nf.format(regular);
            const badge = $('[data-orig-pct]', card);
            if (badge && regular > 0) badge.textContent = badge.dataset.label.replace(':pct', Math.round((1 - shown / regular) * 100));
        }
        const unit = $('[data-unit]', card);
        if (unit) unit.textContent = (months === 1 ? unit.dataset.unitMonth : unit.dataset.unitCycle) || unit.textContent;
        $('[data-billed]', card).textContent = months === 1
            ? (T.billedMonthly || '')
            : (showTotal ? (T.billedTotal || ':month') : (T.billedEvery || ':total / :period'))
                .replace(':total', nf.format(cycleTotal))
                .replace(':month', nf.format(perMonth))
                .replace(':period', (T.periods || {})[months] || months)
                .replace(':saved', saved);
    });
    // any other amount that follows the switch (an extra branch a year): data-monthly, data-discounts, data-t1 / data-tn
    $$('[data-cycle-text]').forEach((el) => {
        const monthly = Number(el.dataset.monthly) || 0;
        let disc = {};
        try { disc = JSON.parse(el.dataset.discounts || '{}'); } catch { /* none */ }
        const pct = Number(disc[String(months)] || 0);
        const total = months === 1 ? monthly : Math.round(monthly * months * (1 - pct / 100));
        el.textContent = (months === 1 ? el.dataset.t1 : el.dataset.tn || el.dataset.t1 || '')
            .replace(':total', nf.format(total))
            .replace(':period', (T.periods || {})[months] || months);
    });
}
cycleButtons.forEach((b) => b.addEventListener('click', () => setCycle(Number(b.dataset.cycle))));

/*
 * ---------- home: the five-step walkthrough (landing/partials/home-walkthrough) ----------
 * [data-walk] holds step buttons ([data-walk-step="i"], in the list and under the device) and screens
 * ([data-walk-screen="i"]). It moves on every data-ms while it is on screen, and holds still under the pointer, while
 * a control has keyboard focus, and after the pause button. Reduced motion: it never moves by itself.
 */
$$('[data-walk]').forEach((root) => {
    const screens = $$('[data-walk-screen]', root);
    const buttons = $$('[data-walk-step]', root);
    const titles = $$('.hm-walk-step b', root).map((b) => b.textContent);
    const n = screens.length;
    const ms = Number(root.dataset.ms) || 4000;
    const bar = $('[data-walk-bar]', root);
    const toggle = $('[data-walk-toggle]', root);
    let at = 0;
    let timer = null;
    let left = ms;
    let started = 0;
    let paused = false;
    let hold = false;
    let visible = false;
    root.style.setProperty('--walk-ms', `${ms}ms`);

    const show = (i) => {
        at = i;
        buttons.forEach((b) => {
            const on = Number(b.dataset.walkStep) === i;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-current', on ? 'step' : 'false');
        });
        screens.forEach((s) => {
            const on = Number(s.dataset.walkScreen) === i;
            s.classList.toggle('is-on', on);
            if (on) { s.removeAttribute('aria-hidden'); } else { s.setAttribute('aria-hidden', 'true'); }
        });
        $('[data-walk-caption]', root).textContent = titles[i] || '';
        $('[data-walk-count]', root).textContent = String(i + 1).padStart(2, '0');
        if (bar) { bar.style.animation = 'none'; void bar.offsetWidth; bar.style.animation = ''; }
    };
    const stop = () => {
        if (timer) { clearTimeout(timer); timer = null; left = Math.max(250, left - (performance.now() - started)); }
        root.classList.remove('is-playing');
    };
    const play = () => {
        if (still || paused || hold || !visible || timer) { return; }
        root.classList.add('is-playing');
        started = performance.now();
        timer = setTimeout(() => { timer = null; left = ms; show((at + 1) % n); play(); }, left);
    };
    const go = (i) => { stop(); left = ms; show(i); play(); };

    buttons.forEach((b) => b.addEventListener('click', () => go(Number(b.dataset.walkStep))));
    if (still) { if (toggle) { toggle.hidden = true; } return; }
    toggle?.addEventListener('click', () => {
        paused = !paused;
        toggle.setAttribute('aria-pressed', String(paused));
        toggle.setAttribute('aria-label', paused ? toggle.dataset.play : toggle.dataset.pause);
        $('[data-icon="pause"]', toggle).hidden = paused;
        $('[data-icon="play"]', toggle).hidden = !paused;
        if (paused) { stop(); } else { play(); }
    });
    const setHold = (h) => { hold = h; if (h) { stop(); } else { play(); } };
    root.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') { setHold(true); } });
    root.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') { setHold(false); } });
    root.addEventListener('focusin', (e) => { if (e.target !== toggle && e.target.matches(':focus-visible')) { setHold(true); } });
    root.addEventListener('focusout', (e) => { if (!root.contains(e.relatedTarget)) { setHold(false); } });
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(([en]) => { visible = en.isIntersecting; if (visible) { play(); } else { stop(); } }, { threshold: 0.35 }).observe(root);
    } else {
        visible = true;
        play();
    }
});

/*
 * ---------- home: "everything your place needs" (landing/partials/home-systems) ----------
 * Pinned on wide, tall screens (CSS decides, with the same media query as here): the share of the section scrolled
 * picks the system, the last share turns the device into the cup. Phones: the card nearest the middle of the swiped
 * row. Elsewhere (short screens, reduced motion): the one tapped. In pinned mode a tap scrolls to that system's share.
 */
const systems = $('[data-systems]');
if (systems) {
    const items = $$('[data-sys-item]', systems);
    const n = items.length;
    const stage = $('.hm-sys-stage', systems);
    const shots = $$('[data-sys-screen]', systems);
    const chips = $$('[data-sys-chip] .hm-chip-in', systems);
    const progress = $('[data-sys-progress]', systems);
    const list = $('[data-sys-list]', systems);
    const pinned = window.matchMedia('(min-width: 1024px) and (min-height: 720px) and (prefers-reduced-motion: no-preference)');
    const narrow = window.matchMedia('(max-width: 1023px)');
    let cur = -1;
    let ticking = false;

    const set = (i, p) => {
        progress.style.setProperty('--p', String(p ?? (i + 1) / n));
        if (i === cur) { return; }
        cur = i;
        items.forEach((b, j) => { b.classList.toggle('is-on', j === i); b.setAttribute('aria-current', String(j === i)); });
        shots.forEach((s, j) => s.classList.toggle('is-on', j === Math.min(i, shots.length - 1)));
        chips.forEach((c, j) => c.classList.toggle('is-on', j === i));
        stage.classList.toggle('is-end', i === n - 1);
        // the screens are lazy: the next one starts loading before it is needed
        const next = shots[Math.min(i + 1, shots.length - 1)];
        if (next) { next.loading = 'eager'; }
    };
    const span = () => systems.offsetHeight - window.innerHeight;
    const fromScroll = () => {
        ticking = false;
        if (pinned.matches) {
            const p = Math.min(1, Math.max(0, -systems.getBoundingClientRect().top / Math.max(1, span())));
            set(Math.min(n - 1, Math.floor(p * n)), p);
        } else if (narrow.matches) {
            const mid = list.getBoundingClientRect().left + list.clientWidth / 2;
            let best = 0;
            let gap = Infinity;
            items.forEach((b, j) => {
                const r = b.getBoundingClientRect();
                const d = Math.abs(r.left + r.width / 2 - mid);
                if (d < gap) { gap = d; best = j; }
            });
            set(best);
        }
    };
    const queue = () => { if (!ticking) { ticking = true; requestAnimationFrame(fromScroll); } };
    window.addEventListener('scroll', queue, { passive: true });
    list.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue, { passive: true });
    items.forEach((b, j) => b.addEventListener('click', () => {
        if (pinned.matches) {
            const top = window.scrollY + systems.getBoundingClientRect().top + span() * ((j + 0.5) / n);
            window.scrollTo({ top, behavior: still ? 'auto' : 'smooth' });
        } else {
            set(j);
            if (narrow.matches) { b.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'nearest', inline: 'center' }); }
        }
    }));
    set(0);
    fromScroll();
}

/*
 * ---------- blog blocks (App\Support\PostBlocks, resources/views/components/blog) ----------
 * The server already drew each block as a worked example; here the inputs come alive.
 */
const CALCULATORS = {
    // the same formulas as App\Support\BlogCalculators::compute()
    packaging: (v) => {
        const total = v.box + v.sides + v.bag + v.seal + v.extras;
        return { total, share: v.price > 0 ? (total * 100) / v.price : null, priced: Math.ceil(+(v.price + total).toFixed(6) / 5) * 5, monthly: total * v.orders };
    },
    'food-cost': (v) => {
        const price = v.target > 0 ? v.cost / (v.target / 100) : null;
        const rounded = price === null ? null : Math.ceil(+price.toFixed(6) / 5) * 5;
        return { price, rounded, actual: rounded ? (v.cost * 100) / rounded : null, left: rounded === null ? null : rounded - v.cost - v.packaging };
    },
    'cake-price': (v) => {
        const cost = v.kilo * v.weight + v.decor + v.box;
        const price = v.target > 0 ? Math.ceil(+(cost / (v.target / 100)).toFixed(6) / 10) * 10 : null;
        return { cost, price, per_kilo: price !== null && v.weight > 0 ? price / v.weight : null };
    },
    'season-stock': (v) => {
        const expected = v.last * (1 + v.change / 100);
        return { expected: Math.ceil(+Math.max(0, expected).toFixed(6)), to_make: Math.max(0, Math.ceil(+(expected * (1 + v.safety / 100) - v.on_hand).toFixed(6))) };
    },
};
$$('[data-bcalc]').forEach((block) => {
    const formula = CALCULATORS[block.dataset.bcalc];
    if (!formula) { return; }
    const inputs = $$('[data-bcalc-inputs] input', block);
    const update = () => {
        const v = {};
        inputs.forEach((input) => {
            const n = parseFloat(String(input.value).replace(',', '.'));
            v[input.name] = Number.isFinite(n) ? (input.name === 'change' ? n : Math.max(0, n)) : 0;
        });
        const out = formula(v);
        $$('[data-out]', block).forEach((el) => {
            const value = out[el.dataset.out];
            el.textContent = value === null || value === undefined || !Number.isFinite(value)
                ? '—'
                : new Intl.NumberFormat('en-US', { maximumFractionDigits: +el.dataset.decimals || 0 }).format(value);
        });
    };
    $('[data-bcalc-inputs]', block).hidden = false;
    $('[data-bcalc-static]', block).hidden = true;
    $('[data-bcalc-hint]', block).hidden = false;
    inputs.forEach((input) => input.addEventListener('input', update));
    update();
});

$$('[data-bchecklist]').forEach((block) => {
    const key = 'eghub:blog-checklist:' + block.dataset.bchecklist;
    const boxes = $$('input[type="checkbox"]', block);
    const progress = $('[data-bchecklist-progress]', block);
    const bar = $('[data-bchecklist-bar]', block);
    const read = () => { try { return JSON.parse(localStorage.getItem(key) || '[]'); } catch { return []; } };
    const save = () => {
        const ticked = boxes.filter((box) => box.checked).map((box) => +box.dataset.index);
        try { if (ticked.length) { localStorage.setItem(key, JSON.stringify(ticked)); } else { localStorage.removeItem(key); } } catch { /* private mode: the ticks just do not persist */ }
    };
    const show = () => {
        const done = boxes.filter((box) => box.checked).length;
        progress.textContent = done === boxes.length ? 'خلّصت الـ ' + boxes.length + ' بند' : 'خلّصت ' + done + ' من ' + boxes.length;
        bar.firstElementChild.style.setProperty('--p', Math.round((done / boxes.length) * 100) + '%');
    };
    const saved = read();
    boxes.forEach((box) => {
        box.checked = Array.isArray(saved) && saved.includes(+box.dataset.index);
        box.addEventListener('change', () => { save(); show(); });
    });
    bar.hidden = false;
    $('[data-bchecklist-actions]', block).hidden = false;
    $('[data-bchecklist-reset]', block).addEventListener('click', () => { boxes.forEach((box) => { box.checked = false; }); save(); show(); });
    $('[data-bchecklist-print]', block).addEventListener('click', () => {
        const done = () => { block.classList.remove('is-printing'); document.body.classList.remove('lp-print-one'); };
        block.classList.add('is-printing');
        document.body.classList.add('lp-print-one');
        window.addEventListener('afterprint', done, { once: true });
        window.print();
    });
    show();
});

$$('[data-bquiz]').forEach((block) => {
    const questions = $$('[data-bquiz-q]', block);
    const score = $('[data-bquiz-score]', block);
    questions.forEach((q) => {
        const answer = $('[data-bquiz-answer]', q);
        const feedback = $('[data-bquiz-feedback]', q);
        answer.hidden = true;
        $$('input[type="radio"]', q).forEach((radio) => radio.addEventListener('change', () => {
            const right = radio.value === '1';
            $$('label', q).forEach((label) => label.classList.remove('is-right', 'is-wrong'));
            radio.closest('label').classList.add(right ? 'is-right' : 'is-wrong');
            if (!right) { $('input[value="1"]', q)?.closest('label').classList.add('is-right'); }
            q.dataset.right = right ? '1' : '0';
            feedback.className = 'lp-quiz-feedback ' + (right ? 'is-right' : 'is-wrong');
            feedback.replaceChildren(right ? 'صح. ' : 'مش دي. الصح: ', ...$('p', answer).cloneNode(true).childNodes);
            feedback.hidden = false;
            const answered = questions.filter((x) => x.dataset.right !== undefined);
            if (answered.length === questions.length) {
                const n = questions.filter((x) => x.dataset.right === '1').length;
                score.textContent = n === questions.length
                    ? 'جاوبت صح على الكل: ' + n + ' من ' + n + '.'
                    : 'جاوبت صح على ' + n + ' من ' + questions.length + '. ارجع للجزء اللي فيه الإجابة اللي فاتتك.';
                score.hidden = false;
            }
        }));
    });
});

$$('[data-bpicker]').forEach((block) => {
    let answers = [];
    let map = {};
    let typical = {};
    try { answers = JSON.parse(block.dataset.answers); map = JSON.parse(block.dataset.map); typical = JSON.parse(block.dataset.typical || '{}'); } catch { return; }
    const result = $('[data-bpicker-result]', block);
    const select = (name) => $('select[name="' + name + '"]', block);
    // a new food starts from how it usually travels; the reader can still change both
    select('food').addEventListener('change', () => {
        const [temp, texture] = typical[select('food').value] || [];
        if (temp) { select('temp').value = temp; select('texture').value = texture; }
    });
    const update = () => {
        const key = ['food', 'temp', 'texture'].map((name) => $('select[name="' + name + '"]', block).value).join('|');
        const answer = answers[map[key]];
        if (!answer) { return; }
        ['box', 'reason', 'tip'].forEach((field) => { $('[data-field="' + field + '"]', result).textContent = answer[field]; });
    };
    $('[data-bpicker-form]', block).hidden = false;
    result.hidden = false;
    $('[data-bpicker-table]', block).open = false;
    $$('select', block).forEach((select) => select.addEventListener('change', update));
    update();
});
