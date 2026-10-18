// Restaurant dashboard chrome (layouts/dashboard.blade.php): the sidebar drawer on phones/tablets, and the column
// titles each table cell shows when tables turn into stacked cards on phones (see resources/css/app.css).

const nav = document.querySelector('[data-dash-nav]');
const backdrop = document.querySelector('[data-dash-nav-backdrop]');
const toggles = document.querySelectorAll('[data-dash-nav-toggle]');

function setNav(open) {
    if (!nav) {
        return;
    }
    nav.toggleAttribute('data-open', open);
    backdrop?.toggleAttribute('data-open', open);
    document.documentElement.classList.toggle('dash-nav-lock', open);
    toggles.forEach((t) => t.setAttribute('aria-expanded', open ? 'true' : 'false'));
    if (open) {
        (nav.querySelector('[aria-current="page"]') || nav.querySelector('a, button'))?.focus({ preventScroll: true });
        nav.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'center' });
    }
}

toggles.forEach((t) => t.addEventListener('click', () => setNav(!nav.hasAttribute('data-open'))));
backdrop?.addEventListener('click', () => setNav(false));
nav?.querySelectorAll('[data-dash-nav-close]').forEach((b) => b.addEventListener('click', () => {
    setNav(false);
    toggles[0]?.focus();
}));
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && nav?.hasAttribute('data-open')) {
        setNav(false);
        toggles[0]?.focus();
    }
});
window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => e.matches && setNav(false));

/** Copies each column title into its cells (data-label) so a phone shows "Title: value" rows. */
function labelTable(table) {
    const head = table.tHead?.rows[table.tHead.rows.length - 1];
    if (!head) {
        return;
    }
    const titles = [];
    for (const cell of head.cells) {
        const text = cell.textContent.trim().replace(/\s+/g, ' ');
        for (let i = 0; i < (cell.colSpan || 1); i++) {
            titles.push(text);
        }
    }
    for (const body of [...table.tBodies, ...(table.tFoot ? [table.tFoot] : [])]) {
        for (const row of body.rows) {
            let col = 0;
            for (const cell of row.cells) {
                if (cell.tagName === 'TD' && !cell.hasAttribute('data-label') && titles[col] && (cell.colSpan || 1) === 1) {
                    cell.setAttribute('data-label', titles[col]);
                }
                col += cell.colSpan || 1;
            }
        }
    }
}

function labelTables(root = document) {
    root.querySelectorAll('main table.table-base:not([data-table-scroll])').forEach(labelTable);
}

labelTables();
// rows added later (live lists) get their labels too
const main = document.querySelector('main');
if (main) {
    new MutationObserver((changes) => {
        if (changes.some((c) => [...c.addedNodes].some((n) => n.nodeType === 1 && (n.matches('tr, table') || n.querySelector?.('tr'))))) {
            labelTables();
        }
    }).observe(main, { childList: true, subtree: true });
}
