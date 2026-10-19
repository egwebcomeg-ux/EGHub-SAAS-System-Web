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
    const onScroll = () => {
        header.classList.toggle('is-compact', window.scrollY > 8);
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
