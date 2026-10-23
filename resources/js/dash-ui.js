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

/*
 * Desktop, inside an app: the slim rail ([data-dash-rail]) or the full sidebar. The chevron on either one switches,
 * and the browser remembers the choice (the layout reads it back before the first paint).
 */
const dashApp = document.querySelector('[data-dash-app]');
const railToggles = document.querySelectorAll('[data-dash-rail-toggle]');
const EXPANDED_KEY = 'dash-nav-expanded';

function setExpanded(expanded) {
    dashApp.toggleAttribute('data-nav-expanded', expanded);
    railToggles.forEach((t) => t.setAttribute('aria-expanded', expanded ? 'true' : 'false'));
    try {
        localStorage.setItem(EXPANDED_KEY, expanded ? '1' : '0');
    } catch {
        // private window or blocked storage: the choice lasts for this page only
    }
}

if (dashApp && railToggles.length) {
    railToggles.forEach((t) => t.setAttribute('aria-expanded', dashApp.hasAttribute('data-nav-expanded') ? 'true' : 'false'));
    railToggles.forEach((t) => t.addEventListener('click', () => {
        const expanded = !dashApp.hasAttribute('data-nav-expanded');
        setExpanded(expanded);
        // keep the focus on the chevron of the menu that is now showing
        [...railToggles].find((other) => other !== t)?.focus();
    }));
}

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

/*
 * Toasts: the flash "ok" message (components/flash.blade.php with :toast) and window.dashToast(message, {kind, action})
 * for scripts (inline price edit). Each one hides by itself; hovering or focusing it keeps it open.
 */
const toastBox = document.querySelector('[data-dash-toasts]');

function dismissToast(toast) {
    if (!toast.isConnected || toast.hasAttribute('data-leaving')) {
        return;
    }
    toast.setAttribute('data-leaving', '');
    setTimeout(() => toast.remove(), 260);
}

function armToast(toast, ms = 4500) {
    let timer = setTimeout(() => dismissToast(toast), ms);
    const hold = () => clearTimeout(timer);
    const release = () => {
        clearTimeout(timer);
        timer = setTimeout(() => dismissToast(toast), 2000);
    };
    toast.addEventListener('mouseenter', hold);
    toast.addEventListener('focusin', hold);
    toast.addEventListener('mouseleave', release);
    toast.addEventListener('focusout', release);
    toast.querySelector('[data-toast-close]')?.addEventListener('click', () => dismissToast(toast));
}

/** Shows a toast; kind 'ok' | 'error'; action {label, href} adds a link. */
function dashToast(message, { kind = 'ok', action = null } = {}) {
    if (!toastBox) {
        return;
    }
    const toast = document.createElement('div');
    toast.className = 'dash-toast';
    toast.dataset.kind = kind;
    toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    const text = document.createElement('span');
    text.textContent = message;
    toast.append(text);
    if (action) {
        const link = document.createElement('a');
        link.href = action.href;
        link.textContent = action.label;
        link.className = 'shrink-0';
        toast.append(link);
    }
    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('data-toast-close', '');
    close.setAttribute('aria-label', toastBox.dataset.closeLabel || 'Close');
    close.textContent = '×';
    toast.append(close);
    toastBox.append(toast);
    armToast(toast, kind === 'error' ? 7000 : 3500);
}

window.dashToast = dashToast;
toastBox?.querySelectorAll('[data-flash-toast]').forEach((toast) => armToast(toast, toast.querySelector('[data-flash-action]') ? 8000 : 4500));

/*
 * Small dropdowns ([data-dropdown] > [data-dropdown-toggle] + [data-dropdown-panel]), e.g. "More" on the menu page:
 * closes on an outside click, Escape (focus back on the button) or picking a link; arrow keys move between links.
 */
document.querySelectorAll('[data-dropdown]').forEach((box) => {
    const button = box.querySelector('[data-dropdown-toggle]');
    const panel = box.querySelector('[data-dropdown-panel]');
    if (!button || !panel) {
        return;
    }
    const links = () => [...panel.querySelectorAll('a[href], button:not([disabled])')];
    const setOpen = (open, focusFirst = false) => {
        panel.classList.toggle('hidden', !open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && focusFirst) {
            links()[0]?.focus();
        }
    };
    button.addEventListener('click', () => setOpen(panel.classList.contains('hidden')));
    button.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setOpen(true, true);
        }
    });
    panel.addEventListener('keydown', (e) => {
        const all = links();
        const at = all.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            all[(at + (e.key === 'ArrowDown' ? 1 : all.length - 1)) % all.length]?.focus();
        }
    });
    panel.addEventListener('click', (e) => e.target.closest('a') && setOpen(false));
    document.addEventListener('click', (e) => !box.contains(e.target) && setOpen(false));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.classList.contains('hidden')) {
            setOpen(false);
            button.focus();
        }
    });
});

/*
 * Unsaved changes ([data-unsaved-guard] forms: settings, item form): after the first edit, leaving the page
 * (closing the tab, a link, another form such as log out) asks first. Submitting the form itself never asks.
 * [data-unsaved-note] inside the form shows while there are changes.
 */
const guarded = document.querySelector('form[data-unsaved-guard]');
if (guarded) {
    const leaveMessage = guarded.dataset.unsavedGuard || 'Leave without saving?';
    let dirty = false;
    let leaving = false;
    const markDirty = () => {
        if (dirty) {
            return;
        }
        dirty = true;
        guarded.querySelectorAll('[data-unsaved-note]').forEach((n) => n.removeAttribute('hidden'));
    };
    guarded.addEventListener('input', (e) => e.isTrusted && markDirty());
    guarded.addEventListener('change', (e) => e.isTrusted && markDirty());
    // adding / removing rows (sizes & extras) changes the form too
    guarded.addEventListener('click', (e) => e.isTrusted && e.target.closest('button[type="button"]') && markDirty());
    guarded.addEventListener('submit', () => {
        leaving = true;
    });
    window.addEventListener('beforeunload', (e) => {
        if (dirty && !leaving) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[href]');
        if (!dirty || leaving || !link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        const url = new URL(link.href, location.href);
        if (link.target === '_blank' || link.hasAttribute('download') || (url.pathname === location.pathname && url.search === location.search && url.hash)) {
            return;
        }
        if (confirm(leaveMessage)) {
            leaving = true; // already asked; the browser must not ask again
        } else {
            e.preventDefault();
        }
    });
    document.addEventListener('submit', (e) => {
        if (e.target === guarded || e.target.hasAttribute('onsubmit') || !dirty || leaving) {
            return;
        }
        if (confirm(leaveMessage)) {
            leaving = true;
        } else {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);
}

/* Ctrl/Cmd+S saves the page's main form ([data-save-shortcut]) through its main button, with the usual checks. */
const shortcutForm = document.querySelector('form[data-save-shortcut]');
if (shortcutForm) {
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 's') {
            e.preventDefault();
            const primary = shortcutForm.querySelector('[data-primary-save]');
            primary ? shortcutForm.requestSubmit(primary) : shortcutForm.requestSubmit();
        }
    });
}
