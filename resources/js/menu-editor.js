// Menu editor helpers: drag-to-reorder lists, quick sold-out/visible toggles, and the sizes & extras editor.

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

// Quick toggles: sold out / visible.
document.querySelectorAll('[data-toggle]').forEach((btn) => {
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            const { value } = await post(btn.dataset.toggle, { field: btn.dataset.field });
            btn.textContent = value ? btn.dataset.on : btn.dataset.off;
            if (btn.dataset.field === 'sold_out') {
                btn.classList.toggle('bg-red-100', value);
                btn.classList.toggle('text-red-700', value);
                btn.classList.toggle('bg-emerald-100', !value);
                btn.classList.toggle('text-emerald-800', !value);
            } else {
                btn.classList.toggle('bg-navy-100', value);
                btn.classList.toggle('text-navy-700', value);
                btn.classList.toggle('bg-navy-800', !value);
                btn.classList.toggle('text-white', !value);
            }
        } finally {
            btn.disabled = false;
        }
    });
});

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
