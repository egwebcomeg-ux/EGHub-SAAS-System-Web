// Dashboard bell: polls /notifications every 20 s (slower in a hidden tab), shows the unread count and a dropdown
// list; opening it marks everything read for this user. Each row links to where the thing is handled.

const box = document.querySelector('[data-notifications]');
if (box) {
    const POLL_MS = 20000;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const bell = box.querySelector('[data-bell]');
    const panel = box.querySelector('[data-bell-panel]');
    const list = box.querySelector('[data-bell-list]');
    const count = box.querySelector('[data-bell-count]');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    let items = [];

    function renderCount(n) {
        count.textContent = n > 99 ? '99+' : String(n);
        count.classList.toggle('hidden', n === 0);
    }

    function renderList() {
        list.innerHTML = items.length
            ? items.map((i) => `
                <li><a href="${esc(i.url)}" class="flex gap-3 px-4 py-3 hover:bg-navy-50 ${i.unread ? 'bg-brand-50/60' : ''}">
                    <span class="text-xl leading-none" aria-hidden="true">${esc(i.icon)}</span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2"><b class="truncate text-sm">${esc(i.title)}</b><span class="shrink-0 text-xs text-navy-500" dir="ltr">${esc(i.time)}</span></span>
                        <span class="block truncate text-xs text-navy-500">${esc(i.detail)}</span>
                    </span>
                    ${i.unread ? '<span class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-600" aria-hidden="true"></span>' : ''}
                </a></li>`).join('')
            : `<li class="px-4 py-8 text-center text-sm text-navy-500">${esc(box.dataset.empty)}</li>`;
    }

    async function load() {
        const res = await fetch(box.dataset.feed, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        items = data.items || [];
        renderCount(data.unread || 0);
        if (!panel.classList.contains('hidden')) renderList();
    }

    function toggle(open) {
        panel.classList.toggle('hidden', !open);
        bell.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!open) return;
        renderList();
        if (items.some((i) => i.unread)) {
            renderCount(0);
            // read from now on; the dots stay in this open list so the user still sees what was new
            fetch(box.dataset.seen, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } }).catch(() => {});
        }
    }

    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        toggle(panel.classList.contains('hidden'));
    });
    document.addEventListener('click', (e) => {
        if (!box.contains(e.target)) toggle(false);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') toggle(false);
    });

    async function poll() {
        try { await load(); } catch {}
        setTimeout(poll, document.hidden ? POLL_MS * 3 : POLL_MS);
    }
    poll();
}
