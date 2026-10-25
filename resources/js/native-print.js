// Printing through the EgHub cashier app (window.EgHubNative.print, ESC/POS on the printer chosen for each role):
// the receipt after a payment, a kitchen ticket per station as soon as an order arrives, reprints and the Z report.
// The server builds every job (App\Services\Printing\PrintJobs) and claims each automatic copy once (order + role);
// this device also remembers what it printed, so a reload or a second tab never prints the same copy twice.
// Outside the app createNativePrinter() returns null and the page prints through the browser as before.
import { call, callJson, inApp, onNative, store } from './native';

const MEMORY = 'eg-printed';
const KITCHEN_DEVICE = 'eg-print-kitchen-device';
const KEEP_MS = 2 * 24 * 3600e3;

function toast(text, tone = 'error') {
    const el = document.createElement('div');
    el.setAttribute('role', 'status');
    el.className = 'fixed inset-x-3 bottom-4 z-[100] mx-auto max-w-md rounded-xl px-4 py-3 text-sm font-bold text-white shadow-lg '
        + (tone === 'error' ? 'bg-red-600' : 'bg-navy-800');
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 5000);
}

/** Sends one job to the app's printer: true when the app took it. */
export function sendToPrinter(job) {
    const res = callJson('print', JSON.stringify(job));
    if (res === null) return { ok: false, error: '' };
    if (typeof res === 'object') return { ok: res.ok !== false, error: res.error || '' };
    return { ok: true, error: '' };
}

/**
 * cfg: {tenant, csrf: () => token, urls: {jobs, release, queue} (jobs / release with __ID__), printing: {receiptAuto,
 * kitchenAuto, roles: {role: label}}, texts: {printFailed, printed}}
 */
export function createNativePrinter(cfg) {
    if (!inApp('pos') || !cfg?.urls?.jobs || !cfg.printing) return null;
    const T = cfg.texts || {};
    const memKey = (orderId, role) => `${cfg.tenant}:${orderId}:${role}`;
    let memory = store.get(MEMORY, {}) || {};
    const now = Date.now();
    memory = Object.fromEntries(Object.entries(memory).filter(([, at]) => now - at < KEEP_MS).slice(-2000));
    store.set(MEMORY, memory);
    const remember = (orderId, role, on) => {
        if (on) memory[memKey(orderId, role)] = Date.now(); else delete memory[memKey(orderId, role)];
        store.set(MEMORY, memory);
    };
    const pending = new Map(); // jobId => {orderId, role, reprint}
    const failures = {}; // a copy that just failed waits a minute before the automatic printing tries it again
    const RETRY_MS = 60000;

    async function request(url, body, method = 'POST') {
        const res = await fetch(url, {
            method, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf() },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || String(res.status));
        return data;
    }

    function failed(p, error) {
        toast((T.printFailed || 'Printing failed') + (error ? ': ' + error : ''));
        if (p.reprint || p.orderId === null) return;
        remember(p.orderId, p.role, false); // the next automatic round tries it again, after a minute
        failures[memKey(p.orderId, p.role)] = Date.now();
        request(cfg.urls.release.replace('__ID__', p.orderId), { role: p.role }).catch(() => {});
    }

    onNative('eg-native-print', (d) => {
        const p = d.jobId ? pending.get(d.jobId) : null;
        if (!p) return;
        pending.delete(d.jobId);
        if (d.ok === false) failed(p, d.error);
    });

    function print(job, orderId, reprint) {
        const p = { orderId, role: job.role, reprint };
        if (job.jobId) pending.set(job.jobId, p);
        const r = sendToPrinter(job);
        if (!r.ok) {
            if (job.jobId) pending.delete(job.jobId);
            failed(p, r.error);
        }
        return r.ok;
    }

    /** Prints roles of an order. Automatic (reprint false): only copies never printed, by any device. */
    async function printOrder(orderId, roles, { reprint = false } = {}) {
        const wanted = reprint ? roles : roles.filter((r) => !memory[memKey(orderId, r)] && Date.now() - (failures[memKey(orderId, r)] || 0) >= RETRY_MS);
        if (!orderId || !wanted.length) return 0;
        let data;
        try {
            data = await request(cfg.urls.jobs.replace('__ID__', orderId), { roles: wanted, reprint });
        } catch (e) {
            if (reprint) toast((T.printFailed || 'Printing failed') + ': ' + e.message);
            return 0;
        }
        if (!reprint) (data.skipped || []).forEach((r) => remember(orderId, r, true)); // printed elsewhere or nothing for it
        let printed = 0;
        for (const job of data.jobs || []) {
            if (!reprint) remember(orderId, job.role, true);
            if (print(job, orderId, reprint)) printed++;
        }
        return printed;
    }

    const deviceKitchen = () => store.get(KITCHEN_DEVICE, true) !== false;
    let busy = false;
    async function poll() {
        if (busy || !cfg.printing.kitchenAuto || !deviceKitchen() || !cfg.urls.queue) return;
        busy = true;
        try {
            const data = await request(cfg.urls.queue, null, 'GET');
            for (const o of data.orders || []) await printOrder(o.id, o.roles);
        } catch { /* offline or signed out: the next round tries again */ } finally {
            busy = false;
        }
    }
    if (cfg.printing.kitchenAuto) {
        setInterval(poll, 8000);
        setTimeout(poll, 1500);
    }

    return {
        printOrder,
        poll,
        receiptAuto: !!cfg.printing.receiptAuto,
        kitchenRoles: () => Object.keys(cfg.printing.roles || {}).filter((r) => r.startsWith('kitchen:')),
        roles: () => ({ receipt: T.receipt || 'Receipt', ...(cfg.printing.roles || {}) }),
        printers: () => { const list = callJson('listPrinters'); return Array.isArray(list) ? list : []; },
        choose: (role) => call('choosePrinter', role),
        test: (role) => call('testPrint', role),
        deviceKitchen,
        setDeviceKitchen: (on) => store.set(KITCHEN_DEVICE, !!on),
    };
}

// The Z report page: «print on the cashier printer» inside the app (the browser print button stays).
const zButton = document.querySelector('[data-native-z]');
if (zButton && inApp('pos')) {
    zButton.hidden = false;
    zButton.addEventListener('click', async () => {
        try {
            const res = await fetch(zButton.dataset.nativeZ, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await res.json();
            const r = sendToPrinter(data.job);
            toast(r.ok ? zButton.dataset.done : zButton.dataset.failed + (r.error ? ': ' + r.error : ''), r.ok ? 'info' : 'error');
        } catch {
            toast(zButton.dataset.failed);
        }
    });
}
