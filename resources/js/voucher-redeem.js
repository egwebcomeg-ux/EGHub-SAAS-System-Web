// "Redeem vouchers" screen (no POS needed): look a voucher up by code / QR / the guest's mobile, take it on an open
// order of the branch or on a paper bill, exceptions after expiry and cancelling a paper bill with a manager's
// approval, then the confirmation and the guest's 80mm receipt. The server checks every rule again.
import { canScanQr, planVouchers, scanQr } from './corporate-vouchers';

const dataEl = document.getElementById('redeem-data');
if (dataEl) {
    const D = JSON.parse(dataEl.textContent);
    const T = JSON.parse(document.getElementById('redeem-i18n').textContent);
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const fmt = (n) => `${Math.round(n * 100) / 100} ${D.currency}`;
    const csrf = $('meta[name="csrf-token"]').content;

    async function api(url, body, method = 'POST') {
        const res = await fetch(url, {
            method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || (data.errors ? Object.values(data.errors)[0][0] : res.status));
        return data;
    }
    const show = (el, text) => { el.textContent = text || ''; el.classList.toggle('hidden', !text); };

    // ---- state: the vouchers chosen, the guest's mobile they were found by, and what they pay for ----
    let chosen = []; // [{id, code, serial, company, balance, value, mode, expiresSort, approval}]
    let chosenPhone = null;
    let found = [];
    let lookupPhone = null;
    let target = 'order';
    let orders = [];
    let orderId = null;
    let method = null;

    const bill = () => {
        if (target === 'order') return orders.find((o) => o.id === orderId)?.total ?? 0;
        return Math.max(0, Number($('[data-amount]').value || 0));
    };

    // ---- 1. lookup ----
    function renderFound() {
        const inUse = new Set(chosen.map((v) => v.id));
        const total = bill();
        $('[data-results]').innerHTML = found.map((v, i) => `
            <li class="rounded-xl border ${v.ok ? 'border-emerald-200 bg-emerald-50/40' : 'border-navy-100'} p-3" data-voucher="${esc(v.serial)}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="text-lg font-bold">${esc(v.company)} · <span dir="ltr">${esc(v.serial)}</span></div>
                        <div class="text-sm">${esc(T.balance.replace('__B__', fmt(v.balance)).replace('__V__', fmt(v.value)))} · ${esc(v.modeLabel)}</div>
                        <div class="text-xs text-navy-500">${esc(T.until.replace('__D__', v.expires))}${v.linked ? ' · 🔒 ' + esc(v.linked) : ''}${v.pending ? ' · ⏳ ' + esc(v.pending) : ''}</div>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        ${v.ok && !inUse.has(v.id) ? `<button type="button" class="btn-primary px-4" data-use="${i}">${esc(T.use)}</button>` : ''}
                        ${inUse.has(v.id) ? `<span class="badge bg-emerald-100 text-emerald-800">✓ ${esc(T.added)}</span>` : ''}
                        ${v.expired && !inUse.has(v.id) ? `<button type="button" class="btn-ghost px-3 text-amber-800" data-exception="${i}">${esc(T.askException)}</button>` : ''}
                        ${v.pending && lookupPhone ? `<button type="button" class="btn-ghost px-3" data-confirm-link="${i}">${esc(T.confirmLink)}</button>` : ''}
                        ${v.code && !v.linked && v.ok ? `<button type="button" class="btn-ghost px-3" data-link="${i}">${esc(T.link)}</button>` : ''}
                    </div>
                </div>
                ${v.reason ? `<div class="mt-2 rounded-lg ${v.ok ? 'bg-emerald-50 text-emerald-800' : v.expired ? 'bg-amber-50 text-amber-800' : 'bg-red-50 text-red-700'} px-2 py-1 text-sm font-semibold" data-reason>${esc(v.reason)}</div>` : ''}
                ${v.ok && v.mode === 'single' && total > 0 && v.balance > total + 0.004 ? `<div class="mt-2 text-xs font-semibold text-amber-700" data-single-warn>⚠ ${esc(T.singleWarn.replace('__A__', fmt(v.balance - total)))}</div>` : ''}
            </li>`).join('');
    }

    async function lookup(raw) {
        const query = (raw ?? $('[data-query]').value).trim();
        show($('[data-error]'), '');
        if (!query) return;
        try {
            const r = await api(D.urls.check, { query, total: bill() });
            lookupPhone = r.by === 'phone' ? r.phone : null;
            found = r.vouchers.map((v) => ({ ...v, code: r.by === 'code' ? v.code : null }));
            if (r.by === 'phone' && !found.length) show($('[data-error]'), T.noneForPhone);
            renderFound();
            // one good voucher scanned / typed goes straight onto the bill
            if (r.by === 'code' && found.length === 1 && found[0].ok) use(0);
            $('[data-query]').value = '';
        } catch (e) {
            found = [];
            renderFound();
            show($('[data-error]'), e.message);
        }
    }
    function use(i, approval = null) {
        const v = found[i];
        if (!v || chosen.some((x) => x.id === v.id)) return;
        if (!v.code) {
            if (chosenPhone && chosenPhone !== lookupPhone) chosen = chosen.filter((x) => x.code);
            chosenPhone = lookupPhone;
        }
        chosen.push({ id: v.id, code: v.code, serial: v.serial, company: v.company, balance: v.balance, value: v.value, mode: v.mode, expiresSort: v.expiresSort, approval });
        renderFound();
        renderSummary();
    }

    $('[data-check]').addEventListener('click', () => lookup());
    $('[data-query]').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });
    // opened from the guest desk with the guest's mobile: their vouchers come up at once
    if (D.lookup) lookup(D.lookup);
    $('[data-results]').addEventListener('click', async (e) => {
        const useBtn = e.target.closest('[data-use]');
        if (useBtn) return use(Number(useBtn.dataset.use));
        const exc = e.target.closest('[data-exception]');
        if (exc) return openApprove('exception', Number(exc.dataset.exception));
        const link = e.target.closest('[data-link], [data-confirm-link]');
        if (!link) return;
        const v = found[Number(link.dataset.link ?? link.dataset.confirmLink)];
        const phone = link.dataset.confirmLink !== undefined ? lookupPhone : (prompt(T.linkAsk) || '').trim();
        if (!phone) return;
        try {
            await api(D.urls.link, v.code ? { code: v.code, phone, total: bill() } : { id: v.id, phone, total: bill() });
            chosen = chosen.filter((x) => x.id !== v.id);
            renderSummary();
            await lookup(phone); // from now on it goes with the mobile
        } catch (err) {
            show($('[data-error]'), err.message);
        }
    });

    if (canScanQr()) {
        const btn = $('[data-scan]');
        let stop = null;
        btn.classList.remove('hidden');
        btn.addEventListener('click', async () => {
            if (stop) { stop(); stop = null; return; }
            stop = await scanQr($('[data-video]'), (code) => { stop = null; lookup(code); }, () => { stop = null; show($('[data-error]'), T.noCamera); });
        });
    }

    // ---- 2. what it pays for ----
    $$('[data-target-tab]').forEach((tab) => tab.addEventListener('click', () => {
        target = tab.dataset.targetTab;
        $$('[data-target-tab]').forEach((t) => t.setAttribute('aria-selected', String(t === tab)));
        $$('[data-panel]').forEach((p) => p.classList.toggle('hidden', p.dataset.panel !== target));
        renderSummary();
        renderFound();
    }));

    async function loadOrders() {
        try {
            const r = await api(D.urls.orders, null, 'GET');
            orders = r.orders;
        } catch {
            orders = [];
        }
        if (!orders.some((o) => o.id === orderId)) orderId = null;
        renderOrders();
        renderSummary();
    }
    function renderOrders() {
        $('[data-orders]').innerHTML = orders.length ? orders.map((o) => `
            <li><button type="button" data-order="${o.id}" aria-pressed="${o.id === orderId}"
                class="flex min-h-14 w-full items-center justify-between gap-2 rounded-xl border p-3 text-start ${o.id === orderId ? 'border-brand-600 bg-brand-50' : 'border-navy-100 bg-white'}">
                <span class="min-w-0"><b>#${esc(o.number)}</b> · ${esc(o.table ? T.table.replace('__N__', o.table) : o.typeLabel)}
                    <span class="block text-xs text-navy-500">${esc(o.time)} · ${esc(o.status)}</span></span>
                <b class="whitespace-nowrap">${fmt(o.total)}</b>
            </button></li>`).join('') : `<li class="rounded-xl bg-navy-50 p-4 text-center text-sm text-navy-500 sm:col-span-2" data-no-orders>${esc(T.noOrders)}</li>`;
    }
    $('[data-orders]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-order]');
        if (!b) return;
        orderId = Number(b.dataset.order);
        renderOrders();
        renderSummary();
        renderFound();
    });
    $('[data-orders-refresh]').addEventListener('click', loadOrders);
    $('[data-amount]').addEventListener('input', () => { renderSummary(); renderFound(); });
    $$('[data-method]').forEach((b) => b.addEventListener('click', () => {
        method = b.dataset.method;
        $$('[data-method]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    }));

    // ---- 3. the plan: earliest expiry first, what each voucher gives, what is lost, what is left to pay ----
    function renderSummary() {
        const total = bill();
        const applied = $('[data-applied]');
        const due = $('[data-due]');
        $('[data-empty-summary]').classList.toggle('hidden', chosen.length > 0);
        applied.classList.toggle('hidden', !chosen.length);
        if (!chosen.length) {
            due.classList.add('hidden');
            $('[data-methods]').classList.add('hidden');
            return;
        }
        // without a bill yet, show each voucher with its whole balance
        const plan = planVouchers(chosen, total > 0 ? total : chosen.reduce((s, v) => s + v.balance, 0));
        applied.innerHTML = plan.lines.map((l) => `
            <div class="flex items-start justify-between gap-2 py-2" data-applied-line="${esc(l.serial)}">
                <div><b dir="ltr">${esc(l.serial)}</b> · ${esc(l.company)}${l.approval ? ` <span class="badge bg-amber-100 text-amber-800">${esc(T.exception)}</span>` : ''}
                    ${total > 0 && l.used <= 0 ? `<div class="text-xs text-navy-500">${esc(T.notNeeded)}</div>` : ''}
                    ${total > 0 && l.lost > 0 ? `<div class="text-xs font-semibold text-amber-700" data-lost>⚠ ${esc(T.lost.replace('__A__', fmt(l.lost)))}</div>` : ''}
                    ${total > 0 && l.used > 0 && l.left > 0 ? `<div class="text-xs text-navy-500">${esc(T.leftAfter.replace('__A__', fmt(l.left)))}</div>` : ''}</div>
                <div class="flex items-center gap-1"><b>${total > 0 && l.used > 0 ? '−' + fmt(l.used) : ''}</b>
                    <button type="button" class="grid size-11 place-items-center rounded-lg text-red-600 hover:bg-red-50" data-remove="${l.id}" aria-label="${esc(T.remove)}">×</button></div>
            </div>`).join('');
        if (total <= 0) {
            due.classList.add('hidden');
            $('[data-methods]').classList.add('hidden');
            return;
        }
        due.classList.remove('hidden');
        due.innerHTML = plan.rest > 0.004 ? `<span class="text-brand-700">${esc(T.rest.replace('__A__', fmt(plan.rest)))}</span>` : `<span class="text-emerald-700">${esc(T.covered)}</span>`;
        // an order's rest is paid here (cash, card…); a paper bill's rest is collected the way the place always does
        $('[data-methods]').classList.toggle('hidden', !(target === 'order' && plan.rest > 0.004));
    }
    $('[data-applied]').addEventListener('click', (e) => {
        const b = e.target.closest('[data-remove]');
        if (!b) return;
        chosen = chosen.filter((v) => String(v.id) !== b.dataset.remove);
        if (!chosen.some((v) => !v.code)) chosenPhone = null;
        renderSummary();
        renderFound();
    });

    $('[data-submit]').addEventListener('click', async (e) => {
        const err = $('[data-submit-error]');
        show(err, '');
        if (!chosen.length) return show(err, T.addVoucher);
        const vouchers = chosen.map((v) => (v.code ? { code: v.code, approval: v.approval || null } : { id: v.id, approval: v.approval || null }));
        const extra = { vouchers, voucher_phone: chosenPhone };
        let url;
        let body;
        if (target === 'order') {
            if (!orderId) return show(err, T.pickOrder);
            const rest = planVouchers(chosen, bill()).rest;
            if (rest > 0.004 && !method) return show(err, T.chooseMethod);
            url = D.urls.pay.replace('__ID__', orderId);
            body = { method: rest > 0.004 ? method : 'corporate', ...extra };
        } else {
            if (!(bill() > 0)) return show(err, T.enterAmount);
            url = D.urls.manual;
            body = { amount: bill(), reference: $('[data-reference]').value.trim() || null, note: $('[data-note]').value.trim() || null, ...extra };
        }
        const btn = e.currentTarget;
        btn.disabled = true;
        try {
            done(await api(url, body));
        } catch (ex) {
            show(err, ex.message);
        } finally {
            btn.disabled = false;
        }
    });

    // ---- 4. done: what each voucher gave and what is left, the receipt for the guest ----
    const doneDialog = $('[data-done-dialog]');
    function done(r) {
        $('[data-done-title]').textContent = (r.order ? T.order.replace('__N__', r.order) : T.paper + (r.reference ? ' ' + r.reference : '')) + ' · ' + T.billOf.replace('__A__', fmt(r.bill));
        $('[data-done-lines]').innerHTML = r.lines.map((l) => `<tr data-done-line="${esc(l.serial)}">
            <td><b dir="ltr">${esc(l.serial)}</b><div class="text-xs text-navy-500">${esc(l.company)}</div>${l.lost > 0 ? `<div class="text-xs text-amber-700">⚠ ${esc(T.lost.replace('__A__', fmt(l.lost)))}</div>` : ''}</td>
            <td class="font-bold">${fmt(l.amount)}</td><td>${fmt(l.balance)}</td></tr>`).join('');
        $('[data-done-total]').textContent = fmt(r.covered);
        show($('[data-done-rest]'), r.rest > 0.004 ? T.rest.replace('__A__', fmt(r.rest)) : '');
        $('[data-done-receipt]').href = r.receipt;
        doneDialog.showModal();
    }
    $('[data-done-new]').addEventListener('click', () => window.location.reload());
    doneDialog.addEventListener('close', () => window.location.reload());

    // ---- manager approval: an exception on an expired voucher, or cancelling a paper bill ----
    let approving = null; // {kind, index | batch}
    function openApprove(kind, ref, label) {
        const dlg = $(`[data-approve-dialog="${kind}"]`);
        approving = { kind, ref };
        $('[data-approve-subject]', dlg).textContent = kind === 'exception' ? `${found[ref].serial} · ${found[ref].company} · ${found[ref].reason}` : label;
        $('[data-approve-reason]', dlg).value = '';
        if ($('[data-approve-pin]', dlg)) $('[data-approve-pin]', dlg).value = '';
        show($('[data-approve-error]', dlg), '');
        dlg.showModal();
        $('[data-approve-reason]', dlg).focus();
    }
    $$('[data-cancel]').forEach((b) => b.addEventListener('click', () => openApprove('cancel', b.dataset.cancel, b.dataset.cancelLabel)));
    $$('[data-approve-confirm]').forEach((btn) => btn.addEventListener('click', async () => {
        const dlg = btn.closest('dialog');
        const body = { reason: $('[data-approve-reason]', dlg).value.trim() };
        if (!D.canApprove) {
            body.approver_id = Number($('[data-approve-manager]', dlg)?.value) || null;
            body.pin = $('[data-approve-pin]', dlg)?.value || '';
        }
        btn.disabled = true;
        try {
            if (approving.kind === 'exception') {
                const v = found[approving.ref];
                const r = await api(D.urls.exception.replace('__ID__', v.id), body);
                dlg.close();
                found[approving.ref] = { ...v, ok: true, expired: false, reason: T.approvedBy.replace('__N__', r.approver) };
                use(approving.ref, r.token);
            } else {
                await api(D.urls.cancel.replace('__B__', approving.ref), body);
                dlg.close();
                window.location.reload();
            }
        } catch (ex) {
            show($('[data-approve-error]', dlg), ex.message);
        } finally {
            btn.disabled = false;
        }
    }));

    loadOrders();
}
