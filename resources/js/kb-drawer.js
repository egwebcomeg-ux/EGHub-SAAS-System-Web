// The knowledge-base drawer of the staff screens (dashboard/partials/kb-drawer.blade.php): the call center, the POS
// and the order board. The answers load once from the feed when the drawer first opens (again after FRESH_MS), and
// the search runs in the browser: Arabic short vowels, tatweel and the alef / taa marbuta / yaa forms never decide a
// match, and every word typed must be found. Each answer opens in place with a button that copies it (for WhatsApp).

const FRESH_MS = 5 * 60 * 1000;

const drawer = document.querySelector('[data-kb-drawer]');

if (drawer) {
    const T = JSON.parse(drawer.dataset.kbI18n || '{}');
    const input = drawer.querySelector('[data-kb-q]');
    const list = drawer.querySelector('[data-kb-list]');
    const cats = drawer.querySelector('[data-kb-cats]');
    const counter = drawer.querySelector('[data-kb-count]');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fold = (text) => String(text ?? '').toLowerCase().normalize('NFKD')
        .replace(/[ً-ْـ̀-ͯ]/g, '')
        .replace(/[آأإ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي');

    let data = null;
    let loadedAt = 0;
    let loading = null;
    let category = 'all';

    async function load(force = false) {
        if (data && !force && Date.now() - loadedAt < FRESH_MS) {
            return;
        }
        if (!data) {
            list.innerHTML = `<p class="py-6 text-center text-sm text-navy-500">${esc(T.loading)}</p>`;
        }
        loading ??= fetch(drawer.dataset.kbUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((r) => {
                if (!r.ok) {
                    throw new Error(String(r.status));
                }
                return r.json();
            })
            .then((json) => {
                data = {
                    categories: json.categories || [],
                    articles: (json.articles || []).map((a) => ({
                        ...a,
                        q: fold(a.question),
                        tagsFolded: fold(`${a.tags} ${a.category ?? ''}`),
                        hay: fold(`${a.question} ${a.tags} ${a.category ?? ''} ${a.answer}`),
                    })),
                };
                loadedAt = Date.now();
            })
            .catch(() => {
                if (!data) {
                    list.innerHTML = `<div class="py-6 text-center text-sm text-red-700">${esc(T.failed)}
                        <button type="button" class="btn-ghost mt-2 min-h-9 px-3" data-kb-retry>${esc(T.retry)}</button></div>`;
                }
            })
            .finally(() => {
                loading = null;
            });
        await loading;
        renderCats();
        render();
    }

    function renderCats() {
        if (!data) {
            return;
        }
        const chips = [{ id: 'all', name: T.all }, ...data.categories];
        cats.hidden = data.categories.length === 0;
        cats.innerHTML = chips.map((c) => {
            const on = String(c.id) === String(category);
            return `<button type="button" class="tab-pill min-h-9 shrink-0 ${on ? 'bg-navy-900 text-white' : 'bg-white text-navy-700 ring-1 ring-navy-100'}"
                data-kb-cat="${esc(c.id)}" aria-pressed="${on}">${esc(c.name)}</button>`;
        }).join('');
    }

    function matches() {
        const words = fold(input.value.trim()).split(/\s+/).filter(Boolean);
        const pool = data.articles.filter((a) => category === 'all' || String(a.category_id) === String(category));
        if (words.length === 0) {
            return pool;
        }
        return pool
            .filter((a) => words.every((w) => a.hay.includes(w)))
            .map((a, i) => ({ a, i, score: words.reduce((s, w) => s + (a.q.includes(w) ? 2 : 0) + (a.tagsFolded.includes(w) ? 1 : 0), 0) }))
            .sort((x, y) => y.score - x.score || x.i - y.i)
            .map((x) => x.a);
    }

    function render() {
        if (!data) {
            return;
        }
        if (data.articles.length === 0) {
            list.innerHTML = `<p class="py-6 text-center text-sm text-navy-500">${esc(T.none)}</p>`;
            counter.textContent = '';
            return;
        }
        const found = matches();
        counter.textContent = `· ${T.count.replace('__N__', found.length)}`;
        if (found.length === 0) {
            list.innerHTML = `<p class="py-6 text-center text-sm text-navy-500">${esc(T.noMatch)}</p>`;
            return;
        }
        const single = found.length === 1;
        list.innerHTML = found.map((a) => `
            <details class="rounded-2xl border border-navy-100 bg-white open:border-brand-300 open:shadow-sm" data-kb-item="${a.id}" ${single ? 'open' : ''}>
                <summary class="flex min-h-11 cursor-pointer list-none items-start gap-2 px-3 py-2.5 font-bold [&::-webkit-details-marker]:hidden">
                    <span class="min-w-0 flex-1" dir="auto">${esc(a.question)}</span>
                    <span class="badge shrink-0 ${a.public ? 'bg-emerald-100 text-emerald-800' : 'bg-navy-100 text-navy-700'}" title="${esc(a.public ? T.public : T.internal)}">${a.public ? '🌐' : '🔒'}</span>
                </summary>
                <div class="px-3 pb-3">
                    <div class="text-sm leading-relaxed text-navy-700 [&_a]:font-semibold [&_a]:text-brand-700 [&_a]:underline" dir="auto">${a.html}</div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" class="btn-ghost min-h-9 px-3 text-sm" data-kb-copy="${a.id}">📋 ${esc(T.copy)}</button>
                        ${a.category ? `<span class="text-xs text-navy-400">${esc(a.category)}</span>` : ''}
                        <span class="text-xs text-navy-400">${esc(a.public ? T.public : T.internal)}</span>
                    </div>
                </div>
            </details>`).join('');
    }

    async function copy(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // http on a local network: no Clipboard API, the old way still works
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            drawer.appendChild(area);
            area.select();
            const ok = document.execCommand('copy');
            area.remove();
            return ok;
        }
    }

    function open() {
        if (!drawer.open) {
            drawer.showModal();
        }
        input.focus();
        input.select();
        load();
    }

    document.querySelectorAll('[data-kb-open]').forEach((btn) => btn.addEventListener('click', open));
    input.addEventListener('input', render);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            list.querySelector('details')?.setAttribute('open', '');
        }
    });
    cats.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-kb-cat]');
        if (chip) {
            category = chip.dataset.kbCat;
            renderCats();
            render();
        }
    });
    list.addEventListener('click', async (e) => {
        if (e.target.closest('[data-kb-retry]')) {
            load(true);
            return;
        }
        const btn = e.target.closest('[data-kb-copy]');
        if (!btn) {
            return;
        }
        const article = data?.articles.find((a) => String(a.id) === btn.dataset.kbCopy);
        if (article && await copy(article.answer)) {
            const label = btn.innerHTML;
            btn.textContent = T.copied;
            setTimeout(() => { btn.innerHTML = label; }, 1500);
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'F1') {
            return;
        }
        e.preventDefault();
        const other = [...document.querySelectorAll('dialog')].some((d) => d.open && d !== drawer);
        if (drawer.open) {
            drawer.close();
        } else if (!other) {
            open();
        }
    });
}
