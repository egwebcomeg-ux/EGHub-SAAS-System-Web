// POS offline mode (no libraries): the device's terminal (localStorage), the branch catalog and the sales made while
// the internet was down (IndexedDB), the online / offline state, and the sync of those sales once it is back (oldest
// first, one by one, idempotent by client_uuid on the server). Also saves the POS page itself for the service worker.

const SHELL = 'egmenu-pos-shell-v1';
const KEEP_SYNCED_MS = 2 * 86400000; // synced sales stay in the log for two days

export class NetworkError extends Error {}
export class HttpError extends Error {
    constructor(message, status, data) {
        super(message);
        this.status = status;
        this.data = data;
    }
}

function idb(name) {
    let opening = null;
    const open = () => (opening ??= new Promise((resolve, reject) => {
        const req = indexedDB.open(name, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            db.createObjectStore('kv');
            db.createObjectStore('queue', { keyPath: 'client_uuid' });
            db.createObjectStore('holds', { keyPath: 'id' });
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    }));
    const run = async (store, mode, fn) => {
        const db = await open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(store, mode);
            const out = fn(tx.objectStore(store));
            tx.oncomplete = () => resolve(out?.result ?? out);
            tx.onerror = () => reject(tx.error);
        });
    };
    return {
        get: (store, key) => run(store, 'readonly', (s) => s.get(key)),
        put: (store, value, key) => run(store, 'readwrite', (s) => (key === undefined ? s.put(value) : s.put(value, key))),
        del: (store, key) => run(store, 'readwrite', (s) => s.delete(key)),
        all: (store) => run(store, 'readonly', (s) => s.getAll()),
    };
}

/**
 * @param {object} D the POS data of the page (tenant, user, branchId, shift, offlineHours, urls…)
 * @param {{csrf: string}} session holds the CSRF token (refreshed from every ping / catalog)
 */
