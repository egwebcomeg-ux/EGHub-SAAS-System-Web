{{-- Every 15 seconds (while the tab is visible) each [data-tv-live="{id}"] gets its screen's fresh status and «بتعرض دلوقتي». --}}
<script>
    (() => {
        const boxes = () => document.querySelectorAll('[data-tv-live]');
        if (!boxes().length) {
            return;
        }
        const refresh = () => {
            if (document.hidden) {
                return;
            }
            fetch(@js(route('dash.tv.live')), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : null))
                .then((d) => {
                    if (!d?.screens) {
                        return;
                    }
                    boxes().forEach((box) => {
                        const html = d.screens[box.dataset.tvLive];
                        if (html !== undefined) {
                            box.innerHTML = html;
                        }
                    });
                })
                .catch(() => {});
        };
        setInterval(refresh, 15000);
        document.addEventListener('visibilitychange', refresh);
    })();
</script>
