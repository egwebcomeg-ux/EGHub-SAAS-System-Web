{{--
    A page the LiteSpeed page cache may keep (App\Services\PageCache) carries no CSRF token: the same copy goes to every
    visitor. Before the first POST from this page (fetch, or a form) the token comes from /_csrf, which also starts
    the session; a page that still has its token uses it as before. A menu page also counts its visit, which PHP did
    not see when the copy came from the cache (MenuController::visit).
--}}
@php
    $visitUrl = tenancy()->initialized && preg_match('/\.(show|item)$/', (string) request()->route()?->getName()) ? route('menu.visit') : null;
@endphp
<script>
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var pending = null;
    function token() {
        if (meta && meta.content) { return Promise.resolve(meta.content); }
        if (!pending) {
            pending = nativeFetch('/_csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (meta) { meta.content = d.token; }
                    document.querySelectorAll('input[name="_token"]').forEach(function (i) { i.value = d.token; });
                    return d.token;
                })
                .catch(function (e) { pending = null; throw e; });
        }
        return pending;
    }
    var nativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        var isRequest = typeof Request !== 'undefined' && input instanceof Request;
        var method = String((init && init.method) || (isRequest ? input.method : 'GET')).toUpperCase();
        var url = new URL(isRequest ? input.url : String(input), location.href);
        if (method === 'GET' || method === 'HEAD' || url.origin !== location.origin) { return nativeFetch(input, init); }
        return token().then(function (t) {
            var options = Object.assign({}, init || {});
            var headers = new Headers(options.headers || (isRequest ? input.headers : undefined));
            headers.set('X-CSRF-TOKEN', t);
            options.headers = headers;
            return nativeFetch(input, options);
        });
    };
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || String(form.method).toLowerCase() !== 'post') { return; }
        var input = form.querySelector('input[name="_token"]');
        if (!input || input.value) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var submitter = e.submitter;
        token().then(function () { form.requestSubmit ? form.requestSubmit(submitter || undefined) : form.submit(); });
    }, true);
    @if ($visitUrl)
    try {
        var visitKey = 'eg-visit:' + location.host + location.pathname.split('/').slice(0, 2).join('/') + ':' + new Date().toDateString();
        if (!sessionStorage.getItem(visitKey)) {
            sessionStorage.setItem(visitKey, '1');
            nativeFetch(@json($visitUrl), { credentials: 'same-origin' }).catch(function () {});
        }
    } catch (err) {}
    @endif
})();
</script>
