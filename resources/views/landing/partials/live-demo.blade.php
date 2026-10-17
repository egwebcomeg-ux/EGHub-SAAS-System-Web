{{--
    Home page: a small working demo of Rosto. The visitor edits prices or marks an item sold out in the "dashboard",
    the guest's phone follows at once; ordering from the phone drops the order on the dashboard. All in the browser,
    nothing is saved or sent.
--}}
@php
    $demoItems = [
        ['latte', __('Latte'), 65, __('Hot coffee')],
        ['cappuccino', __('Cappuccino'), 65, __('Hot coffee')],
        ['spanish', __('Iced Spanish latte'), 85, __('Cold drinks')],
        ['cheesecake', __('Cheesecake'), 90, __('Desserts')],
        ['croissant', __('Croissant'), 45, __('Bakery')],
    ];
    $demoL10n = [
        'newOrder' => __('New order') . ' · ' . __('Table 4'),
        'justNow' => __('Just now'),
        'total' => __('Total'),
        'markReady' => __('Mark ready'),
        'ready' => __('Ready'),
        'egp' => __('EGP'),
        'items' => __(':n items'),
        'oneItem' => __('One item'),
        'sent' => __('Sent to the cafe. It is on the dashboard now.'),
    ];
@endphp
<div data-live-demo data-l10n="{{ json_encode($demoL10n, JSON_UNESCAPED_UNICODE) }}"
     class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px] lg:grid-rows-[auto_1fr] lg:gap-x-10">
    {{-- the owner's side: prices and sold-out --}}
    <div class="min-w-0 overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-[0_20px_40px_-26px_rgba(20,31,56,.6)] lg:col-start-1 lg:row-start-1 lg:self-start">
        <div class="flex h-9 items-center gap-1.5 border-b border-navy-100 bg-navy-50/60 px-3 text-xs font-bold text-navy-500">
            <i class="size-2 rounded-full bg-navy-200"></i><i class="size-2 rounded-full bg-navy-200"></i>
            <span class="ms-2 truncate">{{ __('Your dashboard') }} · {{ __('Menu') }}</span>
        </div>
        <ul class="divide-y divide-navy-100">
            @foreach ($demoItems as [$key, $name, $price])
                <li class="flex min-h-15 items-center gap-2 px-3 py-2 sm:gap-3 sm:px-4" data-demo-row="{{ $key }}">
                    <span class="min-w-0 flex-1 truncate font-bold text-navy-900">{{ $name }}</span>
                    <label class="sr-only" for="demo-price-{{ $key }}">{{ __('Price of :item', ['item' => $name]) }}</label>
                    <span class="flex shrink-0 items-center gap-1">
                        <input id="demo-price-{{ $key }}" type="number" inputmode="numeric" min="1" max="999" step="5" value="{{ $price }}" data-demo-edit="{{ $key }}" dir="ltr"
                               class="field h-10 min-h-10 w-[4.5rem] px-1.5 text-center font-bold tabular-nums">
                        <span class="text-xs font-bold text-navy-500">{{ __('EGP') }}</span>
                    </span>
                    <button type="button" aria-pressed="false" data-demo-soldout="{{ $key }}" aria-label="{{ __('Sold out now: :item', ['item' => $name]) }}"
                            class="min-h-10 shrink-0 rounded-full border border-navy-200 px-3 text-xs font-bold text-navy-600 hover:border-navy-300 aria-pressed:border-red-200 aria-pressed:bg-red-50 aria-pressed:text-red-700">
                        {{ __('Sold out now') }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- the guest's phone --}}
    <div class="mx-auto w-full max-w-[300px] lg:col-start-2 lg:row-span-2 lg:row-start-1">
        <p class="mb-2 text-center text-xs font-bold text-navy-500">{{ __('The guest’s phone') }} · {{ __('Table 4') }}</p>
        <div class="rounded-[34px] bg-[#10182b] p-2 shadow-[0_24px_40px_-24px_rgba(20,31,56,.7)]">
            <div class="relative flex min-h-[500px] flex-col overflow-hidden rounded-[27px] bg-[#fbf7f1] text-navy-900">
                <div class="bg-[#4a3426] px-4 pt-6 pb-3 text-white">
                    <div class="flex items-center justify-between gap-2">
                        <b class="text-base font-extrabold">{{ __('Rosto') }}</b>
                        <span class="rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-bold">{{ __('Table 4') }}</span>
                    </div>
                    <p class="text-[11px] text-white/70">{{ __('Specialty coffee & homemade desserts') }}</p>
                </div>
                <ul class="flex-1 space-y-2 p-2.5">
                    @foreach ($demoItems as [$key, $name, $price, $category])
                        <li data-demo-item="{{ $key }}" data-name="{{ $name }}" data-price="{{ $price }}"
                            class="flex items-center gap-2.5 rounded-xl bg-white p-2 ring-1 ring-navy-100 transition-[opacity,filter] duration-300 data-[soldout]:opacity-60 data-[soldout]:grayscale">
                            <span class="size-10 shrink-0 rounded-full bg-[#ead9c6]" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1">
                                <b class="block truncate text-[13px]">{{ $name }}</b>
                                <span class="text-[11px] text-navy-500">{{ $category }}</span>
                                <span class="block text-xs font-bold text-brand-700"><bdi dir="ltr" data-demo-price class="rounded px-0.5">{{ $price }}</bdi> {{ __('EGP') }}</span>
                                <span data-demo-badge hidden class="mt-0.5 inline-block rounded-full bg-navy-900 px-2 py-0.5 text-[10px] font-bold text-white">{{ __('Sold out today') }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-1">
                                <button type="button" data-demo-minus hidden aria-label="{{ __('Remove one :item', ['item' => $name]) }}" class="grid size-9 place-items-center rounded-full border border-navy-200 bg-white text-lg leading-none font-bold text-navy-700">−</button>
                                <span data-demo-qty hidden class="w-4 text-center text-sm font-black tabular-nums"></span>
                                <button type="button" data-demo-plus aria-label="{{ __('Add :item', ['item' => $name]) }}" class="grid size-9 place-items-center rounded-full bg-brand-600 text-lg leading-none font-bold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-navy-200">+</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <div class="border-t border-navy-100 bg-white p-2.5">
                    <p data-demo-toast hidden role="status" class="mb-2 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-center text-[11px] font-bold text-emerald-800"></p>
                    <p data-demo-cart class="mb-2 text-center text-xs font-bold text-navy-500">{{ __('Tap + to add items') }}</p>
                    <button type="button" data-demo-send disabled class="btn-primary min-h-11 w-full text-sm disabled:cursor-not-allowed disabled:opacity-50">{{ __('Send order') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- orders reaching the dashboard --}}
    <div class="min-w-0 rounded-2xl border border-navy-100 bg-white p-4 lg:col-start-1 lg:row-start-2 lg:self-start">
        <p class="flex items-center gap-2 text-sm font-black text-navy-900"><x-landing.icon name="bell" class="size-4 text-brand-600" />{{ __('Orders') }}</p>
        <div data-demo-orders aria-live="polite" class="mt-3 space-y-3">
            <p data-demo-empty class="rounded-xl border border-dashed border-navy-200 px-3 py-4 text-center text-sm text-navy-500">{{ __('No orders yet. Add something on the phone and send it.') }}</p>
        </div>
    </div>
</div>
<p class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-navy-500">
    <span class="inline-flex items-center gap-1.5"><x-landing.icon name="shield" class="size-4" />{{ __('A demo running in your browser. Nothing is saved or sent.') }}</span>
    <button type="button" data-demo-reset class="min-h-11 font-bold text-brand-700 underline-offset-4 hover:underline">{{ __('Start the demo again') }}</button>
</p>

<script>
/* The live demo on the home page: plain DOM, no requests. */
(function () {
    'use strict';
    var root = document.querySelector('[data-live-demo]');
    if (!root) return;
    var L = JSON.parse(root.getAttribute('data-l10n') || '{}');
    var items = {}, cart = {};
    var sendBtn = root.querySelector('[data-demo-send]');
    var cartLine = root.querySelector('[data-demo-cart]');
    var cartEmpty = cartLine.textContent;
    var toast = root.querySelector('[data-demo-toast]');
    var orders = root.querySelector('[data-demo-orders]');
    var empty = root.querySelector('[data-demo-empty]');
    var toastTimer = null;
    var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };

    root.querySelectorAll('[data-demo-item]').forEach(function (el) {
        var key = el.getAttribute('data-demo-item');
        items[key] = { el: el, name: el.getAttribute('data-name'), price: parseFloat(el.getAttribute('data-price')), start: parseFloat(el.getAttribute('data-price')), soldOut: false };
    });

    function flash(el, cls) {
        el.classList.remove(cls);
        void el.offsetWidth;
        el.classList.add(cls);
    }

    function renderItem(key) {
        var it = items[key], qty = cart[key] || 0;
        it.el.querySelector('[data-demo-price]').textContent = fmt(it.price);
        it.el.toggleAttribute('data-soldout', it.soldOut);
        it.el.querySelector('[data-demo-badge]').hidden = !it.soldOut;
        it.el.querySelector('[data-demo-plus]').disabled = it.soldOut;
        it.el.querySelector('[data-demo-minus]').hidden = qty === 0;
        var q = it.el.querySelector('[data-demo-qty]');
        q.hidden = qty === 0;
        q.textContent = qty;
    }

    function renderCart() {
        var count = 0, total = 0;
        Object.keys(cart).forEach(function (k) { count += cart[k]; total += cart[k] * items[k].price; });
        sendBtn.disabled = count === 0;
        cartLine.textContent = count === 0 ? cartEmpty : (count === 1 ? L.oneItem : L.items.replace(':n', count)) + ' · ' + fmt(total) + ' ' + L.egp;
    }

    // dashboard: edit a price
    root.querySelectorAll('[data-demo-edit]').forEach(function (input) {
        var key = input.getAttribute('data-demo-edit');
        input.addEventListener('input', function () {
            var n = parseFloat(input.value);
            if (isNaN(n) || n < 1 || n > 999 || n === items[key].price) return;
            items[key].price = n;
            renderItem(key);
            renderCart();
            flash(items[key].el.querySelector('[data-demo-price]'), 'dm-flash');
        });
        input.addEventListener('change', function () { input.value = items[key].price; });
    });

    // dashboard: sold out
    root.querySelectorAll('[data-demo-soldout]').forEach(function (btn) {
        var key = btn.getAttribute('data-demo-soldout');
        btn.addEventListener('click', function () {
            var it = items[key];
            it.soldOut = !it.soldOut;
            btn.setAttribute('aria-pressed', it.soldOut ? 'true' : 'false');
            if (it.soldOut) delete cart[key];
            renderItem(key);
            renderCart();
        });
    });

    // phone: + and −
    Object.keys(items).forEach(function (key) {
        var el = items[key].el;
        el.querySelector('[data-demo-plus]').addEventListener('click', function () {
            if (items[key].soldOut) return;
            cart[key] = Math.min((cart[key] || 0) + 1, 9);
            renderItem(key);
            renderCart();
        });
        el.querySelector('[data-demo-minus]').addEventListener('click', function () {
            cart[key] = (cart[key] || 0) - 1;
            if (cart[key] <= 0) delete cart[key];
            renderItem(key);
            renderCart();
            if (!cart[key]) el.querySelector('[data-demo-plus]').focus();
        });
    });

    function line(text, cls) {
        var s = document.createElement(cls ? 'span' : 'div');
        if (cls) s.className = cls;
        s.textContent = text;
        return s;
    }

    // phone: send → a new order card on the dashboard
    sendBtn.addEventListener('click', function () {
        var keys = Object.keys(cart);
        if (!keys.length) return;
        var total = 0;
        var card = document.createElement('div');
        card.setAttribute('data-demo-order', '');
        card.className = 'dm-pulse rounded-xl border border-brand-200 bg-brand-50 p-3';
        var head = document.createElement('div');
        head.className = 'flex items-center justify-between gap-2';
        head.appendChild(line(L.newOrder, 'font-black text-navy-900'));
        head.appendChild(line(L.justNow, 'text-xs font-bold text-navy-500'));
        card.appendChild(head);
        var list = document.createElement('ul');
        list.className = 'mt-2 space-y-0.5 text-sm text-navy-700';
        keys.forEach(function (k) {
            var li = document.createElement('li');
            li.className = 'flex justify-between gap-2';
            li.appendChild(line(cart[k] + '× ' + items[k].name, ''));
            li.appendChild(line(fmt(cart[k] * items[k].price), 'tabular-nums'));
            total += cart[k] * items[k].price;
            list.appendChild(li);
        });
        card.appendChild(list);
        var foot = document.createElement('div');
        foot.className = 'mt-2 flex items-center justify-between gap-2 border-t border-brand-200 pt-2';
        foot.appendChild(line(L.total + ': ' + fmt(total) + ' ' + L.egp, 'text-sm font-black'));
        var ready = document.createElement('button');
        ready.type = 'button';
        ready.className = 'min-h-10 rounded-full bg-navy-900 px-3 text-xs font-bold text-white hover:bg-navy-800';
        ready.textContent = L.markReady;
        ready.addEventListener('click', function () {
            card.className = 'rounded-xl border border-emerald-200 bg-emerald-50 p-3';
            ready.replaceWith(line(L.ready, 'rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold text-white'));
        });
        foot.appendChild(ready);
        card.appendChild(foot);

        empty.hidden = true;
        orders.insertBefore(card, orders.firstChild);
        var cards = orders.querySelectorAll('[data-demo-order]');
        for (var i = 3; i < cards.length; i++) cards[i].remove();

        cart = {};
        keys.forEach(renderItem);
        renderCart();
        toast.textContent = L.sent;
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.hidden = true; }, 4000);
    });

    // start again
    document.querySelector('[data-demo-reset]').addEventListener('click', function () {
        cart = {};
        Object.keys(items).forEach(function (k) {
            items[k].price = items[k].start;
            items[k].soldOut = false;
            root.querySelector('[data-demo-edit="' + k + '"]').value = items[k].start;
            root.querySelector('[data-demo-soldout="' + k + '"]').setAttribute('aria-pressed', 'false');
            renderItem(k);
        });
        orders.querySelectorAll('[data-demo-order]').forEach(function (c) { c.remove(); });
        empty.hidden = false;
        toast.hidden = true;
        renderCart();
    });
})();
</script>
