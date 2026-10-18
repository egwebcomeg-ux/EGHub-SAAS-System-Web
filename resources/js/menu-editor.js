// Menu editor helpers: drag-to-reorder lists, quick sold-out/visible toggles, inline price edit, search as you type,
// category chips, the menu settings panel, and on the item form the sizes & extras editor.

const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error(res.status);
    return res.json();
}

// Drag & drop: [data-sortable] lists of direct children with data-id, dragged by their [data-handle].
document.querySelectorAll('[data-sortable]').forEach((list) => {
    let dragged = null;
    [...list.children].forEach((row) => {
        if (!row.dataset.id) return;
        const handle = row.querySelector(':scope [data-handle]');
        handle?.addEventListener('mousedown', () => (row.draggable = true));
        handle?.addEventListener('touchstart', () => (row.draggable = true), { passive: true });
        row.addEventListener('dragstart', (e) => {
            e.stopPropagation();
            dragged = row;
            row.classList.add('opacity-50');
        });
        row.addEventListener('dragend', () => {
            row.draggable = false;
            row.classList.remove('opacity-50');
            if (dragged) post(list.dataset.url, { ids: [...list.children].map((r) => r.dataset.id).filter(Boolean) });
            dragged = null;
        });
        row.addEventListener('dragover', (e) => {
            if (!dragged || dragged.parentElement !== list || dragged === row) return;
            e.preventDefault();
            const after = e.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2;
            list.insertBefore(dragged, after ? row.nextSibling : row);
        });
    });
});

// "Sold out until" preset next to the list (remembered on this device); custom shows a date-time field.
const untilSelect = document.querySelector('[data-sold-out-until]:not(select[name])');
const untilAt = document.querySelector('[data-sold-out-until-at]:not(input[name])');
if (untilSelect) {
    try { untilSelect.value = localStorage.getItem('egmenu.soldOutUntil') || ''; } catch {}
    const syncUntil = () => {
        untilAt?.classList.toggle('hidden', untilSelect.value !== 'custom');
        try { localStorage.setItem('egmenu.soldOutUntil', untilSelect.value); } catch {}
    };
    untilSelect.addEventListener('change', syncUntil);
    syncUntil();
}

// Quick toggles: sold out / visible. aria-pressed carries the state (pressed = sold out / shown); CSS draws it.
document.querySelectorAll('[data-toggle]').forEach((btn) => {
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            const body = { field: btn.dataset.field };
            if (btn.dataset.field === 'sold_out' && untilSelect) {
                body.until = untilSelect.value;
                if (untilSelect.value === 'custom') body.until_at = untilAt?.value || null;
            }
            const { value, until } = await post(btn.dataset.toggle, body);
            btn.setAttribute('aria-pressed', value ? 'true' : 'false');
            btn.title = value ? btn.dataset.titleOn : btn.dataset.titleOff;
            btn.querySelector('[data-toggle-label]').textContent = value ? btn.dataset.on : btn.dataset.off;
            btn.querySelector('[data-toggle-until]')?.remove();
            if (value && until && btn.dataset.untilLabel) {
                const span = document.createElement('span');
                span.className = 'font-normal';
                span.dir = 'ltr';
                span.dataset.toggleUntil = '';
                span.textContent = '· ' + btn.dataset.untilLabel.replace('__T__', until);
                btn.append(span);
            }
        } catch {
            window.dashToast?.(priceText.error, { kind: 'error' });
        } finally {
            btn.disabled = false;
        }
    });
});

// Inline price: tap the price → a number field; Enter or leaving the field saves, Escape cancels.
const priceText = document.querySelector('[data-price-strings]')?.dataset ?? {};
const toLatinDigits = (s) => s.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٫,]/g, '.');

