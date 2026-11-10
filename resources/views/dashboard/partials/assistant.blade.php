{{--
    «اسأل هوبا»: Hoba, the dashboard assistant (App\Services\DashboardAssistant), for plans with dashboard_assistant while a model
    key is set. A floating button opens the chat; each answer may carry buttons to the pages it talks about, and a card for
    a change Hoba offers (App\Services\DashboardActions) that only happens on «confirm». Not on the working screens
    (cashier, kitchen, call center, pager, deliveries), where the corner belongs to the work.
--}}
@if (auth()->check() && \App\Services\Assistant::enabled() && \App\Services\Entitlements::current()->allows(\App\Services\DashboardAssistant::FEATURE)
    && ! request()->routeIs('dash.pos*', 'dash.kitchen*', 'dash.callcenter*', 'dash.pager*', 'dash.driver*'))
    @php
        $dashAsstL10n = [
            'welcome' => __('Hi :name, I’m Hoba, the EgHub assistant. Ask me how to do anything in your dashboard and I’ll show you the steps and the page.', ['name' => \Illuminate\Support\Str::before(auth()->user()->name, ' ')]),
            'typing' => __('Typing…'),
            'failed' => __('Sorry, something went wrong and I couldn’t answer. You can message EgHub support on WhatsApp.'),
            'tooMany' => __('Your place has used today’s questions. EgHub support on WhatsApp can help in the meantime.'),
            'handoffPrompt' => __('EgHub support can help you with this on WhatsApp.'),
            'handoffButton' => __('Message EgHub support'),
            'confirm' => __('Confirm'),
            'cancel' => __('Cancel'),
            'cancelled' => __('Cancelled, nothing changed.'),
            'handoffText' => __("Hi, I'm from :place and I was asking Hoba, the dashboard assistant:\n:q", ['place' => tenant()->name]),
        ];
        $dashAsstChips = [__('How do I stop taking orders?'), __('How do I add an item with sizes?'), __('How do I print the menu?'), __('How do I add a staff member?')];
    @endphp
    <button type="button" data-dash-asst-open class="fixed bottom-4 end-4 z-40 flex min-h-11 items-center gap-2 rounded-full bg-brand-600 px-4 text-sm font-bold text-white shadow-lg ring-1 ring-white/20 hover:bg-brand-700 print:hidden">
        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        {{ __('Ask Hoba') }}
    </button>
    <div id="dash-asst" hidden role="dialog" aria-modal="true" aria-labelledby="dash-asst-title" data-endpoint="{{ route('dash.assistant') }}" data-run="{{ route('dash.assistant.run') }}"
         data-l10n="{{ json_encode($dashAsstL10n, JSON_UNESCAPED_UNICODE) }}" data-key="eghub-dash-assistant-{{ auth()->id() }}"
         class="fixed inset-0 z-[60] flex flex-col overflow-hidden bg-white shadow-2xl sm:inset-auto sm:bottom-6 sm:end-6 sm:h-[min(620px,calc(100dvh-48px))] sm:w-[400px] sm:rounded-3xl sm:border sm:border-navy-100 print:hidden">
        <header class="flex items-center gap-3 bg-navy-900 px-4 py-3 text-white">
            <img src="{{ versioned_asset('icons/eghub-192.png') }}" alt="" width="40" height="40" class="size-10 shrink-0 rounded-xl bg-white p-0.5">
            <div class="min-w-0 flex-1">
                <p id="dash-asst-title" class="truncate text-base font-extrabold">{{ __('Hoba') }}</p>
                <p class="text-xs text-white/70">{{ __('Explains your dashboard · changes only what you confirm') }}</p>
            </div>
            <button type="button" data-dash-asst-reset class="grid size-9 place-items-center rounded-lg bg-white/10 text-lg hover:bg-white/20" title="{{ __('New chat') }}" aria-label="{{ __('New chat') }}">↺</button>
            <button type="button" data-dash-asst-close class="grid size-9 place-items-center rounded-lg bg-white/10 text-xl leading-none hover:bg-white/20" aria-label="{{ __('Close') }}">×</button>
        </header>
        <div data-dash-asst-log class="flex flex-1 flex-col gap-2.5 overflow-y-auto overscroll-contain bg-navy-50 p-4 text-[15px] leading-relaxed" aria-live="polite"></div>
        <div data-dash-asst-chips class="flex flex-wrap gap-1.5 bg-navy-50 px-4 pt-1">
            @foreach ($dashAsstChips as $chip)<button type="button" class="rounded-full border border-navy-200 bg-white px-3 py-1.5 text-[13px] font-bold text-navy-800 hover:border-brand-500 hover:text-brand-700">{{ $chip }}</button>@endforeach
        </div>
        <form data-dash-asst-form class="flex gap-2 border-t border-navy-100 bg-white px-3 py-2.5">
            <label class="sr-only" for="dash-asst-input">{{ __('Type your question') }}</label>
            <textarea id="dash-asst-input" rows="1" maxlength="600" placeholder="{{ __('Type your question…') }}" class="field max-h-28 min-h-11 flex-1 resize-none py-2.5"></textarea>
            <button type="submit" class="btn-primary min-h-11 px-4">{{ __('Send') }}</button>
        </form>
    </div>
    <script>
    /* «اسألني»: the conversation lives in this tab (sessionStorage, per user); every turn goes to POST /assistant. */
    (function () {
        'use strict';
        var panel = document.getElementById('dash-asst');
        if (!panel || !window.fetch) return;
        var L = JSON.parse(panel.getAttribute('data-l10n') || '{}');
        var KEY = panel.getAttribute('data-key');
        var log = panel.querySelector('[data-dash-asst-log]');
        var form = panel.querySelector('[data-dash-asst-form]');
        var input = form.querySelector('textarea');
        var send = form.querySelector('button[type=submit]');
        var chips = panel.querySelector('[data-dash-asst-chips]');
        var csrf = document.querySelector('meta[name=csrf-token]');
        var busy = false, lastFocus = null, wa = '';
        var BOT = 'max-w-[88%] self-start rounded-2xl rounded-ss-md border border-navy-100 bg-white px-3.5 py-2.5 text-navy-900';
        var ME = 'max-w-[88%] self-end rounded-2xl rounded-se-md bg-brand-600 px-3.5 py-2.5 text-white';
        var state = load() || fresh();
        function fresh() { return { id: Math.random().toString(36).slice(2) + Date.now().toString(36), messages: [] }; }
        function load() { try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
        function save() { try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} }
        function lastQuestion() {
            for (var i = state.messages.length - 1; i >= 0; i--) if (state.messages[i].role === 'user') return state.messages[i].content;
            return '';
        }
        function bubble(role, text, extra) {
            var div = document.createElement('div');
            div.className = (role === 'user' ? ME : BOT) + (extra ? ' ' + extra : '');
            div.dir = 'auto';
            div.style.whiteSpace = 'pre-line';
            div.textContent = text;
            log.appendChild(div);
            log.scrollTop = log.scrollHeight;
            return div;
        }
        // the pages the answer talks about: buttons, only from the server's list of pages this user may open
        function linkButtons(links) {
            if (!links || !links.length) return;
            var row = document.createElement('div');
            row.className = 'flex flex-wrap gap-2 self-start';
            links.forEach(function (l) {
                var a = document.createElement('a');
                a.href = l.url;
                a.className = 'inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-brand-200 bg-white px-3 text-sm font-bold text-brand-700 hover:bg-brand-50';
                a.textContent = '↗ ' + l.label;
                a.setAttribute('data-dash-asst-link', '');
                row.appendChild(a);
            });
            log.appendChild(row);
            log.scrollTop = log.scrollHeight;
        }
        // a change Hoba offers: the server's own words, done only on «confirm»; the outcome stays in the chat
        function actionCard(m) {
            if (!m.action) return;
            var card = document.createElement('div');
            card.className = 'self-stretch rounded-2xl border border-brand-200 bg-white p-3 text-sm';
            card.setAttribute('data-dash-asst-action', '');
            var p = document.createElement('p');
            p.className = 'mb-2 font-bold text-navy-900';
            p.dir = 'auto';
            p.textContent = m.action.summary;
            card.appendChild(p);
            if (m.action.result) {
                var r = document.createElement('p');
                r.className = m.action.ok ? 'font-bold text-emerald-700' : 'font-bold text-red-700';
                r.textContent = (m.action.ok ? '✓ ' : '') + m.action.result;
                card.appendChild(r);
            } else {
                var row = document.createElement('div');
                row.className = 'flex gap-2';
                var yes = document.createElement('button');
                yes.type = 'button'; yes.className = 'btn-primary min-h-9 px-4'; yes.textContent = L.confirm;
                yes.setAttribute('data-dash-asst-confirm', '');
                var no = document.createElement('button');
                no.type = 'button'; no.className = 'btn-ghost min-h-9 px-4'; no.textContent = L.cancel;
                yes.addEventListener('click', function () {
                    yes.disabled = no.disabled = true;
                    fetch(panel.getAttribute('data-run'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' },
                        body: JSON.stringify({ token: m.action.token })
                    }).then(function (r) { return r.json().catch(function () { return {}; }); })
                      .then(function (d) { m.action.ok = !!d.ok; m.action.result = d.message || L.failed; })
                      .catch(function () { m.action.ok = false; m.action.result = L.failed; })
                      .then(function () { delete m.action.token; save(); redraw(); });
                });
                no.addEventListener('click', function () { m.action.ok = false; m.action.result = L.cancelled; delete m.action.token; save(); redraw(); });
                row.appendChild(yes); row.appendChild(no);
                card.appendChild(row);
            }
            log.appendChild(card);
            log.scrollTop = log.scrollHeight;
        }
        function handoffCard() {
            if (!wa) return;
            var div = document.createElement('div');
            div.className = 'self-stretch rounded-2xl border border-dashed border-navy-200 bg-white p-3 text-center text-sm';
            var p = document.createElement('p');
            p.className = 'mb-2 text-navy-800';
            p.textContent = L.handoffPrompt;
            var a = document.createElement('a');
            a.className = 'inline-flex min-h-11 items-center justify-center rounded-xl bg-[#25D366] px-4 font-bold text-white';
            a.target = '_blank'; a.rel = 'noopener';
            a.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent(L.handoffText.replace(':q', lastQuestion()));
            a.textContent = L.handoffButton;
            div.appendChild(p); div.appendChild(a);
            log.appendChild(div);
            log.scrollTop = log.scrollHeight;
        }
        function redraw() {
            log.innerHTML = '';
            bubble('assistant', L.welcome);
            state.messages.forEach(function (m) {
                bubble(m.role, m.content);
                linkButtons(m.links);
                actionCard(m);
                if (m.handoff) handoffCard();
            });
            chips.hidden = state.messages.length > 0;
        }
        function ask(text) {
            text = (text || '').trim().slice(0, 600);
            if (!text || busy) return;
            busy = true; send.disabled = true; chips.hidden = true;
            state.messages.push({ role: 'user', content: text });
            save();
            bubble('user', text);
            input.value = '';
            grow();
            var typing = bubble('assistant', L.typing, 'italic text-navy-500');
            fetch(panel.getAttribute('data-endpoint'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : '' },
                body: JSON.stringify({ conversation: state.id, messages: state.messages.map(function (m) { return { role: m.role, content: m.content }; }) })
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, d: d }; });
            }).then(function (res) {
                typing.remove();
                wa = res.d.whatsapp || wa;
                if (res.status === 200 && res.d.reply) {
                    var said = { role: 'assistant', content: res.d.reply, links: res.d.links || [], action: res.d.action || null, handoff: !!res.d.handoff };
                    state.messages.push(said);
                    save();
                    bubble('assistant', res.d.reply);
                    linkButtons(res.d.links);
                    actionCard(said);
                    if (res.d.handoff) handoffCard();
                } else {
                    state.messages.pop(); save();
                    bubble('assistant', res.status === 429 ? L.tooMany : L.failed, 'border-red-300');
                    handoffCard();
                }
            }).catch(function () {
                typing.remove(); state.messages.pop(); save();
                bubble('assistant', L.failed, 'border-red-300');
                handoffCard();
            }).then(function () { busy = false; send.disabled = false; input.focus(); });
        }
        function grow() {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 112) + 'px';
        }
        function open() {
            lastFocus = document.activeElement;
            panel.hidden = false;
            redraw();
            setTimeout(function () { input.focus(); }, 50);
        }
        function close() {
            panel.hidden = true;
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }
        input.addEventListener('input', grow);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(input.value); }
        });
        form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
        chips.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) ask(b.textContent); });
        document.querySelector('[data-dash-asst-open]').addEventListener('click', open);
        panel.querySelector('[data-dash-asst-close]').addEventListener('click', close);
        panel.querySelector('[data-dash-asst-reset]').addEventListener('click', function () { state = fresh(); save(); redraw(); input.focus(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });
    })();
    </script>
@endif
