// The guest pager on the staff screens (order board, kitchen display, POS recent orders, pager station): whether the
// guest has the pager page open right now, how many times they were called, and the "Call" / WhatsApp / ticket buttons.
// The texts come from App\Services\Orders\Pager::staffTexts() (T below).

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** The status line: connected now / page closed / QR not scanned yet, and the calls so far. */
export function pagerStatus(p, T, dark = false) {
    if (!p) return '';
    let tone;
    let text;
    if (p.picked_up) {
        tone = dark ? 'bg-emerald-500/20 text-emerald-200' : 'bg-emerald-100 text-emerald-800';
        text = `✓ ${T.pickedUp}`;
    } else if (p.connected) {
        tone = dark ? 'bg-emerald-500/20 text-emerald-200' : 'bg-emerald-100 text-emerald-800';
        text = `● ${T.connected}`;
    } else if (p.seen) {
        tone = dark ? 'bg-amber-500/20 text-amber-200' : 'bg-amber-100 text-amber-800';
        text = `○ ${T.away}`;
    } else {
        tone = dark ? 'bg-white/10 text-white/70' : 'bg-navy-100 text-navy-600';
        text = `○ ${T.never}`;
    }
    const calls = p.calls ? `<span class="text-xs font-semibold ${dark ? 'text-white/70' : 'text-navy-500'}" data-pager-calls>📣 ${esc(T.called.replace('__N__', p.calls).replace('__T__', p.called_at || ''))}</span>` : '';

    return `<div class="mt-2 flex flex-wrap items-center gap-1.5" data-pager-status="${p.connected ? 'connected' : p.seen ? 'away' : 'never'}">
        <span class="badge ${tone}">${esc(text)}</span>${calls}</div>`;
}

/** The buttons: "Call" (rings the guest's phone), the WhatsApp fallback when there is a number, and the QR ticket. */
export function pagerButtons(p, T, id, dark = false) {
    if (!p || p.done) return '';
    const ghost = dark ? 'rounded-xl bg-white/10 px-3 text-white hover:bg-white/15' : 'btn-ghost px-3';

    return `<div class="mt-2 flex flex-wrap gap-2">
        ${p.call ? `<button type="button" class="${dark ? 'rounded-xl bg-amber-400 px-4 font-extrabold text-navy-900' : 'btn-primary px-4'} min-h-11 flex-1" data-pager-call="${esc(p.call)}" data-pager-id="${id}">📣 ${esc(T.call)}</button>` : ''}
        ${p.whatsapp && !p.connected ? `<button type="button" class="${ghost} min-h-11" data-pager-wa="${esc(p.whatsapp)}" title="${esc(T.whatsapp)}">💬 ${esc(T.whatsapp)}</button>` : ''}
        ${p.print ? `<a href="${esc(p.print)}" target="_blank" rel="noopener" class="${ghost} inline-flex min-h-11 items-center" title="${esc(T.print)}" aria-label="${esc(T.print)}">🖨</a>` : ''}
    </div>`;
}

/** One click handler for every "Call" and WhatsApp button inside root; after() redraws the screen. */
export function bindPager(root, after) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    root.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-pager-call], [data-pager-wa]');
        if (!btn) return;
        e.preventDefault();
        btn.disabled = true;
        try {
            const res = await fetch(btn.dataset.pagerCall || btn.dataset.pagerWa, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) alert(data.message || res.status);
            else if (btn.dataset.pagerWa && data.message) alert(data.message);
        } catch (err) {
            alert(err.message);
        }
        btn.disabled = false;
        after?.();
    });
}