document.querySelectorAll('[data-price-edit]').forEach((btn) => {
    const cell = btn.closest('[data-price-cell]');
    const status = cell.querySelector('[data-price-status]');
    const setStatus = (text, tone) => {
        status.textContent = text;
        status.className = 'text-xs font-semibold ' + ({ busy: 'text-navy-500', ok: 'text-emerald-700', error: 'text-red-600' }[tone] || '');
    };

    btn.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'text';
        input.inputMode = 'decimal';
        input.autocomplete = 'off';
        input.className = 'dash-price-input';
        input.dir = 'ltr';
        input.value = btn.dataset.price;
        input.setAttribute('aria-label', btn.getAttribute('aria-label'));
        btn.hidden = true;
        btn.after(input);
        input.focus();
        input.select();
        setStatus(priceText.hint || '', 'busy');

        let done = false;
        const close = () => {
            input.remove();
            btn.hidden = false;
        };
        const cancel = () => {
            done = true;
            close();
            setStatus('', '');
            btn.focus();
        };
        let failed = null;
        const save = async (viaBlur = false) => {
            const raw = toLatinDigits(input.value.trim());
            // leaving the field after an error keeps it open with the same value instead of retrying in a loop
            if (done || (viaBlur && raw === failed)) return;
            done = true;
            if (raw === '' || Number(raw) === Number(btn.dataset.price)) {
                close();
                setStatus('', '');
                return;
            }
            input.disabled = true;
            setStatus(priceText.saving || '…', 'busy');
            try {
                const res = await fetch(btn.dataset.priceEdit, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({ price: raw }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    throw new Error(data.errors?.price?.[0] || data.message || priceText.error);
                }
                btn.dataset.price = data.price;
                btn.querySelector('[data-price-text]').textContent = data.formatted;
                close();
                if (!viaBlur) btn.focus();
                setStatus('✓ ' + (priceText.saved || ''), 'ok');
                setTimeout(() => status.textContent.startsWith('✓') && setStatus('', ''), 2500);
                const name = btn.closest('[data-item-row]')?.querySelector('a.font-semibold')?.textContent.trim() ?? '';
                window.dashToast?.((priceText.toast || '').replace(':name', name).replace(':price', data.formatted));
            } catch (err) {
                // keep the field open with what was typed, so it can be fixed and saved again
                done = false;
                failed = raw;
                input.disabled = false;
                if (!viaBlur) input.focus();
                setStatus(err.message || priceText.error, 'error');
                window.dashToast?.(err.message || priceText.error, { kind: 'error' });
            }
        };
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                save();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancel();
            }
        });
        input.addEventListener('blur', () => !input.disabled && save(true));
    });
});

// Search as you type: hides the items (and categories) that don't match; Enter still searches on the server.
const normalize = (s) => s.toLowerCase().replace(/[ً-ْـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').trim();
const search = document.querySelector('[data-menu-search]');
if (search) {
    const sections = [...document.querySelectorAll('[data-category-section]')];
    const empty = document.querySelector('[data-search-empty]');
    const filter = () => {
        const q = normalize(search.value);
        let shown = 0;
        sections.forEach((section) => {
            const rows = [...section.querySelectorAll('[data-item-row]')];
            let count = 0;
            rows.forEach((row) => {
                const hit = !q || normalize(row.dataset.search || '').includes(q);
                row.hidden = !hit;
                count += hit ? 1 : 0;
            });
            section.hidden = !!q && count === 0;
            shown += count;
            const chip = document.querySelector(`[data-chip="${section.dataset.id}"]`);
            if (chip) {
                chip.hidden = section.hidden;
                chip.querySelector('[data-chip-count]').textContent = count;
            }
        });
        empty?.classList.toggle('hidden', !q || shown > 0);
    };
    search.addEventListener('input', filter);
    search.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && search.value) {
            search.value = '';
            filter();
        }
    });
}

// Category chips: mark the category on screen; the chip row follows it sideways.
const chips = document.querySelector('[data-category-chips]');
if (chips && 'IntersectionObserver' in window) {
    const seen = new Map();
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((e) => seen.set(e.target.dataset.id, e.isIntersecting ? e.intersectionRect.height : 0));
        const [current] = [...seen.entries()].sort((a, b) => b[1] - a[1])[0] ?? [];
        chips.querySelectorAll('[data-chip]').forEach((chip) => {
            const on = chip.dataset.chip === current;
            if (on && chip.getAttribute('aria-current') !== 'true') {
                chip.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
            chip.setAttribute('aria-current', on ? 'true' : 'false');
        });
    }, { rootMargin: '-120px 0px -40% 0px', threshold: [0, 0.25, 0.5, 1] });
    document.querySelectorAll('[data-category-section]').forEach((s) => observer.observe(s));
}

