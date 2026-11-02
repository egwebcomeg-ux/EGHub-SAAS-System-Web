{{-- For the scripts that run before the page's bundle: the same move as resources/js/storage-rename.js --}}
<script>
    try {
        Object.keys(localStorage).forEach(function (key) {
            if (key.indexOf('egmenu.') === 0 && key.indexOf('egmenu.pos.') !== 0) {
                var renamed = 'eghub.' + key.slice(7);
                if (localStorage.getItem(renamed) === null) localStorage.setItem(renamed, localStorage.getItem(key));
                localStorage.removeItem(key);
            }
        });
    } catch (e) {}
</script>
