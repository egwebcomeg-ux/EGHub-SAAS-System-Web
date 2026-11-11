// Automatic kitchen tickets from the cashier screen in a browser (outside the EgHub cashier app, which has its own:
// native-print.js). A device the cashier turns it on for asks the server every few seconds for the new orders that
// need a ticket (the owner's choice: waiters' orders, the QR menu, online…), claims each order's copies once (no other
// device prints them again) and prints the kitchen copy of the order page in a hidden frame.
// The browser asks before each print unless Chrome was started with --kiosk-printing: then it goes to the default
// printer at once.
import { inApp, store } from './native';

const DEVICE = 'eg-browser-kitchen';
const PRINTED = 'eg-browser-kitchen-printed';
const KEEP_MS = 2 * 24 * 3600e3;
const GAP_MS = 4000;

/** cfg: {tenant, csrf: () => token, kitchenAuto, urls: {jobs, queue, receipt} (jobs / receipt with __ID__)} */
export function createBrowserKitchenPrinter(cfg) {
    if (inApp('pos') || !cfg?.kitchenAuto || !cfg.urls?.queue || !cfg.urls?.jobs || !cfg.urls?.receipt) return null;
    let printed = store.get(PRINTED, {}) || {};
    const now = Date.now();
    printed = Object.fromEntries(Object.entries(printed).filter(([, at]) => now - at < KEEP_MS).slice(-1000));
    store.set(PRINTED, printed);
    const key = (id) => `${cfg.tenant}:${id}`;
    const enabled = () => store.get(DEVICE, false) === true;

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

    /** The kitchen copy of the order in a hidden frame: its page prints itself once loaded. */
    function printTicket(orderId) {
        return new Promise((resolve) => {
            const frame = document.createElement('iframe');
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText = 'position:fixed;width:0;height:0;border:0;inset-inline-start:-9999px';
            frame.src = cfg.urls.receipt.replace('__ID__', orderId) + '?copy=kitchen';
            frame.addEventListener('load', () => setTimeout(resolve, GAP_MS), { once: true });
            document.body.appendChild(frame);
            setTimeout(() => frame.remove(), 60000);
        });
    }

    let busy = false;
    async function poll() {
        if (busy || !enabled()) return;
        busy = true;
        try {
            const data = await request(cfg.urls.queue, null, 'GET');
            for (const o of data.orders || []) {
                if (printed[key(o.id)]) continue;
                // claims every copy of the order (one per station): printed once, here, as a single ticket
                const claim = await request(cfg.urls.jobs.replace('__ID__', o.id), { roles: o.roles, reprint: false });
                printed[key(o.id)] = Date.now();
                store.set(PRINTED, printed);
                if ((claim.jobs || []).length) await printTicket(o.id);
            }
        } catch { /* offline or signed out: the next round tries again */ } finally {
            busy = false;
        }
    }
    setInterval(poll, 8000);
    setTimeout(poll, 1500);

    return {
        poll,
        enabled,
        setEnabled: (on) => { store.set(DEVICE, !!on); if (on) poll(); },
    };
}
