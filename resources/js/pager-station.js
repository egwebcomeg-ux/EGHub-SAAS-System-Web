// The pager station (/pager, App\Http\Controllers\Dashboard\PagerController): for places that take orders on another
// POS. Type the order number (or +1), show the big QR to the guest, then call and close each ticket from the queue.
// The queue polls every few seconds and shows whether each guest has the pager page open right now.
import { pagerStatus } from './pager-call';

const station = document.getElementById('pager-station');
if (station) {
    const T = JSON.parse(station.dataset.i18n);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const pollMs = Number(station.dataset.poll || 5) * 1000;
    const $ = (s) => station.querySelector(s);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const input = $('[data-number]');
    const error = $('[data-error]');
    const dialog = $('[data-qr-dialog]');
    let tickets = [];

    const post = (url, body = null) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: body ? JSON.stringify(body) : null,
    });
    const showError = (message) => {
        error.textContent = message || '';
        error.classList.toggle('hidden', !message);
    };

    // ---- the keypad ----
    $('[data-keypad]').addEventListener('click', (e) => {
        const key = e.target.closest('[data-key]')?.dataset.key;
        if (!key) return;
        if (key === 'clear') input.value = '';
        else if (key === 'back') input.value = input.value.slice(0, -1);
        else if (input.value.length < 6) input.value += key;
        showError('');
    });
    input.addEventListener('input', () => { input.value = input.value.replace(/\D+/g, '').slice(0, 6); showError(''); });
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') create(false); });

    function showQr(ticket, svg) {
        $('[data-qr-number]').textContent = `#${ticket.number}`;
        $('[data-qr-code]').innerHTML = svg;
        $('[data-qr-print]').href = ticket.print;
        if (!dialog.open) dialog.showModal();
    }
    $('[data-qr-close]').addEventListener('click', () => dialog.close());

    async function create(next) {
        const number = next ? null : Number(input.value);
        if (!next && !number) {
            input.focus();
            return;
        }
        showError('');
        const res = await post(station.dataset.store, number ? { number } : {});
        const data = await res.json().catch(() => ({}));
        if (!res.ok) return showError(data.message || res.status);
        input.value = '';
        $('[data-next-label]').textContent = T.next.replace('__N__', data.next);
        showQr(data.ticket, data.qr);
        load();
    }
    $('[data-create]').addEventListener('click', () => create(false));
    $('[data-create-next]').addEventListener('click', () => create(true));

    // ---- the queue ----
    const statusLabel = (t) => ({ waiting: T.waiting, called: T.calledStatus, picked_up: T.picked_up, expired: T.expired })[t.status] || t.status;
    function row(t) {
        const open = ['waiting', 'called'].includes(t.status);
        return `<li class="flex flex-wrap items-center gap-3 py-3 ${open ? '' : 'opacity-60'}" data-ticket="${t.id}">
            <div class="w-24 shrink-0 text-center"><div class="text-3xl font-black tabular-nums" dir="ltr">#${t.number}</div><div class="text-xs text-navy-500">${esc(t.time)}</div></div>
            <div class="min-w-0 flex-1">
                <span class="badge ${t.status === 'called' ? 'bg-amber-100 text-amber-800' : t.status === 'waiting' ? 'bg-sky-100 text-sky-800' : 'bg-navy-100 text-navy-600'}">${esc(statusLabel(t))}</span>
                ${open || t.picked_up ? pagerStatus(t, T) : ''}
            </div>
            ${open ? `<div class="flex w-full flex-wrap gap-2 xl:w-auto xl:shrink-0">
                <button type="button" class="btn-primary min-h-12 flex-1 px-4 text-base" data-call="${esc(t.call)}">📣 ${esc(T.call)}</button>
                <button type="button" class="btn-ghost min-h-12 border px-3" data-picked="${esc(t.picked)}">✓ ${esc(T.pickedBtn)}</button>
                <button type="button" class="btn-ghost min-h-12 border px-3" data-qr="${esc(t.qr_url)}" aria-label="${esc(T.qr)}">▦</button>
            </div>` : ''}
        </li>`;
    }
    function render() {
        const open = tickets.filter((t) => ['waiting', 'called'].includes(t.status));
        const closed = tickets.filter((t) => !['waiting', 'called'].includes(t.status)).reverse();
        $('[data-open-count]').textContent = open.length;
        $('[data-queue]').innerHTML = tickets.length ? open.concat(closed).map(row).join('') : `<li class="py-12 text-center text-navy-500">${esc(T.empty)}</li>`;
    }
    async function load() {
        const res = await fetch(station.dataset.feed, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(res.status);
        const data = await res.json();
        tickets = data.tickets;
        $('[data-next-label]').textContent = T.next.replace('__N__', data.next);
        render();
    }
    async function poll() {
        try {
            await load();
            $('[data-live-dot]')?.classList.replace('bg-red-500', 'bg-emerald-500');
        } catch {
            $('[data-live-dot]')?.classList.replace('bg-emerald-500', 'bg-red-500');
        }
        setTimeout(poll, document.hidden ? pollMs * 3 : pollMs);
    }

    $('[data-queue]').addEventListener('click', async (e) => {
        const call = e.target.closest('[data-call]');
        const picked = e.target.closest('[data-picked]');
        const qr = e.target.closest('[data-qr]');
        if (qr) {
            const data = await (await fetch(qr.dataset.qr, { headers: { Accept: 'application/json' } })).json();
            return showQr(data.ticket, data.qr);
        }
        const btn = call || picked;
        if (!btn) return;
        btn.disabled = true;
        const res = await post(call ? call.dataset.call : picked.dataset.picked);
        if (!res.ok) alert((await res.json().catch(() => ({}))).message || res.status);
        try { await load(); } catch { btn.disabled = false; }
    });

    $('[data-fullscreen]')?.addEventListener('click', async () => {
        try {
            await document.documentElement.requestFullscreen();
            await navigator.wakeLock?.request('screen');
        } catch {}
    });

    render();
    poll();
}
