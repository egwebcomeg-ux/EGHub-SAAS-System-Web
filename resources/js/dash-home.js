// The apps screen (dashboard/home.blade.php): the search box filters the tiles on the page, no request. "/" focuses
// it on a desktop keyboard, Escape clears it, Enter opens the first app left. The "your next step" card remembers
// whether this browser folded it.

const nextStep = document.querySelector('[data-next-step-body]');
if (nextStep) {
    const key = 'egmenu.home.nextStep';
    try {
        if (localStorage.getItem(key) === 'closed') {
            nextStep.open = false;
        }
    } catch {}
    nextStep.addEventListener('toggle', () => {
        try {
            localStorage.setItem(key, nextStep.open ? 'open' : 'closed');
        } catch {}
    });
}

const root = document.querySelector('[data-home-apps]');
const input = root?.querySelector('[data-apps-search]');

if (root && input) {
    const tiles = [...root.querySelectorAll('[data-app-tile]')];
    const groups = [...root.querySelectorAll('[data-apps-group]')];
    const more = root.querySelector('[data-apps-more]');
    const empty = root.querySelector('[data-apps-empty]');
    const moreWasOpen = more?.open ?? false;

    // Arabic: short vowels, tatweel and the alef forms never decide a match
    const fold = (text) => text.toLowerCase().normalize('NFKD')
        .replace(/[ً-ْـ̀-ͯ]/g, '')
        .replace(/[آأإ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي');

    const visible = (scope) => [...scope.querySelectorAll('[data-app-tile]')].some((t) => !t.hidden);

    function apply() {
        const q = fold(input.value.trim());
        let shown = 0;
        for (const tile of tiles) {
            const hit = q === '' || fold(tile.dataset.search || tile.textContent).includes(q);
            tile.hidden = !hit;
            shown += hit ? 1 : 0;
        }
        for (const group of groups) {
            group.hidden = !visible(group);
        }
        if (more) {
            more.hidden = !visible(more);
            more.open = q === '' ? moreWasOpen : true;
        }
        if (empty) {
            empty.hidden = shown > 0;
        }
    }

    input.addEventListener('input', apply);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            input.value = '';
            apply();
            input.blur();
        } else if (e.key === 'Enter') {
            const first = tiles.find((t) => !t.hidden);
            if (first && input.value.trim() !== '') {
                e.preventDefault();
                first.click();
            }
        }
    });
    document.addEventListener('keydown', (e) => {
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
        if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing && window.matchMedia('(min-width: 1024px)').matches) {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });
    // the browser may restore a typed value on back navigation
    if (input.value !== '') {
        apply();
    }
}
