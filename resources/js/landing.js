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
        toggle.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('lp-locked', open);
        $$('[data-menu-icon]', toggle).forEach((i) => { i.hidden = (i.dataset.menuIcon === 'open') === open; });
    };
    toggle?.addEventListener('click', () => setPanel(panel.hidden));
    panel?.addEventListener('click', (e) => { if (e.target.closest('a')) { setPanel(false); } });
}

/* ---------- plan details: open on wide screens, one tap away on phones ---------- */
if (window.matchMedia('(min-width: 1024px)').matches) {
    $$('details[data-open-wide]').forEach((d) => { d.open = true; });
}

/* ---------- scroll reveal ---------- */
if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
            if (!en.isIntersecting) { return; }
            en.target.classList.add('is-in');
            io.unobserve(en.target);
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
        const price = $('[data-price]', card);
        price.textContent = nf.format(perMonth);
        if (!still) { price.classList.remove('lp-pop'); void price.offsetWidth; price.classList.add('lp-pop'); }
        $('[data-billed]', card).textContent = months === 1
            ? (T.billedMonthly || '')
            : (T.billedEvery || ':total / :period')
                .replace(':total', nf.format(cycleTotal))
                .replace(':period', (T.periods || {})[months] || months)
                .replace(':saved', nf.format(Math.round(monthly * months - cycleTotal)));
    });
}
cycleButtons.forEach((b) => b.addEventListener('click', () => setCycle(Number(b.dataset.cycle))));