export function createOffline(D, session) {
    const tenant = D.tenant;
    const db = 'indexedDB' in window ? idb('egmenu-pos-' + tenant) : null;
    const listeners = new Set();
    const ls = {
        get(key, fallback = null) { try { const v = localStorage.getItem(key); return v === null ? fallback : JSON.parse(v); } catch { return fallback; } },
        set(key, value) { try { value === null ? localStorage.removeItem(key) : localStorage.setItem(key, JSON.stringify(value)); } catch {} },
    };
    const TERMINAL = 'egmenu.pos.terminal.' + tenant;
    const SINCE = 'egmenu.pos.offline-since.' + tenant;
    let online = navigator.onLine !== false;
    let syncing = false;
    let log = []; // queue entries, newest first

    const emit = () => listeners.forEach((fn) => fn(api));

    // ---- requests: a network failure (offline) is told apart from a refusal of the server ----
    async function request(url, { method = 'GET', body } = {}) {
        const t = terminal();
        let res;
        try {
            res = await fetch(url, {
                method, credentials: 'same-origin',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': session.csrf,
                    ...(t ? { 'X-POS-Terminal': String(t.id), 'X-POS-Terminal-Token': t.token } : {}),
                },
                body: body ? JSON.stringify(body) : undefined,
            });
        } catch {
            setOnline(false);
            throw new NetworkError('offline');
        }
        const data = await res.json().catch(() => ({}));
        setOnline(true);
        if (data && data.csrf) session.csrf = data.csrf;
        if (!res.ok) throw new HttpError(data.message || (data.errors ? Object.values(data.errors)[0][0] : String(res.status)), res.status, data);
        return data;
    }

    function setOnline(value) {
        if (value === online) return;
        online = value;
        if (!value && !ls.get(SINCE)) ls.set(SINCE, new Date().toISOString());
        emit();
        if (value) sync();
    }

    // ---- the terminal this device holds ----
    function terminal() {
        const t = ls.get(TERMINAL);
        return t && t.id && t.token ? t : null;
    }
    function setTerminal(t) {
        ls.set(TERMINAL, t ? { id: t.id, name: t.name, code: t.code, token: t.token } : null);
        if (t) {
            // a device that was reset goes on after the highest number the server already has
            const key = seqKey(t.id);
            ls.set(key, Math.max(Number(ls.get(key, 0)) || 0, Number(t.seq) || 0));
        }
        emit();
    }
    const seqKey = (id) => 'egmenu.pos.seq.' + tenant + '.' + id;
    function nextRef() {
        const t = terminal();
        const n = (Number(ls.get(seqKey(t.id), 0)) || 0) + 1;
        ls.set(seqKey(t.id), n);
        return `OFF-${t.code}-${n}`;
    }

    // ---- may this device sell offline right now? ----
    function offlineSince() {
        return ls.get(SINCE);
    }
    /** @return {string|null} why an offline sale is not possible (null = it is) */
    function blockReason(shift) {
        const t = terminal();
        if (!db) return 'nodb';
        if (!t) return 'terminal';
        if (!shift) return 'shift';
        if (shift.terminal && Number(shift.terminal) !== Number(t.id)) return 'shiftTerminal';
        if (!shift.terminal) return 'shiftTerminal';
        const since = offlineSince();
        if (since && Date.now() - new Date(since).getTime() > D.offlineHours * 3600000) return 'limit';
        return null;
    }

    // ---- the catalog kept for offline ----
    const catalogKey = () => 'catalog:' + D.branchId;
    async function saveCatalog(catalog) {
        if (db) await db.put('kv', catalog, catalogKey()).catch(() => null);
    }
    async function loadCatalog() {
        return db ? db.get('kv', catalogKey()).catch(() => null) : null;
    }
    async function refreshCatalog() {
        const c = await request(D.urls.catalog);
        await saveCatalog(c);
        return c;
    }

    // ---- the POS page itself, for the service worker to open it offline ----
    async function saveShell() {
        if (!('caches' in window)) return;
        try {
            const cache = await caches.open(SHELL);
            const assets = [...document.querySelectorAll('script[src], link[rel="stylesheet"][href], link[rel="modulepreload"][href]')]
                .map((el) => el.src || el.href).filter((u) => u.startsWith(location.origin));
            await cache.addAll([new Request(location.pathname, { credentials: 'same-origin' }), ...new Set(assets)]);
        } catch {}
    }

    // ---- the queue of offline sales ----
    async function refreshLog() {
        if (!db) return;
        const all = await db.all('queue').catch(() => []);
        const now = Date.now();
        for (const e of all) {
            if (e.status === 'synced' && now - new Date(e.synced_at).getTime() > KEEP_SYNCED_MS) await db.del('queue', e.client_uuid).catch(() => null);
        }
        log = all.filter((e) => !(e.status === 'synced' && now - new Date(e.synced_at).getTime() > KEEP_SYNCED_MS))
            .sort((a, b) => (a.created_offline_at < b.created_offline_at ? 1 : -1));
        emit();
    }
    async function queue(sale) {
        const entry = { ...sale, status: 'pending', attempts: 0, error: null, queued_at: new Date().toISOString() };
        await db.put('queue', entry);
        await refreshLog();
        return entry;
    }
    const pending = () => log.filter((e) => e.status === 'pending');

    async function update(entry, changes) {
        Object.assign(entry, changes);
        await db.put('queue', entry);
    }

    /** Sends the waiting sales, oldest first; stops at the first network failure. */
    async function sync() {
        if (syncing || !db) return;
        syncing = true;
        emit();
        try {
            await refreshLog();
            const todo = pending().slice().reverse();
            for (const entry of todo) {
                try {
                    const r = await request(D.urls.sync, { method: 'POST', body: entry.payload });
                    await update(entry, { status: 'synced', number: r.number, order_id: r.id, review: !!r.review, synced_at: new Date().toISOString(), error: null });
                } catch (e) {
                    if (e instanceof NetworkError) break;
                    if (e.status === 419 || e.status === 401) {
                        // the session token changed (or the session ended): get a fresh one and try again later
                        await update(entry, { attempts: entry.attempts + 1, error: e.message });
                        if (e.status === 419) await ping();
                        break;
                    }
                    await update(entry, { status: e.data && e.data.refused ? 'refused' : 'pending', attempts: entry.attempts + 1, error: e.message });
                }
            }
            if (!pending().length && online) ls.set(SINCE, null);
        } finally {
            syncing = false;
            await refreshLog();
        }
    }
    async function retry(uuid) {
        const entry = log.find((e) => e.client_uuid === uuid);
        if (!entry) return;
        await update(entry, { status: 'pending', error: null });
        await refreshLog();
        sync();
    }
    async function resolve(uuid, note) {
        const entry = log.find((e) => e.client_uuid === uuid);
        if (!entry) return;
        await update(entry, { status: 'resolved', resolved_at: new Date().toISOString(), resolved_note: note || '' });
        await refreshLog();
    }

    // ---- held tickets kept on the device while offline ----
    const localHolds = () => (db ? db.all('holds').catch(() => []) : Promise.resolve([]));
    const saveLocalHold = (hold) => db?.put('holds', hold);
    const deleteLocalHold = (id) => db?.del('holds', id);

    async function ping() {
        try {
            await request(D.urls.ping);
            return true;
        } catch {
            return false;
        }
    }

    // checks every 15 s while offline, every minute online; the browser's own online / offline events too
    let timer = null;
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            if (await ping()) sync();
            schedule();
        }, online ? 60000 : 15000);
    };
    window.addEventListener('online', () => ping().then((ok) => ok && sync()));
    window.addEventListener('offline', () => setOnline(false));

    const api = {
        get online() { return online; },
        get syncing() { return syncing; },
        get log() { return log; },
        get available() { return !!db; },
        pending, terminal, setTerminal, nextRef, offlineSince, blockReason, request, ping, sync, retry, resolve, queue,
        saveCatalog, loadCatalog, refreshCatalog, saveShell, refreshLog, localHolds, saveLocalHold, deleteLocalHold,
        onChange(fn) { listeners.add(fn); },
        start() {
            refreshLog();
            schedule();
            if (online) sync();
        },
    };
    return api;
}