// Menu settings panel: closed by default, remembers open/closed on this device (opens by itself when it needs you).
const settingsPanel = document.querySelector('[data-menu-settings]');
if (settingsPanel) {
    if (!settingsPanel.hasAttribute('data-force-open')) {
        try { settingsPanel.open = localStorage.getItem('egmenu.menuSettingsOpen') === '1'; } catch {}
        if (location.hash === '#ai-translate') settingsPanel.open = true;
    }
    settingsPanel.addEventListener('toggle', () => {
        try { localStorage.setItem('egmenu.menuSettingsOpen', settingsPanel.open ? '1' : '0'); } catch {}
    });
}

// The item just saved (or #item-N from "back to menu"): scrolled into view and flashed once.
const focused = document.querySelector('[data-item-row][data-focus]') || (/^#item-\d+$/.test(location.hash) ? document.querySelector(location.hash) : null);
if (focused) {
    focused.scrollIntoView({ block: 'center' });
    focused.classList.add('dash-found');
}

// Item form: the "sold out until" fields only matter while the item is sold out.
const soldOutCheck = document.querySelector('[data-sold-out-check]');
if (soldOutCheck) {
    const fields = document.querySelector('[data-sold-out-fields]');
    const select = fields.querySelector('select[data-sold-out-until]');
    const at = fields.querySelector('input[data-sold-out-until-at]');
    const sync = () => {
        fields.classList.toggle('hidden', !soldOutCheck.checked);
        at.classList.toggle('hidden', select.value !== 'custom');
    };
    soldOutCheck.addEventListener('change', sync);
    select.addEventListener('change', sync);
    sync();
}

// Sizes & extras editor. Inputs carry data-name; names are rebuilt as groups[i][...] before submit.
const groupsBox = document.querySelector('[data-groups]');
if (groupsBox) {
    const groupTpl = document.getElementById('group-template');
    const optionTpl = document.getElementById('option-template');

    const addOption = (group, o = {}) => {
        const row = optionTpl.content.firstElementChild.cloneNode(true);
        row.querySelector('[data-name="name"]').value = o.name ?? '';
        row.querySelector('[data-name="name_en"]').value = o.name_en ?? '';
        row.querySelector('[data-name="price"]').value = o.price ?? '';
        row.querySelector('[data-remove-option]').addEventListener('click', () => {
            if (group.querySelectorAll('[data-option]').length > 1) row.remove();
        });
        group.querySelector('[data-options]').append(row);
    };

    const addGroup = (g = {}) => {
        const el = groupTpl.content.firstElementChild.cloneNode(true);
        el.querySelector('[data-name="name"]').value = g.name ?? '';
        el.querySelector('[data-name="name_en"]').value = g.name_en ?? '';
        el.querySelector('[data-name="type"]').value = g.type ?? 'single';
        el.querySelector('[data-name="is_required"]').checked = !!g.is_required;
        el.querySelector('[data-name="max_choices"]').value = g.max_choices ?? '';
        el.querySelector('[data-add-option]').addEventListener('click', () => addOption(el));
        el.querySelector('[data-remove-group]').addEventListener('click', () => el.remove());
        groupsBox.append(el);
        (g.options?.length ? g.options : [{}]).forEach((o) => addOption(el, o));
    };

    JSON.parse(groupsBox.dataset.initial || '[]').forEach(addGroup);
    document.querySelector('[data-add-group]').addEventListener('click', () => addGroup());

    groupsBox.closest('form').addEventListener('submit', () => {
        groupsBox.querySelectorAll('[data-group]').forEach((group, gi) => {
            group.querySelectorAll(':scope > div:first-child [data-name]').forEach((input) => {
                input.name = `groups[${gi}][${input.dataset.name}]`;
            });
            group.querySelectorAll('[data-option]').forEach((row, oi) => {
                row.querySelectorAll('[data-name]').forEach((input) => {
                    input.name = `groups[${gi}][options][${oi}][${input.dataset.name}]`;
                });
            });
        });
    });
}
