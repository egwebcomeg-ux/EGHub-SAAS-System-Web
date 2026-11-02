// What a browser remembered under the old name moves to the new one, so no guest loses a cart or an address. The offline
// cashier keeps its own old keys: a cached till page still reads them, and losing them would restart its order numbers.
try {
    for (const key of Object.keys(localStorage)) {
        if (key.startsWith('egmenu.') && !key.startsWith('egmenu.pos.')) {
            const renamed = 'eghub.' + key.slice('egmenu.'.length);
            if (localStorage.getItem(renamed) === null) localStorage.setItem(renamed, localStorage.getItem(key));
            localStorage.removeItem(key);
        }
    }
} catch {}