/** An 80mm receipt (or kitchen ticket) printed from the device, for a sale that has no number yet. */
export function printLocalReceipt(sale, header, T, kitchen = false) {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const m = (n) => String(Math.round(n * 100) / 100);
    const p = sale.payload;
    const lines = p.items.map((l) => `<tr><td><b>${l.qty}×</b> ${esc(l.name)}${l.options?.length ? `<div class="opt">${esc(l.options.map((o) => o.name).join('، '))}</div>` : ''}${l.notes ? `<div class="opt">⚠ ${esc(l.notes)}</div>` : ''}</td>${kitchen ? '' : `<td class="n">${m(l.unit_price * l.qty)}</td>`}</tr>`).join('');
    const row = (label, v) => `<tr><td>${esc(label)}</td><td class="n">${esc(v)}</td></tr>`;
    const totals = kitchen ? '' : `<hr><table>
        ${p.discount > 0 || p.service > 0 || p.tax > 0 ? row(T.subtotal, m(p.subtotal)) : ''}
        ${p.discount > 0 ? row(T.discount, '−' + m(p.discount)) : ''}${p.service > 0 ? row(T.service, m(p.service)) : ''}${p.tax > 0 ? row(T.tax, m(p.tax)) : ''}
        <tr class="tot"><td>${esc(T.total)}</td><td class="n">${m(p.total)} ${esc(header.currency)}</td></tr>
        ${(p.payments || [{ method: p.method, amount: p.total, tendered: p.tendered }]).map((x) => row(T.methods[x.method] || x.method, m(x.amount))
            + (x.tendered > x.amount ? row(T.changeLabel, m(x.tendered - x.amount)) : '')).join('')}
        </table><div class="c muted">${esc(T.offlineReceiptNote)}</div><hr><div class="c">${esc(T.thanks)}</div><div class="c muted">${esc(header.url)}</div>`;
    const when = new Date(p.created_offline_at).toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' });
    const html = `<!doctype html><html dir="rtl"><head><meta charset="utf-8"><title>${esc(p.offline_ref)}</title><style>
        @page { size: 80mm auto; margin: 3mm; } body { width: 74mm; margin: 0 auto; font-family: 'Cairo', Tahoma, sans-serif; font-size: 13px; color: #000; }
        h1 { font-size: 16px; margin: 0; text-align: center; } .c { text-align: center; } .muted { color: #444; font-size: 11px; }
        .big { font-size: 26px; font-weight: 800; text-align: center; margin: 4px 0; } table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; } td.n { text-align: end; white-space: nowrap; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        .opt { font-size: 11px; color: #333; } .tot td { font-weight: 800; font-size: 15px; }</style></head><body>
        ${kitchen ? `<h1>${esc(T.kitchenTicket)}</h1>` : `<h1>${esc(header.brand)}</h1><div class="c muted">${esc(header.branch)}${header.phone ? ' · ' + esc(header.phone) : ''}</div>`}
        <div class="big" dir="ltr">${esc(p.offline_ref)}</div><div class="c">${esc(p.type === 'dine_in' ? T.dineIn : T.takeaway)}<div class="muted">${esc(when)}</div>
        ${p.customer_name ? `<div>${esc(p.customer_name)}</div>` : ''}</div><hr><table>${lines}</table>${p.notes ? `<hr><div>⚠ ${esc(p.notes)}</div>` : ''}${totals}</body></html>`;
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;width:0;height:0;border:0;inset-inline-start:-9999px';
    frame.setAttribute('aria-hidden', 'true');
    document.body.appendChild(frame);
    frame.contentDocument.open();
    frame.contentDocument.write(html);
    frame.contentDocument.close();
    setTimeout(() => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
        setTimeout(() => frame.remove(), 60000);
    }, 250);
}
